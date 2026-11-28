<?php

namespace App\Support\Monitoring;

use App\Models\Monitor;
use App\Support\SafeHttp\BatchCheckOutcome;
use App\Support\SafeHttp\BatchCheckTarget;
use App\Support\SafeHttp\SafeHttpClient;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Coordinates locked, bounded, concurrency-limited execution and atomic batch persistence of due checks.
 */
final class MonitorCheckRunner
{
    public const EXECUTION_LOCK_KEY = 'sitepulse:check-monitors:execution';

    /** @var Closure(): int */
    private readonly Closure $monotonicClockNs;

    /**
     * @param  (Closure(): int)|null  $monotonicClockNs
     */
    public function __construct(
        private readonly SafeHttpClient $safeHttpClient,
        private readonly CheckBatchPersister $persister = new CheckBatchPersister,
        ?Closure $monotonicClockNs = null,
    ) {
        $this->monotonicClockNs = $monotonicClockNs ?? static fn (): int => hrtime(true);
    }

    /**
     * Run one bounded monitor checking cycle under an atomic overlap lock.
     */
    public function run(
        ?int $concurrency = null,
        ?int $limit = null,
        int|float|null $softDeadlineSeconds = null,
        int|float|null $hardDeadlineSeconds = null,
    ): MonitorRunReport {
        $startedAtNs = $this->nowNs();

        $configuredConcurrency = $concurrency ?? (int) config('sitepulse.max_concurrency', 5);
        $ceiling = min(10, max(1, (int) config('sitepulse.max_concurrency_ceiling', 10)));
        $effectiveConcurrency = max(1, min($ceiling, $configuredConcurrency));

        $softSeconds = $softDeadlineSeconds ?? (int) config('sitepulse.soft_deadline_seconds', 45);
        $hardSeconds = $hardDeadlineSeconds ?? (int) config('sitepulse.hard_deadline_seconds', 50);

        if ($softSeconds <= 0 || $hardSeconds < $softSeconds) {
            throw new InvalidArgumentException('Monitoring run soft/hard deadlines must be positive and hard >= soft.');
        }

        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('Monitor check limit must be a positive integer.');
        }

        $softDeadlineNs = $startedAtNs + (int) round($softSeconds * 1_000_000_000);
        $hardDeadlineNs = $startedAtNs + (int) round($hardSeconds * 1_000_000_000);

        $batchPersistSize = max(5, min(10, (int) config('sitepulse.batch_persist_size', 10)));
        $lockTtlSeconds = max(60, (int) config('sitepulse.check_lock_ttl_seconds', 600));
        $lockStore = config('sitepulse.lock_store');
        $lock = Cache::store(is_string($lockStore) && $lockStore !== '' ? $lockStore : null)
            ->lock(self::EXECUTION_LOCK_KEY, $lockTtlSeconds);

        if (! $lock->get()) {
            $elapsedMs = $this->elapsedMs($startedAtNs);
            Log::info('SitePulse monitor check skipped because an execution lock is already active.', [
                'lock_acquired' => false,
                'elapsed_ms' => $elapsedMs,
            ]);

            return new MonitorRunReport(
                lockAcquired: false,
                dueCount: 0,
                startedCount: 0,
                completedCount: 0,
                succeededCount: 0,
                failedCount: 0,
                staleDiscardedCount: 0,
                softDeadlineSkippedCount: 0,
                hardDeadlineCancelledCount: 0,
                elapsedMs: $elapsedMs,
                softDeadlineReached: false,
                hardDeadlineReached: false,
            );
        }

        try {
            $dueQuery = Monitor::query()->dueForCheck(now()->utc());
            if ($limit !== null) {
                $dueQuery->limit($limit);
            }

            $dueMonitors = $dueQuery->get();
            $dueCount = $dueMonitors->count();

            if ($dueCount === 0) {
                $elapsedMs = $this->elapsedMs($startedAtNs);
                Log::info('SitePulse monitor check run completed with no due monitors.', [
                    'due_count' => 0,
                    'concurrency' => $effectiveConcurrency,
                    'elapsed_ms' => $elapsedMs,
                ]);

                return new MonitorRunReport(
                    lockAcquired: true,
                    dueCount: 0,
                    startedCount: 0,
                    completedCount: 0,
                    succeededCount: 0,
                    failedCount: 0,
                    staleDiscardedCount: 0,
                    softDeadlineSkippedCount: 0,
                    hardDeadlineCancelledCount: 0,
                    elapsedMs: $elapsedMs,
                    softDeadlineReached: false,
                    hardDeadlineReached: false,
                );
            }

            if ($this->nowNs() >= $softDeadlineNs) {
                $elapsedMs = $this->elapsedMs($startedAtNs);
                $hardReached = $this->nowNs() >= $hardDeadlineNs;

                Log::info('SitePulse monitor check skipped probe execution because whole-run soft deadline elapsed during selection.', [
                    'due_count' => $dueCount,
                    'started_count' => 0,
                    'completed_count' => 0,
                    'soft_deadline_skipped_count' => $dueCount,
                    'concurrency' => $effectiveConcurrency,
                    'soft_deadline_reached' => true,
                    'hard_deadline_reached' => $hardReached,
                    'elapsed_ms' => $elapsedMs,
                ]);

                return new MonitorRunReport(
                    lockAcquired: true,
                    dueCount: $dueCount,
                    startedCount: 0,
                    completedCount: 0,
                    succeededCount: 0,
                    failedCount: 0,
                    staleDiscardedCount: 0,
                    softDeadlineSkippedCount: $dueCount,
                    hardDeadlineCancelledCount: 0,
                    elapsedMs: $elapsedMs,
                    softDeadlineReached: true,
                    hardDeadlineReached: $hardReached,
                );
            }

            $targets = [];
            foreach ($dueMonitors as $monitor) {
                $snapshot = MonitorProbeSnapshot::fromMonitor($monitor);
                $targets[] = new BatchCheckTarget(
                    key: $snapshot->monitorId,
                    url: $snapshot->url,
                    timeoutSeconds: $snapshot->timeoutSeconds,
                    expectedStatusMin: $snapshot->expectedStatusCodeMin,
                    expectedStatusMax: $snapshot->expectedStatusCodeMax,
                    method: 'GET',
                    context: $snapshot,
                );
            }
            unset($dueMonitors);

            $buffer = [];
            $startedCount = 0;
            $completedCount = 0;
            $succeededCount = 0;
            $failedCount = 0;
            $staleDiscardedCount = 0;
            $softDeadlineSkippedCount = 0;
            $hardDeadlineCancelledCount = 0;

            $flushBuffer = function () use (
                &$buffer,
                &$completedCount,
                &$succeededCount,
                &$failedCount,
                &$staleDiscardedCount,
            ): void {
                if ($buffer === []) {
                    return;
                }

                $stats = $this->persister->persistBatch($buffer);
                $completedCount += $stats['persisted'];
                $succeededCount += $stats['succeeded'];
                $failedCount += $stats['failed'];
                $staleDiscardedCount += $stats['staleDiscarded'];
                $buffer = [];
            };

            $this->safeHttpClient->checkBatch(
                targets: $targets,
                maxConcurrency: $effectiveConcurrency,
                softDeadlineSeconds: $softSeconds,
                hardDeadlineSeconds: $hardSeconds,
                onOutcome: function (BatchCheckOutcome $outcome) use (
                    &$buffer,
                    &$startedCount,
                    &$softDeadlineSkippedCount,
                    &$hardDeadlineCancelledCount,
                    $batchPersistSize,
                    $hardDeadlineNs,
                    $flushBuffer,
                ): void {
                    if ($outcome->cancelled) {
                        if ($outcome->cancellationReason === 'soft_deadline') {
                            $softDeadlineSkippedCount++;
                        } else {
                            $startedCount++;
                            $hardDeadlineCancelledCount++;
                        }

                        return;
                    }

                    $startedCount++;
                    $snapshot = $outcome->target->context;

                    if (! $snapshot instanceof MonitorProbeSnapshot || $outcome->result === null) {
                        return;
                    }

                    $buffer[] = [
                        'snapshot' => $snapshot,
                        'result' => $outcome->result,
                        'checkedAt' => CarbonImmutable::instance(now('UTC')),
                    ];

                    if (count($buffer) >= $batchPersistSize && $this->nowNs() < $hardDeadlineNs) {
                        $flushBuffer();
                    }
                },
                softDeadlineNs: $softDeadlineNs,
                hardDeadlineNs: $hardDeadlineNs,
            );

            $flushBuffer();

            $elapsedMs = $this->elapsedMs($startedAtNs);
            $softDeadlineReached = $softDeadlineSkippedCount > 0 || $hardDeadlineCancelledCount > 0 || $this->nowNs() >= $softDeadlineNs;
            $hardDeadlineReached = $hardDeadlineCancelledCount > 0 || $this->nowNs() >= $hardDeadlineNs;

            Log::info('SitePulse monitor check run finished.', [
                'due_count' => $dueCount,
                'started_count' => $startedCount,
                'completed_count' => $completedCount,
                'succeeded_count' => $succeededCount,
                'failed_count' => $failedCount,
                'stale_discarded_count' => $staleDiscardedCount,
                'soft_deadline_skipped_count' => $softDeadlineSkippedCount,
                'hard_deadline_cancelled_count' => $hardDeadlineCancelledCount,
                'concurrency' => $effectiveConcurrency,
                'soft_deadline_reached' => $softDeadlineReached,
                'hard_deadline_reached' => $hardDeadlineReached,
                'elapsed_ms' => $elapsedMs,
            ]);

            return new MonitorRunReport(
                lockAcquired: true,
                dueCount: $dueCount,
                startedCount: $startedCount,
                completedCount: $completedCount,
                succeededCount: $succeededCount,
                failedCount: $failedCount,
                staleDiscardedCount: $staleDiscardedCount,
                softDeadlineSkippedCount: $softDeadlineSkippedCount,
                hardDeadlineCancelledCount: $hardDeadlineCancelledCount,
                elapsedMs: $elapsedMs,
                softDeadlineReached: $softDeadlineReached,
                hardDeadlineReached: $hardDeadlineReached,
            );
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Best-effort unlock; atomic TTL guarantees automatic expiration if connection dropped.
            }
        }
    }

    private function nowNs(): int
    {
        return ($this->monotonicClockNs)();
    }

    private function elapsedMs(int $startedAtNs): int
    {
        return max(0, (int) round(($this->nowNs() - $startedAtNs) / 1_000_000));
    }
}
