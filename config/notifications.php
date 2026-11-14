<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | External provider
    |--------------------------------------------------------------------------
    | Every channel is delivered through one HTTP endpoint, as in the brief:
    | POST {to, channel, content} -> 202 {messageId, status, timestamp}.
    */
    'provider' => [
        'url' => (string) env('NOTIFICATION_PROVIDER_URL', 'http://mock-provider:9000/send'),
        'connect_timeout_seconds' => (float) env('NOTIFICATION_PROVIDER_CONNECT_TIMEOUT', 2),
        'timeout_seconds' => (float) env('NOTIFICATION_PROVIDER_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    | rate_per_second is a hard ceiling shared by all worker processes.
    */
    'channels' => [
        'sms' => [
            'rate_per_second' => (int) env('SMS_RATE_PER_SECOND', 100),
            'max_segments' => (int) env('SMS_MAX_SEGMENTS', 3),
        ],
        'email' => [
            'rate_per_second' => (int) env('EMAIL_RATE_PER_SECOND', 100),
            'max_length' => (int) env('EMAIL_MAX_LENGTH', 100_000),
        ],
        'push' => [
            'rate_per_second' => (int) env('PUSH_RATE_PER_SECOND', 100),
            'max_length' => (int) env('PUSH_MAX_LENGTH', 1024),
        ],
    ],

    'rate_limit' => [
        // Slots are spaced slightly wider than 1/rate so that network jitter
        // between the worker and the provider cannot push a one-second window
        // over the limit as measured at the provider.
        'safety_margin' => (float) env('RATE_LIMIT_SAFETY_MARGIN', 0.05),
        // A worker waits in-process for its slot up to this long; beyond it
        // (provider asked us to back off) the job is released instead.
        'max_block_ms' => (int) env('RATE_LIMIT_MAX_BLOCK_MS', 1500),
        // A worker that wakes up later than this for its slot (CPU stall,
        // garbage collection) gives the slot up and takes a fresh one: a
        // late message would arrive bunched with the ones behind it.
        'max_lateness_ms' => (int) env('RATE_LIMIT_MAX_LATENESS_MS', 10),
    ],

    'delivery' => [
        'max_attempts' => (int) env('DELIVERY_MAX_ATTEMPTS', 5),
        // How long a worker owns a notification. Must stay below retry_after.
        'lease_seconds' => (int) env('DELIVERY_LEASE_SECONDS', 30),
        // A notification that could not be delivered within this time fails.
        'ttl_seconds' => (int) env('DELIVERY_TTL_SECONDS', 86_400),
        'backoff' => [
            'base_seconds' => (int) env('RETRY_BASE_SECONDS', 2),
            'factor' => (int) env('RETRY_FACTOR', 3),
            'max_seconds' => (int) env('RETRY_MAX_SECONDS', 300),
        ],
    ],

    'circuit_breaker' => [
        'failure_threshold' => (int) env('CIRCUIT_FAILURE_THRESHOLD', 20),
        'cooldown_seconds' => (int) env('CIRCUIT_COOLDOWN_SECONDS', 15),
        'probe_lock_seconds' => (int) env('CIRCUIT_PROBE_LOCK_SECONDS', 10),
    ],

    'sweeper' => [
        'interval_ms' => (int) env('SWEEPER_INTERVAL_MS', 1000),
        'chunk' => (int) env('SWEEPER_CHUNK', 500),
        // Queued rows whose push to Redis was never confirmed.
        'unqueued_grace_seconds' => (int) env('SWEEPER_UNQUEUED_GRACE_SECONDS', 10),
        // Processing rows whose lease expired (worker died mid-delivery).
        'lease_grace_seconds' => (int) env('SWEEPER_LEASE_GRACE_SECONDS', 5),
        // Queued rows that have been waiting suspiciously long (lost job).
        'stale_after_seconds' => (int) env('SWEEPER_STALE_AFTER_SECONDS', 900),
    ],

    'batch' => [
        'max_size' => 1000,
    ],

    'workers' => [
        'per_channel' => (int) env('WORKERS_PER_CHANNEL', 4),
        'fair_per_channel' => (int) env('FAIR_WORKERS_PER_CHANNEL', 1),
    ],

    'metrics' => [
        'redis_database' => (int) env('METRICS_REDIS_DB', 2),
    ],

    'realtime' => [
        // Broadcast every status change over WebSocket (Laravel Reverb).
        'enabled' => (bool) env('REALTIME_ENABLED', true),
        // Port on which a browser reaches the Reverb container.
        'public_port' => (int) env('REVERB_PUBLIC_PORT', 8085),
    ],

    // Demo scenarios (`make demo`) call the public API over HTTP.
    'scenarios' => [
        'api_url' => (string) env('SCENARIO_API_URL', 'http://app:8000'),
    ],
];
