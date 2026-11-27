<?php

namespace App\Support\Monitoring;

/**
 * Bounded execution summary returned by MonitorCheckRunner::run().
 */
final readonly class MonitorRunReport
{
    public function __construct(
        public bool $lockAcquired,
        public int $dueCount,
        public int $startedCount,
        public int $completedCount,
        public int $succeededCount,
        public int $failedCount,
        public int $staleDiscardedCount,
        public int $softDeadlineSkippedCount,
        public int $hardDeadlineCancelledCount,
        public int $elapsedMs,
        public bool $softDeadlineReached,
        public bool $hardDeadlineReached,
    ) {}
}
