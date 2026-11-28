<?php

namespace App\Support\Monitoring;

use App\Models\CheckResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deletes stale check_results rows in bounded portable chunks while preserving incidents and notifications.
 */
final class CheckHistoryPruner
{
    public const EXECUTION_LOCK_KEY = 'sitepulse:prune-checks:execution';

    public const MAX_CHUNK_SIZE = 5000;

    /**
     * Prune check_results rows strictly older than the configured retention window using bounded transactions.
     *
     * @return array{lockAcquired: bool, retentionDays: int, cutoff: CarbonImmutable, batchesProcessed: int, deletedCount: int}
     */
    public function prune(
        ?int $retentionDays = null,
        ?int $chunkSize = null,
        ?CarbonImmutable $asOf = null,
    ): array {
        $effectiveDays = $retentionDays ?? (int) config('sitepulse.history_retention_days', 30);
        $effectiveDays = max(1, $effectiveDays);

        $effectiveChunk = $chunkSize ?? (int) config('sitepulse.prune_batch_size', 1000);
        $effectiveChunk = max(1, min(self::MAX_CHUNK_SIZE, $effectiveChunk));

        $referenceTime = ($asOf ?? CarbonImmutable::instance(now('UTC')))->utc();
        $cutoff = $referenceTime->subDays($effectiveDays);
        $cutoffFormatted = $cutoff->format('Y-m-d H:i:s');

        $lockStore = config('sitepulse.lock_store');
        $lock = Cache::store(is_string($lockStore) && $lockStore !== '' ? $lockStore : null)
            ->lock(self::EXECUTION_LOCK_KEY, 1800);

        if (! $lock->get()) {
            Log::info('SitePulse history pruning skipped because a pruning lock is already active.', [
                'lock_acquired' => false,
                'retention_days' => $effectiveDays,
                'cutoff_utc' => $cutoffFormatted,
            ]);

            return [
                'lockAcquired' => false,
                'retentionDays' => $effectiveDays,
                'cutoff' => $cutoff,
                'batchesProcessed' => 0,
                'deletedCount' => 0,
            ];
        }

        $deletedCount = 0;
        $batchesProcessed = 0;

        try {
            do {
                // Portable PK chunk selection works identically across SQLite and MySQL without DELETE ... LIMIT.
                $ids = CheckResult::query()
                    ->where('checked_at', '<', $cutoffFormatted)
                    ->orderBy('id')
                    ->limit($effectiveChunk)
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    break;
                }

                $deleted = DB::transaction(static fn (): int => CheckResult::query()
                    ->whereIn('id', $ids)
                    ->delete());

                $deletedCount += $deleted;
                $batchesProcessed++;
            } while ($deleted > 0);

            Log::info('SitePulse history pruning completed.', [
                'retention_days' => $effectiveDays,
                'cutoff_utc' => $cutoffFormatted,
                'chunk_size' => $effectiveChunk,
                'batches_processed' => $batchesProcessed,
                'deleted_count' => $deletedCount,
            ]);

            return [
                'lockAcquired' => true,
                'retentionDays' => $effectiveDays,
                'cutoff' => $cutoff,
                'batchesProcessed' => $batchesProcessed,
                'deletedCount' => $deletedCount,
            ];
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Best-effort unlock; TTL ensures stale lock recovery after crashes.
            }
        }
    }
}
