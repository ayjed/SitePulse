<?php

namespace App\Enums;

/**
 * Canonical alert notification event types aligned with ADR-0004 and SitePulse architecture.
 */
enum IncidentNotificationType: string
{
    case DOWN = 'down';
    case RECOVERY = 'recovery';
    case TLS_WARNING = 'tls_warning';

    public function label(): string
    {
        return match ($this) {
            self::DOWN => 'Monitor Down',
            self::RECOVERY => 'Monitor Recovered',
            self::TLS_WARNING => 'TLS Certificate Warning',
        };
    }
}
