<?php

namespace App\Enums;

/**
 * Outage lifecycle states for persisted SitePulse incidents.
 */
enum IncidentStatus: string
{
    case OPEN = 'open';
    case RESOLVED = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open',
            self::RESOLVED => 'Resolved',
        };
    }
}
