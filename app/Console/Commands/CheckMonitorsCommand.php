<?php

namespace App\Console\Commands;

use App\Support\Monitoring\MonitorCheckRunner;
use Illuminate\Console\Command;

/**
 * Executes a bounded scheduled pass over due SitePulse HTTP/HTTPS monitors.
 */
class CheckMonitorsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sitepulse:check-monitors
        {--concurrency= : Override bounded HTTP concurrency (1-10)}
        {--limit= : Restrict number of due monitors processed in this pass}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute bounded scheduled HTTP/HTTPS monitor checks safely.';

    public function handle(MonitorCheckRunner $runner): int
    {
        $concurrencyOption = $this->option('concurrency');
        $limitOption = $this->option('limit');

        $concurrency = null;
        if ($concurrencyOption !== null) {
            if (! $this->isPositiveIntegerString($concurrencyOption)) {
                $this->error('Option --concurrency must be a positive integer between 1 and 10.');

                return self::INVALID;
            }

            $requestedConcurrency = (int) $concurrencyOption;
            $ceiling = min(10, max(1, (int) config('sitepulse.max_concurrency_ceiling', 10)));
            if ($requestedConcurrency > $ceiling) {
                $this->warn("Requested concurrency ({$requestedConcurrency}) exceeds safety ceiling ({$ceiling}); clamping to {$ceiling}.");
            }

            $concurrency = max(1, min($ceiling, $requestedConcurrency));
        }

        $limit = null;
        if ($limitOption !== null) {
            if (! $this->isPositiveIntegerString($limitOption)) {
                $this->error('Option --limit must be a positive integer.');

                return self::INVALID;
            }

            $limit = (int) $limitOption;
        }

        $report = $runner->run(
            concurrency: $concurrency,
            limit: $limit,
        );

        if (! $report->lockAcquired) {
            $this->warn('Skipped monitor check pass: execution overlap lock is currently held.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Monitor checks finished: due=%d started=%d completed=%d succeeded=%d failed=%d stale=%d soft_skipped=%d hard_cancelled=%d incidents_opened=%d incidents_resolved=%d alerts_queued=%d elapsed_ms=%d',
            $report->dueCount,
            $report->startedCount,
            $report->completedCount,
            $report->succeededCount,
            $report->failedCount,
            $report->staleDiscardedCount,
            $report->softDeadlineSkippedCount,
            $report->hardDeadlineCancelledCount,
            $report->incidentsOpenedCount,
            $report->incidentsResolvedCount,
            $report->notificationsQueuedCount,
            $report->elapsedMs,
        ));

        return self::SUCCESS;
    }

    private function isPositiveIntegerString(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[1-9][0-9]*$/', trim($value)) === 1;
    }
}
