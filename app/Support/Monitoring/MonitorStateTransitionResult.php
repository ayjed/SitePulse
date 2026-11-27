<?php

namespace App\Support\Monitoring;

use App\Enums\MonitorStatus;

/**
 * Immutable output produced by MonitorStateTransition::apply().
 */
final readonly class MonitorStateTransitionResult
{
    public function __construct(
        public MonitorStatus $nextStatus,
        public int $consecutiveFailures,
        public int $consecutiveSuccesses,
        public bool $statusChanged,
    ) {}
}
