<?php

namespace App\Support\Monitoring;

use App\Enums\MonitorStatus;
use LogicException;

/**
 * Computes deterministic monitor state transitions using SitePulse's N=2 failure and M=2 recovery thresholds.
 */
final class MonitorStateTransition
{
    public const FAILURE_THRESHOLD = 2;

    public const RECOVERY_THRESHOLD = 2;

    /**
     * Determine whether a transition confirmed a new outage (N=2 consecutive failures).
     */
    public static function isConfirmedOutage(MonitorStatus $previousStatus, MonitorStatus $nextStatus): bool
    {
        return $previousStatus === MonitorStatus::PENDING_DOWN && $nextStatus === MonitorStatus::DOWN;
    }

    /**
     * Determine whether a transition confirmed recovery (M=2 consecutive successes from outage).
     */
    public static function isConfirmedRecovery(MonitorStatus $previousStatus, MonitorStatus $nextStatus): bool
    {
        return $previousStatus === MonitorStatus::PENDING_RECOVERY && $nextStatus === MonitorStatus::OPERATIONAL;
    }

    /**
     * Advance monitor health state based on a completed check outcome.
     */
    public function apply(
        MonitorStatus $currentStatus,
        int $currentFailures,
        int $currentSuccesses,
        bool $checkSucceeded,
    ): MonitorStateTransitionResult {
        if ($currentStatus === MonitorStatus::PAUSED) {
            throw new LogicException('Cannot transition a paused monitor state without resuming it first.');
        }

        $safeFailures = max(0, $currentFailures);
        $safeSuccesses = max(0, $currentSuccesses);

        if ($checkSucceeded) {
            [$nextStatus, $failures, $successes] = match ($currentStatus) {
                MonitorStatus::UNKNOWN, MonitorStatus::PENDING_DOWN => [MonitorStatus::OPERATIONAL, 0, 1],
                MonitorStatus::OPERATIONAL => [MonitorStatus::OPERATIONAL, 0, $safeSuccesses + 1],
                MonitorStatus::DOWN => [MonitorStatus::PENDING_RECOVERY, 0, 1],
                MonitorStatus::PENDING_RECOVERY => $safeSuccesses + 1 >= self::RECOVERY_THRESHOLD
                    ? [MonitorStatus::OPERATIONAL, 0, $safeSuccesses + 1]
                    : [MonitorStatus::PENDING_RECOVERY, 0, $safeSuccesses + 1],
            };

            return new MonitorStateTransitionResult(
                nextStatus: $nextStatus,
                consecutiveFailures: $failures,
                consecutiveSuccesses: $successes,
                statusChanged: $nextStatus !== $currentStatus,
            );
        }

        [$nextStatus, $failures, $successes] = match ($currentStatus) {
            MonitorStatus::UNKNOWN, MonitorStatus::OPERATIONAL => [MonitorStatus::PENDING_DOWN, 1, 0],
            MonitorStatus::PENDING_DOWN => $safeFailures + 1 >= self::FAILURE_THRESHOLD
                ? [MonitorStatus::DOWN, $safeFailures + 1, 0]
                : [MonitorStatus::PENDING_DOWN, $safeFailures + 1, 0],
            MonitorStatus::DOWN => [MonitorStatus::DOWN, $safeFailures + 1, 0],
            MonitorStatus::PENDING_RECOVERY => [MonitorStatus::DOWN, 1, 0],
        };

        return new MonitorStateTransitionResult(
            nextStatus: $nextStatus,
            consecutiveFailures: $failures,
            consecutiveSuccesses: $successes,
            statusChanged: $nextStatus !== $currentStatus,
        );
    }
}
