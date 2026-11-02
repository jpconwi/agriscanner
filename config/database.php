<?php

use Illuminate\Support\Str;

return [
    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],

        // Primary: Neon PostgreSQL. Use the POOLED host for the running app and the DIRECT host for migrations
        // (set DB_URL to the pooled string; run migrations with DB_URL_DIRECT, see README).
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'agriscan'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'require'),
            'options' => [
                // Fail fast instead of hanging when Neon is waking up or unreachable.
                \PDO::ATTR_TIMEOUT => (int) env('DB_CONNECT_TIMEOUT', 10),
                // Keep real prepared statements. Emulation sends booleans as integers, which PostgreSQL rejects,
                // and Neon's pooler supports protocol-level prepared statements. Only set DB_EMULATE_PREPARES=true
                // if you use a pooler that does not (and then expect boolean columns to need explicit casts).
                \PDO::ATTR_EMULATE_PREPARES => (bool) env('DB_EMULATE_PREPARES', false),
            ],
        ],

        // Optional secondary PostgreSQL, used ONLY for explicit recovery and the pending-operation journal.
        'pgsql_secondary' => [
            'driver' => 'pgsql',
            'url' => env('DB_SECONDARY_URL'),
            'host' => env('DB_SECONDARY_HOST', '127.0.0.1'),
            'port' => env('DB_SECONDARY_PORT', '5432'),
            'database' => env('DB_SECONDARY_DATABASE', 'agriscan'),
            'username' => env('DB_SECONDARY_USERNAME', 'postgres'),
            'password' => env('DB_SECONDARY_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SECONDARY_SSLMODE', 'require'),
        ],
    ],

    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
    'redis' => [],
];
