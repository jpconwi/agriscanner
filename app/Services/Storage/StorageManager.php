<?php

namespace App\Services\Storage;

use App\Models\PendingOperation;
use App\Models\StoredFile;
use App\Models\StoredFileReplica;
use App\Services\Storage\Contracts\ObjectStore;
use App\Services\Storage\Exceptions\StorageUnavailableException;
use App\Services\Storage\Exceptions\StoreException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Chooses between stores (primary -> secondary), verifies writes, and handles replica deletion.
 * Never claims success unless the object is confirmed present with the expected size.
 */
class StorageManager
{
    /** @param array<string, ObjectStore> $stores keyed by provider name, in priority order */
    public function __construct(
        private readonly array $stores,
        private readonly int $maxRetries = 2,
        private readonly int $backoffMs = 300,
        private readonly bool $replicate = false,
    ) {
    }

    /** @return list<ObjectStore> configured stores in priority order */
    public function configured(): array
    {
        return array_values(array_filter($this->stores, fn (ObjectStore $s) => $s->isConfigured()));
    }

    /** @return array<string, ObjectStore> */
    public function all(): array
    {
        return $this->stores;
    }

    public function store(string $name): ?ObjectStore
    {
        return $this->stores[$name] ?? null;
    }

    public function hasAny(): bool
    {
        return $this->configured() !== [];
    }

    public function keyFor(int $userId, string $uuid, string $extension): string
    {
        return sprintf('users/%d/scans/%s.%s', $userId, $uuid, $extension);
    }

    /**
     * Write + verify on the first store that works. Returns the provider that holds the file.
     *
     * @throws StorageUnavailableException
     */
    public function putVerified(string $key, string $contents, string $mime): ObjectStore
    {
        $stores = $this->configured();
        if ($stores === []) {
            throw StorageUnavailableException::noneConfigured();
        }

        foreach ($stores as $store) {
            try {
                $this->withRetry(function () use ($store, $key, $contents, $mime) {
                    $store->put($key, $contents, $mime);
                    $size = $store->size($key);
                    if ($size !== strlen($contents)) {
                        // Incomplete or missing object: treat as transient so we retry the write.
                        throw new StoreException($store->name().': upload verification failed', true);
                    }
                });

                return $store;
            } catch (StoreException $e) {
                Log::warning('storage.write_failed', ['provider' => $store->name(), 'reason' => $e->getMessage()]);
                $this->bestEffortDelete($store, $key);
            }
        }

        throw StorageUnavailableException::allFailed();
    }

    /** Copy to every other configured store. Failures become pending operations; the file stays usable. */
    public function replicateTo(StoredFile $file, ObjectStore $source, string $contents): void
    {
        if (! $this->replicate) {
            return;
        }
        foreach ($this->configured() as $store) {
            if ($store->name() === $source->name()) {
                continue;
            }
            try {
                $this->withRetry(function () use ($store, $file, $contents) {
                    $store->put($file->object_key, $contents, $file->mime);
                    if ($store->size($file->object_key) !== strlen($contents)) {
                        throw new StoreException('replica verification failed', true);
                    }
                });
                StoredFileReplica::updateOrCreate(
                    ['stored_file_id' => $file->id, 'provider' => $store->name()],
                    ['object_key' => $file->object_key, 'status' => 'available', 'verified_at' => now()],
                );
            } catch (StoreException $e) {
                PendingOperation::firstOrCreate(
                    ['idempotency_key' => 'replicate:'.$file->uuid.':'.$store->name()],
                    ['uuid' => (string) Str::uuid(), 'user_id' => $file->user_id, 'type' => 'replicate_file',
                        'payload' => ['file_uuid' => $file->uuid, 'provider' => $store->name()], 'last_error' => Str::limit($e->getMessage(), 300)],
                );
            }
        }
    }

    /** Read bytes from any available replica (used for replication retries and AI re-analysis). */
    public function read(StoredFile $file): string
    {
        $last = null;
        foreach ($file->replicas()->where('status', 'available')->get() as $replica) {
            $store = $this->store($replica->provider);
            if (! $store?->isConfigured()) {
                continue;
            }
            try {
                return $this->withRetry(fn () => $store->get($replica->object_key));
            } catch (StoreException $e) {
                $last = $e;
            }
        }
        throw StorageUnavailableException::allFailed();
    }

    /** Short-lived signed URL from the first healthy replica, or null if none can sign right now. */
    public function temporaryUrl(StoredFile $file): ?string
    {
        if ($file->status !== StoredFile::AVAILABLE) {
            return null;
        }
        $expires = now()->addMinutes(max(1, (int) config('agriscan.storage.signed_url_minutes', 10)));
        foreach ($file->replicas as $replica) {
            if ($replica->status !== 'available') {
                continue;
            }
            $store = $this->store($replica->provider);
            if (! $store?->isConfigured()) {
                continue;
            }
            try {
                return $store->temporaryUrl($replica->object_key, $expires);
            } catch (StoreException) {
                continue;
            }
        }

        return null;
    }

    /**
     * Delete every replica. Returns true only when ALL replicas are confirmed gone.
     * Partial failure leaves the file in "deleting" state with the remaining replicas recorded,
     * so a retry (or the pending-operation processor) can finish the job idempotently.
     */
    public function deleteEverywhere(StoredFile $file): bool
    {
        $file->loadMissing('replicas');
        $allGone = true;

        foreach ($file->replicas as $replica) {
            if ($replica->status === 'deleted') {
                continue;
            }
            $store = $this->store($replica->provider);
            if (! $store || ! $store->isConfigured()) {
                // Cannot reach this provider now; do not pretend it is deleted.
                $allGone = false;
                continue;
            }
            try {
                $this->withRetry(fn () => $store->delete($replica->object_key));
                $replica->update(['status' => 'deleted']);
            } catch (StoreException $e) {
                $allGone = false;
                Log::warning('storage.delete_failed', ['provider' => $replica->provider, 'reason' => $e->getMessage()]);
            }
        }

        if (! $allGone) {
            DB::transaction(function () use ($file) {
                $file->update(['status' => StoredFile::DELETING]);
                PendingOperation::firstOrCreate(
                    ['idempotency_key' => 'delete:'.$file->uuid],
                    ['uuid' => (string) Str::uuid(), 'user_id' => $file->user_id, 'type' => 'delete_replica',
                        'payload' => ['file_uuid' => $file->uuid], 'last_error' => 'One or more replicas could not be deleted yet.'],
                );
            });
        }

        return $allGone;
    }

    /** Writes a small JSON record used when the database is unreachable (see PendingJournal). */
    public function putJournal(string $name, string $json): bool
    {
        foreach ($this->configured() as $store) {
            try {
                $this->withRetry(fn () => $store->put(config('agriscan.storage.journal_prefix').$name.'.json', $json, 'application/json'));

                return true;
            } catch (StoreException) {
                continue;
            }
        }

        return false;
    }

    /**
     * Journal entries written while the database was unreachable.
     *
     * @return list<array{store: ObjectStore, key: string, entry: array}>
     */
    public function readJournal(int $limit = 100): array
    {
        $out = [];
        foreach ($this->configured() as $store) {
            try {
                foreach ($store->list(config('agriscan.storage.journal_prefix'), $limit) as $key) {
                    $entry = json_decode($store->get($key), true);
                    if (is_array($entry) && isset($entry['type'], $entry['key'])) {
                        $out[] = ['store' => $store, 'key' => $key, 'entry' => $entry];
                    }
                }
            } catch (StoreException) {
                continue;
            }
        }

        return $out;
    }

    private function bestEffortDelete(ObjectStore $store, string $key): void
    {
        try {
            $store->delete($key);
        } catch (StoreException) {
            // An orphan is harmless and is picked up by reconciliation.
        }
    }

    /**
     * @template T
     * @param  callable(): T  $fn
     * @return T
     *
     * @throws StoreException
     */
    private function withRetry(callable $fn): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $fn();
            } catch (StoreException $e) {
                if (! $e->transient || $attempt >= $this->maxRetries) {
                    throw $e;
                }
                Sleep::for(min($this->backoffMs * (2 ** $attempt), 5000) + random_int(0, 100))->milliseconds();
                $attempt++;
            }
        }
    }
}
