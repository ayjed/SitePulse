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

    'alerts_enabled' => filter_var(
        env('SITEPULSE_ALERTS_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE,
    ) === true,

    'tls_expiry_warning_days' => max(1, (int) env('SITEPULSE_TLS_EXPIRY_WARNING_DAYS', 14)),

    'notification_max_attempts' => max(1, (int) env('SITEPULSE_NOTIFICATION_MAX_ATTEMPTS', 3)),

    'notification_claim_timeout_seconds' => max(15, (int) env('SITEPULSE_NOTIFICATION_CLAIM_TIMEOUT_SECONDS', 60)),

    'notification_retry_backoff_seconds' => max(5, (int) env('SITEPULSE_NOTIFICATION_RETRY_BACKOFF_SECONDS', 60)),

    'history_retention_days' => max(1, (int) env('SITEPULSE_HISTORY_RETENTION_DAYS', 30)),

    'prune_checks_enabled' => filter_var(
        env('SITEPULSE_PRUNE_CHECKS_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE,
    ) === true,

    'prune_batch_size' => max(50, (int) env('SITEPULSE_PRUNE_BATCH_SIZE', 1000)),
];
