<?php

return [
    // Pure bearer-token API: no stateful (cookie/SPA) domains.
    'stateful' => [],
    'guard' => [],
    // Tokens expire after 30 days of issue.
    'expiration' => (int) env('SANCTUM_TOKEN_MINUTES', 43200),
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
    'middleware' => [],
];
