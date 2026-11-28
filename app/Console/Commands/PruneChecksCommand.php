<?php

namespace App\Console\Commands;

use App\Support\Monitoring\CheckHistoryPruner;
use Illuminate\Console\Command;

/**
 * Prunes raw check_results older than the configured retention window while keeping incident audit history.
 */
class PruneChecksCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sitepulse:prune-checks
        {--days= : Override check-history retention window in days (>= 1)}
        {--chunk= : Override chunk batch size for portable deletion (1-5000)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete aged check_results records in bounded chunks while preserving incident history.';

    public function handle(CheckHistoryPruner $pruner): int
    {
        $daysOption = $this->option('days');
        $chunkOption = $this->option('chunk');

        $days = null;
        if ($daysOption !== null) {
            if (! $this->isPositiveIntegerString($daysOption)) {
                $this->error('Option --days must be a positive integer.');

                return self::INVALID;
            }

            $days = (int) $daysOption;
        }

        $chunk = null;
        if ($chunkOption !== null) {
            if (! $this->isPositiveIntegerString($chunkOption)) {
                $this->error('Option --chunk must be a positive integer between 1 and 5000.');

                return self::INVALID;
            }

            $requestedChunk = (int) $chunkOption;
            if ($requestedChunk > CheckHistoryPruner::MAX_CHUNK_SIZE) {
                $this->warn(sprintf(
                    'Requested chunk size (%d) exceeds safety ceiling (%d); clamping to %d.',
                    $requestedChunk,
                    CheckHistoryPruner::MAX_CHUNK_SIZE,
                    CheckHistoryPruner::MAX_CHUNK_SIZE,
                ));
            }

            $chunk = min(CheckHistoryPruner::MAX_CHUNK_SIZE, $requestedChunk);
        }

        $report = $pruner->prune(
            retentionDays: $days,
            chunkSize: $chunk,
        );

        if (! $report['lockAcquired']) {
            $this->warn('Skipped history pruning pass: pruning overlap lock is currently held.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Pruned check history: deleted=%d batches=%d retention_days=%d cutoff_utc=%s',
            $report['deletedCount'],
            $report['batchesProcessed'],
            $report['retentionDays'],
            $report['cutoff']->format('Y-m-d H:i:s'),
        ));

        return self::SUCCESS;
    }

    private function isPositiveIntegerString(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 1;
        }

        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            return false;
        }

        return (int) $value >= 1;
    }
}
