<?php

// Browser cross-origin access to the JSON API is closed unless CORS_ALLOWED_ORIGINS lists an origin.
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    // Empty by default: the Blade UI is same-origin. List origins only if another site calls /api/v1.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'Idempotency-Key'],
    'exposed_headers' => ['Retry-After', 'X-RateLimit-Remaining'],
    'max_age' => 3600,
    // Bearer tokens are used, so cookies/credentials are not needed.
    'supports_credentials' => false,
];
