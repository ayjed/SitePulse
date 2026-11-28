<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a confirmed availability outage lifecycle for a monitored endpoint.
 */
class Incident extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = ['id'];

    /**
     * Get the monitor associated with this outage incident.
     *
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /**
     * Get notification events dispatched for this incident.
     *
     * @return HasMany<IncidentNotification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(IncidentNotification::class);
    }

    /**
     * Scope currently open incidents.
     *
     * @param  Builder<Incident>  $query
     * @return Builder<Incident>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', IncidentStatus::OPEN->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monitor_id' => 'integer',
            'open_monitor_id' => 'integer',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'duration_seconds' => 'integer',
            'status' => IncidentStatus::class,
        ];
    }
}
