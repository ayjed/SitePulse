<?php

namespace App\Support\Monitoring;

/**
 * Immutable summary metrics for one bounded monitor check execution pass.
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
        public int $incidentsOpenedCount = 0,
        public int $incidentsResolvedCount = 0,
        public int $notificationsQueuedCount = 0,
        public int $notificationsSentCount = 0,
        public int $notificationsFailedCount = 0,
    ) {}
}
