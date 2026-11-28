<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Monitoring Engine Configuration
    |--------------------------------------------------------------------------
    |
    | Bounded execution defaults for the scheduled monitoring engine.
    | Concurrency is strictly clamped to 1..max_concurrency_ceiling to protect
    | shared hosting CPU, memory, and outbound socket limits.
    |
    | Unattended scheduled probing remains disabled by default until
    | SITEPULSE_SCHEDULED_CHECKS_ENABLED is explicitly set to true.
    |
    */
    'scheduled_checks_enabled' => filter_var(
        env('SITEPULSE_SCHEDULED_CHECKS_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE,
    ) === true,

    'max_concurrency' => max(1, min(10, (int) env('SITEPULSE_MAX_CONCURRENCY', 5))),

    'max_concurrency_ceiling' => 10,

    'soft_deadline_seconds' => 45,

    'hard_deadline_seconds' => 50,

    'check_lock_ttl_seconds' => 600,

    'lock_store' => env('SITEPULSE_CHECK_LOCK_STORE'),

    'batch_persist_size' => 10,

    'history_retention_days' => max(1, (int) env('SITEPULSE_HISTORY_RETENTION_DAYS', 30)),
];
