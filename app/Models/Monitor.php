<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MonitorFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

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
                'tls_warning_expires_at',
                'tls_warning_cycle',
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
     * Get historical and open incidents recorded for this monitor.
     *
     * @return HasMany<Incident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /**
     * Get the currently open outage incident for this monitor, if any.
     *
     * @return HasOne<Incident, $this>
     */
    public function openIncident(): HasOne
    {
        return $this->hasOne(Incident::class)->where('status', IncidentStatus::OPEN->value);
    }

    /**
     * Get alert notification records created for this monitor.
     *
     * @return HasMany<IncidentNotification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(IncidentNotification::class);
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
     * Pause this monitor, clearing transient flapping counters and closing any open incident without recovery alerts.
     */
    public function pause(): bool
    {
        return (bool) DB::transaction(function (): bool {
            $this->is_active = false;
            $this->status = MonitorStatus::PAUSED;
            $this->consecutive_failures = 0;
            $this->consecutive_successes = 0;
            $this->next_check_at = null;

            $saved = $this->save();
            $this->resolveOpenIncidentSilently();

            return $saved;
        });
    }

    /**
     * Resume this monitor cleanly into unchecked `unknown` state without immediate network probes.
     */
    public function resume(): bool
    {
        return (bool) DB::transaction(function (): bool {
            $this->is_active = true;
            $this->status = MonitorStatus::UNKNOWN;
            $this->consecutive_failures = 0;
            $this->consecutive_successes = 0;
            $this->next_check_at = null;

            $saved = $this->save();
            $this->resolveOpenIncidentSilently();

            return $saved;
        });
    }

    /**
     * Close any active open incident for this monitor without emitting a recovery alert.
     */
    public function resolveOpenIncidentSilently(?DateTimeInterface $resolvedAt = null): void
    {
        $resolvedTimestamp = $resolvedAt !== null
            ? CarbonImmutable::instance($resolvedAt)->utc()
            : now('UTC')->toImmutable();

        Incident::query()
            ->where('monitor_id', $this->getKey())
            ->where('status', IncidentStatus::OPEN->value)
            ->lockForUpdate()
            ->get()
            ->each(function (Incident $incident) use ($resolvedTimestamp): void {
                $durationSeconds = $incident->started_at !== null
                    ? max(0, (int) $incident->started_at->utc()->diffInSeconds($resolvedTimestamp, false))
                    : 0;

                $incident->forceFill([
                    'status' => IncidentStatus::RESOLVED,
                    'open_monitor_id' => null,
                    'resolved_at' => $resolvedTimestamp,
                    'duration_seconds' => $durationSeconds,
                ])->save();
            });
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
        $this->tls_warning_expires_at = null;
        $this->tls_warning_cycle = max(1, (int) ($this->tls_warning_cycle ?? 1)) + 1;
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
            'tls_warning_expires_at' => 'datetime',
            'tls_warning_cycle' => 'integer',
            'revision' => 'integer',
        ];
    }
}
