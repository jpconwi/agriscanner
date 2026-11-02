<?php

use Monolog\Handler\StreamHandler;

// Render captures stdout/stderr, so production logs go to stderr. Image data and secrets are never logged.
return [
    'default' => env('LOG_CHANNEL', 'stderr'),
    'deprecations' => ['channel' => 'null', 'trace' => false],
    'channels' => [
        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => 'php://stderr'],
            'processors' => [Monolog\Processor\PsrLogMessageProcessor::class],
        ],
        'single' => ['driver' => 'single', 'path' => storage_path('logs/laravel.log'), 'level' => env('LOG_LEVEL', 'debug'), 'replace_placeholders' => true],
        'null' => ['driver' => 'monolog', 'handler' => Monolog\Handler\NullHandler::class],
    ],
];
