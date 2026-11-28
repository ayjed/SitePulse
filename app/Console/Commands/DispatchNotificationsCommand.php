<?php

namespace App\Console\Commands;

use App\Support\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * Dispatches bounded pending outage, recovery, and TLS alert notifications from the durable DB outbox.
 */
class DispatchNotificationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sitepulse:dispatch-notifications
        {--limit= : Override maximum number of notifications processed per run (1-100)}
        {--deadline= : Override whole-run monotonic dispatch deadline in seconds (1-55)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch bounded pending SitePulse incident alerts from the durable outbox cleanly outside monitor checks.';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $limitOption = $this->option('limit');
        $deadlineOption = $this->option('deadline');

        $limit = null;
        if ($limitOption !== null) {
            if (! $this->isPositiveIntegerString($limitOption)) {
                $this->error('Option --limit must be a positive integer.');

                return self::INVALID;
            }

            $requestedLimit = (int) $limitOption;
            $limitCeiling = max(1, (int) config('sitepulse.notification_max_limit', 100));
            if ($requestedLimit > $limitCeiling) {
                $this->warn("Requested limit ({$requestedLimit}) exceeds safety ceiling ({$limitCeiling}); clamping to {$limitCeiling}.");
            }

            $limit = min($limitCeiling, $requestedLimit);
        }

        $deadline = null;
        if ($deadlineOption !== null) {
            if (! $this->isPositiveIntegerString($deadlineOption)) {
                $this->error('Option --deadline must be a positive integer in seconds.');

                return self::INVALID;
            }

            $requestedDeadline = (int) $deadlineOption;
            $deadlineCeiling = max(1, (int) config('sitepulse.notification_max_deadline_seconds', 55));
            if ($requestedDeadline > $deadlineCeiling) {
                $this->warn("Requested deadline ({$requestedDeadline}s) exceeds safety ceiling ({$deadlineCeiling}s); clamping to {$deadlineCeiling}s.");
            }

            $deadline = min($deadlineCeiling, $requestedDeadline);
        }

        $report = $dispatcher->dispatchPending(
            limit: $limit,
            deadlineSeconds: $deadline,
        );

        if (! $report['alertsEnabled']) {
            $this->warn('Skipped notification dispatch: SITEPULSE_ALERTS_ENABLED is disabled.');

            return self::SUCCESS;
        }

        if (! $report['lockAcquired']) {
            $this->warn('Skipped notification dispatch pass: execution overlap lock is currently held.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Notification dispatch finished: claimed=%d sent=%d failed=%d fenced=%d deadline_reached=%s elapsed_ms=%d',
            $report['claimed'],
            $report['sent'],
            $report['failed'],
            $report['fenced'],
            $report['deadlineReached'] ? 'true' : 'false',
            $report['elapsedMs'],
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
