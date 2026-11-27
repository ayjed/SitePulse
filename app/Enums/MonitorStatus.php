<?php

namespace App\Enums;

/**
 * Lifecycle monitoring states defined by the SitePulse architecture.
 */
enum MonitorStatus: string
{
    case UNKNOWN = 'unknown';
    case OPERATIONAL = 'operational';
    case PENDING_DOWN = 'pending_down';
    case DOWN = 'down';
    case PENDING_RECOVERY = 'pending_recovery';
    case PAUSED = 'paused';

    public function label(): string
    {
        return match ($this) {
            self::UNKNOWN => 'Unknown',
            self::OPERATIONAL => 'Operational',
            self::PENDING_DOWN => 'Pending Down',
            self::DOWN => 'Down',
            self::PENDING_RECOVERY => 'Pending Recovery',
            self::PAUSED => 'Paused',
        };
    }

    /**
     * Tailwind classes styled cleanly for accessible light and dark mode status badges.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::UNKNOWN => 'border-slate-300 bg-slate-100 text-slate-900 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100',
            self::OPERATIONAL => 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-200',
            self::PENDING_DOWN => 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/60 dark:text-amber-200',
            self::DOWN => 'border-rose-300 bg-rose-50 text-rose-900 dark:border-rose-800 dark:bg-rose-950/60 dark:text-rose-200',
            self::PENDING_RECOVERY => 'border-sky-300 bg-sky-50 text-sky-900 dark:border-sky-800 dark:bg-sky-950/60 dark:text-sky-200',
            self::PAUSED => 'border-stone-300 bg-stone-100 text-stone-700 dark:border-stone-700 dark:bg-stone-800 dark:text-stone-300',
        };
    }
}
