<?php

namespace App\Models;

use App\Enums\MonitorStatus;
use Database\Factories\MonitorFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'url',
    'check_interval_minutes',
    'timeout_seconds',
    'expected_status_code_min',
    'expected_status_code_max',
])]
class Monitor extends Model
{
    /** @use HasFactory<MonitorFactory> */
    use HasFactory;

    /**
     * Permitted check intervals in minutes.
     *
     * @var array<int, int>
     */
    public const ALLOWED_INTERVALS = [1, 5, 10, 15, 30, 60];

    public const DEFAULT_INTERVAL_MINUTES = 5;

    public const MIN_TIMEOUT_SECONDS = 2;

    public const MAX_TIMEOUT_SECONDS = 15;

    public const DEFAULT_TIMEOUT_SECONDS = 10;

    public const MIN_HTTP_STATUS = 100;

    public const MAX_HTTP_STATUS = 599;

    public const DEFAULT_EXPECTED_STATUS_MIN = 200;

    public const DEFAULT_EXPECTED_STATUS_MAX = 299;

    /**
     * Initialize and monotonically increment persistent monitor revision on configuration or state changes.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $monitor): void {
            if ($monitor->revision === null || (int) $monitor->revision < 1) {
                $monitor->revision = 1;
            }
        });

        static::updating(static function (self $monitor): void {
            if ($monitor->isDirty([
                'name',
                'url',
                'check_interval_minutes',
                'timeout_seconds',
                'expected_status_code_min',
                'expected_status_code_max',
                'is_active',
                'status',
                'consecutive_failures',
                'consecutive_successes',
                'last_checked_at',
                'next_check_at',
                'last_status_code',
                'last_latency_ms',
                'tls_expires_at',
                'tls_last_checked_at',
            ])) {
                $current = (int) ($monitor->getOriginal('revision') ?? $monitor->revision ?? 1);
                $monitor->revision = max(1, $current) + 1;
            }
        });
    }

    /**
     * Get the single site owner associated with this monitor.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get historical check results recorded for this monitor.
     *
     * @return HasMany<CheckResult, $this>
     */
    public function checkResults(): HasMany
    {
        return $this->hasMany(CheckResult::class);
    }

    /**
     * Scope monitors to a specific owner.
     *
     * @param  Builder<Monitor>  $query
     * @return Builder<Monitor>
     */
    public function scopeForOwner(Builder $query, User $owner): Builder
    {
        return $query->where('user_id', $owner->getKey());
    }

    /**
     * Scope monitors eligible for future automated scheduling (explicitly excludes paused/inactive monitors).
     *
     * @param  Builder<Monitor>  $query
     * @return Builder<Monitor>
     */
    public function scopeEligibleForCheck(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('status', '!=', MonitorStatus::PAUSED->value);
    }

    /**
     * Scope due active monitors using portable SQL ordering for SQLite and MySQL compatibility.
     *
     * @param  Builder<Monitor>  $query
     * @return Builder<Monitor>
     */
    public function scopeDueForCheck(Builder $query, ?DateTimeInterface $asOf = null): Builder
    {
        $cutoff = $asOf ?? now();

        return $query
            ->eligibleForCheck()
            ->where(function (Builder $nested) use ($cutoff): void {
                $nested->whereNull('next_check_at')
                    ->orWhere('next_check_at', '<=', $cutoff);
            })
            ->orderByRaw('(CASE WHEN last_checked_at IS NULL THEN 0 ELSE 1 END) ASC, last_checked_at ASC')
            ->orderBy('id');
    }

    /**
     * Determine whether monitoring is currently paused.
     */
    public function isPaused(): bool
    {
        return ! $this->is_active || $this->status === MonitorStatus::PAUSED;
    }

    /**
     * Pause this monitor, clearing transient flapping counters and eligibility while keeping check history.
     */
    public function pause(): bool
    {
        $this->is_active = false;
        $this->status = MonitorStatus::PAUSED;
        $this->consecutive_failures = 0;
        $this->consecutive_successes = 0;
        $this->next_check_at = null;

        return $this->save();
    }

    /**
     * Resume this monitor cleanly into unchecked `unknown` state without immediate network probes.
     */
    public function resume(): bool
    {
        $this->is_active = true;
        $this->status = MonitorStatus::UNKNOWN;
        $this->consecutive_failures = 0;
        $this->consecutive_successes = 0;
        $this->next_check_at = null;

        return $this->save();
    }

    /**
     * Reset probe telemetry and TLS inspection state when target URL changes.
     */
    public function resetTelemetryForUrlChange(bool $active): void
    {
        $this->consecutive_failures = 0;
        $this->consecutive_successes = 0;
        $this->last_checked_at = null;
        $this->next_check_at = null;
        $this->last_status_code = null;
        $this->last_latency_ms = null;
        $this->tls_expires_at = null;
        $this->tls_last_checked_at = null;
        $this->is_active = $active;
        $this->status = $active ? MonitorStatus::UNKNOWN : MonitorStatus::PAUSED;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'check_interval_minutes' => 'integer',
            'timeout_seconds' => 'integer',
            'expected_status_code_min' => 'integer',
            'expected_status_code_max' => 'integer',
            'is_active' => 'boolean',
            'status' => MonitorStatus::class,
            'consecutive_failures' => 'integer',
            'consecutive_successes' => 'integer',
            'last_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
            'last_status_code' => 'integer',
            'last_latency_ms' => 'integer',
            'tls_expires_at' => 'datetime',
            'tls_last_checked_at' => 'datetime',
            'revision' => 'integer',
        ];
    }
}
