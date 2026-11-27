<?php

namespace App\Support\Monitoring;

use App\Enums\CheckResultStatus;
use App\Models\CheckResult;
use App\Models\Monitor;
use App\Support\SafeHttp\SafeHttpCheckResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Atomically persists bounded batches of completed monitor checks with stale-state race protection.
 */
final class CheckBatchPersister
{
    public const MAX_ERROR_MESSAGE_LENGTH = 255;

    public function __construct(
        private readonly MonitorStateTransition $stateTransition = new MonitorStateTransition,
    ) {}

    /**
     * Persist completed check outcomes in one atomic transaction using deterministic row locking order.
     *
     * @param  array<int, array{snapshot: MonitorProbeSnapshot, result: SafeHttpCheckResult, checkedAt: CarbonImmutable}>  $batch
     * @return array{persisted: int, staleDiscarded: int, succeeded: int, failed: int}
     */
    public function persistBatch(array $batch): array
    {
        if ($batch === []) {
            return [
                'persisted' => 0,
                'staleDiscarded' => 0,
                'succeeded' => 0,
                'failed' => 0,
            ];
        }

        $monitorIds = array_values(array_unique(array_map(
            static fn (array $item): int => $item['snapshot']->monitorId,
            $batch,
        )));
        sort($monitorIds);

        return DB::transaction(function () use ($batch, $monitorIds): array {
            $lockedMonitors = Monitor::query()
                ->whereIn('id', $monitorIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $checkRows = [];
            $persisted = 0;
            $staleDiscarded = 0;
            $succeeded = 0;
            $failed = 0;

            foreach ($batch as $entry) {
                /** @var MonitorProbeSnapshot $snapshot */
                $snapshot = $entry['snapshot'];
                /** @var SafeHttpCheckResult $result */
                $result = $entry['result'];
                /** @var CarbonImmutable $checkedAt */
                $checkedAt = $entry['checkedAt']->utc();

                /** @var Monitor|null $monitor */
                $monitor = $lockedMonitors->get($snapshot->monitorId);

                if ($monitor === null || ! $snapshot->matchesLockedMonitor($monitor)) {
                    $staleDiscarded++;

                    continue;
                }

                $transition = $this->stateTransition->apply(
                    currentStatus: $monitor->status,
                    currentFailures: (int) $monitor->consecutive_failures,
                    currentSuccesses: (int) $monitor->consecutive_successes,
                    checkSucceeded: $result->succeeded,
                );

                $monitor->status = $transition->nextStatus;
                $monitor->consecutive_failures = $transition->consecutiveFailures;
                $monitor->consecutive_successes = $transition->consecutiveSuccesses;
                $monitor->last_checked_at = $checkedAt;
                $monitor->next_check_at = $checkedAt->addMinutes((int) $monitor->check_interval_minutes);
                $monitor->last_status_code = $result->statusCode;
                $monitor->last_latency_ms = max(0, $result->latencyMs);

                if ($snapshot->scheme === 'https' && $result->tlsExpiresAt !== null) {
                    $monitor->tls_expires_at = CarbonImmutable::instance($result->tlsExpiresAt)->utc();
                    $monitor->tls_last_checked_at = $checkedAt;
                } elseif ($snapshot->scheme === 'http') {
                    $monitor->tls_expires_at = null;
                    $monitor->tls_last_checked_at = null;
                }

                $monitor->save();

                // Update locked collection snapshot if the same monitor appeared multiple times defensively.
                $snapshotCheckStatus = $result->succeeded ? CheckResultStatus::SUCCESS : CheckResultStatus::FAILURE;

                $checkRows[] = [
                    'monitor_id' => $monitor->getKey(),
                    'status' => $snapshotCheckStatus->value,
                    'status_code' => $result->statusCode,
                    'latency_ms' => max(0, $result->latencyMs),
                    'failure_reason' => $result->failureReason?->value,
                    'error_message' => $result->succeeded
                        ? null
                        : self::sanitizeErrorMessage($result->errorMessage),
                    'redirects_followed' => max(0, $result->redirectsFollowed),
                    'body_truncated' => $result->bodyTruncated,
                    'checked_at' => $checkedAt->format('Y-m-d H:i:s'),
                ];

                $persisted++;
                if ($result->succeeded) {
                    $succeeded++;
                } else {
                    $failed++;
                }
            }

            if ($checkRows !== []) {
                CheckResult::query()->insert($checkRows);
            }

            return [
                'persisted' => $persisted,
                'staleDiscarded' => $staleDiscarded,
                'succeeded' => $succeeded,
                'failed' => $failed,
            ];
        });
    }

    /**
     * Enforce sanitized, control-character-free error messages capped at 255 characters.
     */
    public static function sanitizeErrorMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $cleaned = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? '';
        $cleaned = preg_replace('#(https?://)[^/@\s]+@#i', '$1[REDACTED]@', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\b(authorization|cookie|set-cookie|x-api-key)\s*:\s*\S+/i', '$1: [REDACTED]', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s+/', ' ', trim($cleaned)) ?? '';

        if ($cleaned === '') {
            return 'Check failed.';
        }

        if (mb_strlen($cleaned, 'UTF-8') > self::MAX_ERROR_MESSAGE_LENGTH) {
            return mb_substr($cleaned, 0, self::MAX_ERROR_MESSAGE_LENGTH - 3, 'UTF-8').'...';
        }

        return $cleaned;
    }
}
