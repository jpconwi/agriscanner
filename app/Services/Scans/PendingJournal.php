<?php

namespace App\Services\Scans;

use App\Models\PendingOperation;
use App\Services\Storage\StorageManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Durable record of work that must not be lost. Payloads never contain image bytes or secrets.
 * Order: primary database -> secondary database (if configured) -> object-storage journal object.
 * Anything written outside the primary database is replayed into it by `agriscan:process-pending`.
 */
class PendingJournal
{
    public function __construct(private readonly StorageManager $storage)
    {
    }

    /** @return string where it was written: primary|secondary|storage|none */
    public function record(string $type, string $idempotencyKey, array $payload, ?int $userId, ?string $error = null): string
    {
        $row = [
            'uuid' => (string) Str::uuid(), 'user_id' => $userId, 'type' => $type, 'idempotency_key' => $idempotencyKey,
            'payload' => $payload, 'last_error' => $error ? Str::limit($error, 300) : null,
        ];

        try {
            PendingOperation::firstOrCreate(['idempotency_key' => $idempotencyKey], $row);

            return 'primary';
        } catch (Throwable $e) {
            Log::warning('journal.primary_failed');
        }

        if (config('agriscan.database.secondary_enabled')) {
            try {
                DB::connection('pgsql_secondary')->table('pending_operations')->insertOrIgnore([
                    'uuid' => $row['uuid'], 'user_id' => null, 'type' => $type, 'idempotency_key' => $idempotencyKey,
                    'payload' => json_encode($payload + ['_user_id' => $userId]), 'status' => 'pending', 'attempts' => 0,
                    'last_error' => $row['last_error'], 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);

                return 'secondary';
            } catch (Throwable $e) {
                Log::warning('journal.secondary_failed');
            }
        }

        $json = json_encode(['type' => $type, 'key' => $idempotencyKey, 'user_id' => $userId, 'payload' => $payload, 'error' => $row['last_error']]);
        if ($this->storage->putJournal(hash('sha256', $idempotencyKey), $json)) {
            return 'storage';
        }

        return 'none';
    }
}
