<?php

namespace App\Enums;

/**
 * Lifecycle monitoring states defined by the SitePulse architecture.
 */
enum MonitorStatus: string
{
    case OPERATIONAL = 'operational';
    case PENDING_DOWN = 'pending_down';
    case DOWN = 'down';
    case PENDING_RECOVERY = 'pending_recovery';
    case PAUSED = 'paused';

    public function label(): string
    {
        return match ($this) {
            self::OPERATIONAL => 'Operational',
            self::PENDING_DOWN => 'Pending Down',
            self::DOWN => 'Down',
            self::PENDING_RECOVERY => 'Pending Recovery',
            self::PAUSED => 'Paused',
        };
    }
}
