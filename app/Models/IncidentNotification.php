<?php

namespace App\Models;

use App\Enums\IncidentNotificationType;
use App\Enums\NotificationDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable notification outbox and delivery audit record for monitor outages, recoveries, and TLS warnings.
 */
class IncidentNotification extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = ['id'];

    /**
     * Get the monitor associated with this alert notification.
     *
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /**
     * Get the outage incident associated with down/recovery notifications, if any.
     *
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monitor_id' => 'integer',
            'incident_id' => 'integer',
            'type' => IncidentNotificationType::class,
            'delivery_status' => NotificationDeliveryStatus::class,
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'claim_version' => 'integer',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'is_success' => 'boolean',
            'duration_seconds' => 'integer',
            'tls_expires_at' => 'datetime',
        ];
    }
}
