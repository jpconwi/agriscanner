<?php

return [
    // "Sign in with Google" (OAuth 2.0 authorization-code flow, no extra package needed).
    // Leave GOOGLE_CLIENT_ID empty to hide the button and disable the routes' effect.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // Defaults to APP_URL + /auth/google/callback. Must match the URI registered in Google Cloud exactly.
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],
];
