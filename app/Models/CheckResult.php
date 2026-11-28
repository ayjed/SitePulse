<?php

namespace App\Models;

use App\Enums\CheckResultStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historical check execution record persisted for each completed monitor probe.
 */
#[Fillable([
    'monitor_id',
    'status',
    'status_code',
    'latency_ms',
    'failure_reason',
    'error_message',
    'redirects_followed',
    'body_truncated',
    'checked_at',
])]
class CheckResult extends Model
{
    public $timestamps = false;

    /**
     * Get the monitor that owns this check result.
     *
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monitor_id' => 'integer',
            'status' => CheckResultStatus::class,
            'status_code' => 'integer',
            'latency_ms' => 'integer',
            'redirects_followed' => 'integer',
            'body_truncated' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }
}
