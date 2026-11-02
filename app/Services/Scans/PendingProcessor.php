<?php

namespace App\Services\Scans;

use App\Models\PendingOperation;
use App\Models\Scan;
use App\Models\ScanResult;
use App\Models\StoredFile;
use App\Services\Quota\QuotaService;
use App\Services\Storage\StorageManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Replays journalled work and retries failed storage operations. Every handler is idempotent. */
class PendingProcessor
{
    public function __construct(private readonly StorageManager $storage, private readonly QuotaService $quota)
    {
    }

    /** @return array{processed:int, completed:int, failed:int, replayed:int, cleaned:int} */
    public function run(int $limit = 50): array
    {
        $replayed = $this->replayExternalJournal();
        $cleaned = $this->cleanStalePending();
        $done = 0;
        $failed = 0;
        $n = 0;

        PendingOperation::whereIn('status', ['pending', 'failed'])
            ->where('attempts', '<', PendingOperation::MAX_ATTEMPTS)
            ->where('available_at', '<=', now())
            ->orderBy('id')->limit($limit)->get()
            ->each(function (PendingOperation $op) use (&$done, &$failed, &$n) {
                $n++;
                $this->processOne($op) ? $done++ : $failed++;
            });

        return ['processed' => $n, 'completed' => $done, 'failed' => $failed, 'replayed' => $replayed, 'cleaned' => $cleaned];
    }

    public function processOne(PendingOperation $op): bool
    {
        $op->update(['status' => 'processing', 'attempts' => $op->attempts + 1]);
        try {
            match ($op->type) {
                'delete_replica' => $this->deleteReplica($op),
                'replicate_file' => $this->replicate($op),
                'save_scan' => $this->saveScan($op),
                default => throw new \RuntimeException('Unknown operation type.'),
            };
            $op->update(['status' => 'completed', 'last_error' => null]);

            return true;
        } catch (Throwable $e) {
            $delay = min(60 * (2 ** $op->attempts), 3600 * 6);
            $op->update([
                'status' => $op->attempts >= PendingOperation::MAX_ATTEMPTS ? 'failed' : 'pending',
                'last_error' => Str::limit($e->getMessage(), 300),
                'available_at' => now()->addSeconds($delay),
            ]);
            Log::warning('pending.failed', ['type' => $op->type, 'attempts' => $op->attempts]);

            return false;
        }
    }

    /** Admin "retry" button: reset the attempt budget and run once now. */
    public function retryNow(PendingOperation $op): bool
    {
        $op->update(['status' => 'pending', 'attempts' => 0, 'available_at' => now()]);

        return $this->processOne($op);
    }

    private function deleteReplica(PendingOperation $op): void
    {
        $file = StoredFile::where('uuid', $op->payload['file_uuid'] ?? '')->first();
        if (! $file) {
            return; // already gone
        }
        if (! $this->storage->deleteEverywhere($file)) {
            throw new \RuntimeException('Replicas still could not be deleted.');
        }
        DB::transaction(function () use ($file) {
            if ($file->user) {
                $this->quota->decrement($file->user, $file->size_bytes);
            }
            Scan::where('stored_file_id', $file->id)->delete();
            $file->delete();
        });
    }

    private function replicate(PendingOperation $op): void
    {
        $file = StoredFile::where('uuid', $op->payload['file_uuid'] ?? '')->where('status', StoredFile::AVAILABLE)->first();
        $target = $this->storage->store($op->payload['provider'] ?? '');
        if (! $file || ! $target?->isConfigured()) {
            return;
        }
        $bytes = $this->storage->read($file);
        $source = $this->storage->store($file->replicas()->where('status', 'available')->value('provider'));
        $this->storage->replicateTo($file, $source, $bytes);
        if (! $file->replicas()->where('provider', $target->name())->where('status', 'available')->exists()) {
            throw new \RuntimeException('Replica still missing.');
        }
    }

    private function saveScan(PendingOperation $op): void
    {
        $scan = Scan::where('uuid', $op->payload['scan_uuid'] ?? '')->first();
        if (! $scan) {
            throw new \RuntimeException('Scan row not found in primary database.');
        }
        DB::transaction(function () use ($scan, $op) {
            ScanResult::updateOrCreate(['scan_id' => $scan->id], $op->payload['result']);
            $scan->update(['status' => Scan::COMPLETED, 'failure_category' => null, 'failure_message' => null]);
        });
    }

    /** Move entries written to the secondary DB / object-storage journal back into the primary database. */
    private function replayExternalJournal(): int
    {
        $count = 0;
        if (config('agriscan.database.secondary_enabled')) {
            try {
                $rows = DB::connection('pgsql_secondary')->table('pending_operations')->where('status', 'pending')->limit(200)->get();
                foreach ($rows as $r) {
                    $payload = json_decode($r->payload, true) ?: [];
                    $userId = $payload['_user_id'] ?? null;
                    unset($payload['_user_id']);
                    PendingOperation::firstOrCreate(['idempotency_key' => $r->idempotency_key], [
                        'uuid' => $r->uuid, 'user_id' => $userId, 'type' => $r->type, 'payload' => $payload, 'last_error' => $r->last_error,
                    ]);
                    DB::connection('pgsql_secondary')->table('pending_operations')->where('id', $r->id)->update(['status' => 'completed']);
                    $count++;
                }
            } catch (Throwable) {
                // secondary unreachable; try again next run
            }
        }

        foreach ($this->storage->readJournal() as ['store' => $store, 'key' => $key, 'entry' => $e]) {
            try {
                PendingOperation::firstOrCreate(['idempotency_key' => $e['key']], [
                    'uuid' => (string) Str::uuid(), 'user_id' => $e['user_id'] ?? null, 'type' => $e['type'],
                    'payload' => $e['payload'] ?? [], 'last_error' => $e['error'] ?? null,
                ]);
                // Remove the journal object only after it is safely in the primary database.
                $store->delete($key);
                $count++;
            } catch (Throwable) {
                // primary still unavailable or store error: keep the journal object for the next run
            }
        }

        return $count;
    }

    /** Photos that were reserved but never confirmed (e.g. the database dropped mid-upload) older than an hour. */
    private function cleanStalePending(): int
    {
        $n = 0;
        StoredFile::where('status', StoredFile::PENDING)->where('created_at', '<', now()->subHour())->with('replicas')->each(function (StoredFile $f) use (&$n) {
            try {
                foreach ($this->storage->configured() as $store) {
                    $store->delete($f->object_key);
                }
                DB::transaction(function () use ($f) {
                    $f->update(['status' => StoredFile::FAILED, 'failure_reason' => 'Upload was not confirmed; cleaned up.']);
                    if ($f->user) {
                        $this->quota->release($f->user, $f->size_bytes);
                    }
                    Scan::where('stored_file_id', $f->id)->where('status', Scan::PENDING)->update(['status' => Scan::FAILED, 'failure_category' => 'upload_unconfirmed']);
                });
                $n++;
            } catch (Throwable) {
                // leave for the next run
            }
        });

        return $n;
    }
}
