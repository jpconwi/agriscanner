<?php

/*
|--------------------------------------------------------------------------
| AgriScan configuration
|--------------------------------------------------------------------------
| Every external dependency is optional and can be switched off with a flag.
| Nothing in here enables a paid service by default.
*/

$nullableInt = static fn (string $key): ?int => ($v = env($key)) === null || $v === '' ? null : (int) $v;

return [
    // Only needed if a separate browser app consumes /api/v1 (see CORS_ALLOWED_ORIGINS).
    'frontend_url' => env('FRONTEND_URL'),

    'quota' => [
        // 1 GiB default. Admins can change the default and per-user quotas at runtime.
        'default_bytes' => (int) env('DEFAULT_QUOTA_BYTES', 1073741824),
        'warn_percent' => (int) env('QUOTA_WARN_PERCENT', 75),
        'critical_percent' => (int) env('QUOTA_CRITICAL_PERCENT', 90),
    ],

    'uploads' => [
        'max_kb' => (int) env('UPLOAD_MAX_KB', 8192),
        'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
        'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
        'min_dimension' => (int) env('UPLOAD_MIN_DIMENSION', 64),
        'max_dimension' => (int) env('UPLOAD_MAX_DIMENSION', 8000),
    ],

    'ai' => [
        // Comma separated, first = primary. Unconfigured providers are skipped automatically.
        'priority' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_PROVIDER_PRIORITY', 'huggingface,grok'))))),
        'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 45),
        'max_retries' => (int) env('AI_MAX_RETRIES', 2),
        'backoff_ms' => (int) env('AI_BACKOFF_MS', 500),
        'max_backoff_ms' => (int) env('AI_MAX_BACKOFF_MS', 8000),

        'providers' => [
            'huggingface' => [
                'enabled' => filter_var(env('HUGGINGFACE_ENABLED', true), FILTER_VALIDATE_BOOL),
                'token' => env('HUGGINGFACE_API_TOKEN'),
                // image-classification: returns real class probabilities (genuine confidence).
                // chat: a vision-language model via the OpenAI-compatible router (no calibrated confidence).
                'task' => env('HUGGINGFACE_TASK', 'image-classification'),
                'model' => env('HUGGINGFACE_MODEL'),
                'classification_base' => env('HUGGINGFACE_CLASSIFICATION_BASE', 'https://router.huggingface.co/hf-inference/models'),
                'chat_url' => env('HUGGINGFACE_CHAT_URL', 'https://router.huggingface.co/v1/chat/completions'),
                'hub_url' => env('HUGGINGFACE_HUB_URL', 'https://huggingface.co/api/models'),
            ],
            'grok' => [
                'enabled' => filter_var(env('GROK_ENABLED', false), FILTER_VALIDATE_BOOL),
                // Must be set to true by an operator who has confirmed the account may call this
                // model without unexpected charges. Without it Grok is never called.
                'access_authorized' => filter_var(env('GROK_ACCESS_AUTHORIZED', false), FILTER_VALIDATE_BOOL),
                'api_key' => env('GROK_API_KEY'),
                'model' => env('GROK_MODEL'),
                'base_url' => env('GROK_BASE_URL', 'https://api.x.ai/v1'),
            ],
        ],
    ],

    'storage' => [
        'priority' => array_values(array_filter(array_map('trim', explode(',', (string) env('STORAGE_PRIORITY', 'r2,secondary'))))),
        // When true, a successful primary write is also copied to the secondary store (best effort).
        'replicate' => filter_var(env('STORAGE_REPLICATE', false), FILTER_VALIDATE_BOOL),
        'max_retries' => (int) env('STORAGE_MAX_RETRIES', 2),
        'backoff_ms' => (int) env('STORAGE_BACKOFF_MS', 300),
        'signed_url_minutes' => (int) env('SIGNED_URL_MINUTES', 10),
        'journal_prefix' => 'journal/',

        'stores' => [
            'r2' => [
                'label' => 'Cloudflare R2',
                'enabled' => filter_var(env('R2_ENABLED', true), FILTER_VALIDATE_BOOL),
                'driver' => 's3',
                'key' => env('R2_ACCESS_KEY_ID'),
                'secret' => env('R2_SECRET_ACCESS_KEY'),
                'region' => env('R2_REGION', 'auto'),
                'bucket' => env('R2_BUCKET'),
                'endpoint' => env('R2_ENDPOINT'),
                'use_path_style_endpoint' => true,
                'throw' => true,
                // Cloudflare's free allowance is set by Cloudflare. Leave empty if unsure: shown as "Unknown".
                'provider_limit_bytes' => $nullableInt('R2_FREE_ALLOWANCE_BYTES'),
            ],
            'secondary' => [
                'label' => 'Secondary S3-compatible storage',
                'enabled' => filter_var(env('SECONDARY_STORAGE_ENABLED', false), FILTER_VALIDATE_BOOL),
                'driver' => 's3',
                'key' => env('SECONDARY_STORAGE_KEY'),
                'secret' => env('SECONDARY_STORAGE_SECRET'),
                'region' => env('SECONDARY_STORAGE_REGION', 'us-east-1'),
                'bucket' => env('SECONDARY_STORAGE_BUCKET'),
                'endpoint' => env('SECONDARY_STORAGE_ENDPOINT'),
                'use_path_style_endpoint' => filter_var(env('SECONDARY_STORAGE_PATH_STYLE', true), FILTER_VALIDATE_BOOL),
                'throw' => true,
                'provider_limit_bytes' => $nullableInt('SECONDARY_STORAGE_LIMIT_BYTES'),
            ],
        ],
    ],

    'database' => [
        // The secondary is for explicit, operator-driven recovery and the pending-operation journal.
        // It is never read from automatically.
        'secondary_enabled' => filter_var(env('DB_SECONDARY_ENABLED', false), FILTER_VALIDATE_BOOL),
        // Neon does not expose a limit through SQL. Set this (bytes) from your plan, or leave empty for "Unknown".
        'capacity_bytes' => $nullableInt('PRIMARY_DB_CAPACITY_BYTES'),
        'pooled' => filter_var(env('DB_POOLED', false), FILTER_VALIDATE_BOOL),
        'neon' => [
            'api_key' => env('NEON_API_KEY'),
            'project_id' => env('NEON_PROJECT_ID'),
        ],
    ],

    'health' => [
        'db_warn_percent' => (int) env('DB_WARN_PERCENT', 80),
        'stale_after_minutes' => (int) env('HEALTH_STALE_MINUTES', 30),
        // Shared secret for hitting the scheduler endpoint from a free external cron service.
        'cron_token' => env('INTERNAL_CRON_TOKEN'),
    ],

    'rate_limits' => [
        'login_per_minute' => (int) env('RATE_LOGIN_PER_MINUTE', 5),
        'uploads_per_minute' => (int) env('RATE_UPLOADS_PER_MINUTE', 10),
        'ai_per_hour' => (int) env('RATE_AI_PER_HOUR', 30),
        'api_per_minute' => (int) env('RATE_API_PER_MINUTE', 120),
    ],
];
