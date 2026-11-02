<?php

// Browser UI uses encrypted cookie sessions (no database round-trip); the /api/v1 API is token based.
return [
    'driver' => env('SESSION_DRIVER', 'cookie'),
    'lifetime' => 120,
    'expire_on_close' => false,
    'encrypt' => true,
    'files' => storage_path('framework/sessions'),
    'connection' => null,
    'table' => 'sessions',
    'store' => null,
    'lottery' => [2, 100],
    'cookie' => 'agriscan_session',
    'path' => '/',
    'domain' => null,
    'secure' => env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production'),
    'http_only' => true,
    'same_site' => 'lax',
];
