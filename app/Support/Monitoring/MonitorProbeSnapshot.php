<?php

namespace App\Support\Monitoring;

use App\Enums\MonitorStatus;
use App\Models\Monitor;

/**
 * Immutable snapshot of monitor configuration, state, and persistent revision captured before probing.
 */
final readonly class MonitorProbeSnapshot
{
    public function __construct(
        public int $monitorId,
        public string $url,
        public string $scheme,
        public int $checkIntervalMinutes,
        public int $timeoutSeconds,
        public int $expectedStatusCodeMin,
        public int $expectedStatusCodeMax,
        public MonitorStatus $initialStatus,
        public int $initialConsecutiveFailures,
        public int $initialConsecutiveSuccesses,
        public int $revision,
        public ?int $updatedAtTimestamp,
    ) {}

    public static function fromMonitor(Monitor $monitor): self
    {
        $scheme = strtolower((string) (parse_url($monitor->url, PHP_URL_SCHEME) ?: 'https'));

        return new self(
            monitorId: (int) $monitor->getKey(),
            url: (string) $monitor->url,
            scheme: $scheme,
            checkIntervalMinutes: (int) $monitor->check_interval_minutes,
            timeoutSeconds: (int) $monitor->timeout_seconds,
            expectedStatusCodeMin: (int) $monitor->expected_status_code_min,
            expectedStatusCodeMax: (int) $monitor->expected_status_code_max,
            initialStatus: $monitor->status,
            initialConsecutiveFailures: (int) $monitor->consecutive_failures,
            initialConsecutiveSuccesses: (int) $monitor->consecutive_successes,
            revision: max(1, (int) ($monitor->revision ?? 1)),
            updatedAtTimestamp: $monitor->updated_at?->utc()->getTimestamp(),
        );
    }

    /**
     * Determine whether a locked database row still matches this pre-flight snapshot, revision, and active state.
     */
    public function matchesLockedMonitor(Monitor $lockedMonitor): bool
    {
        if (! $lockedMonitor->is_active || $lockedMonitor->status === MonitorStatus::PAUSED) {
            return false;
        }

        if ((int) ($lockedMonitor->revision ?? 0) !== $this->revision) {
            return false;
        }

        if (
            (string) $lockedMonitor->url !== $this->url
            || (int) $lockedMonitor->check_interval_minutes !== $this->checkIntervalMinutes
            || (int) $lockedMonitor->timeout_seconds !== $this->timeoutSeconds
            || (int) $lockedMonitor->expected_status_code_min !== $this->expectedStatusCodeMin
            || (int) $lockedMonitor->expected_status_code_max !== $this->expectedStatusCodeMax
            || $lockedMonitor->status !== $this->initialStatus
            || (int) $lockedMonitor->consecutive_failures !== $this->initialConsecutiveFailures
            || (int) $lockedMonitor->consecutive_successes !== $this->initialConsecutiveSuccesses
        ) {
            return false;
        }

        $lockedUpdatedAtTs = $lockedMonitor->updated_at?->utc()->getTimestamp();

        if ($this->updatedAtTimestamp === null && $lockedUpdatedAtTs !== null) {
            return false;
        }

        if ($this->updatedAtTimestamp !== null && $lockedUpdatedAtTs !== null && $lockedUpdatedAtTs > $this->updatedAtTimestamp) {
            return false;
        }

        return true;
    }
}
