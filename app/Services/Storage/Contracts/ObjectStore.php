<?php

namespace App\Services\Storage\Contracts;

/** One physical object store (Cloudflare R2, another S3-compatible service, or an in-memory fake in tests). */
interface ObjectStore
{
    /** Stable provider identifier saved with each replica, e.g. "r2". */
    public function name(): string;

    public function label(): string;

    /** True when enabled AND all required credentials are present. */
    public function isConfigured(): bool;

    /** @throws \App\Services\Storage\Exceptions\StoreException */
    public function put(string $key, string $contents, string $mime): void;

    /** @return ?int Size in bytes, or null when the object does not exist. @throws \App\Services\Storage\Exceptions\StoreException */
    public function size(string $key): ?int;

    /** @throws \App\Services\Storage\Exceptions\StoreException */
    public function get(string $key): string;

    /** Idempotent: deleting a missing object succeeds. @throws \App\Services\Storage\Exceptions\StoreException */
    public function delete(string $key): void;

    /** Time-limited URL for a private object. @throws \App\Services\Storage\Exceptions\StoreException */
    public function temporaryUrl(string $key, \DateTimeInterface $expiresAt): string;

    /** @return list<string> object keys under a prefix (bounded). @throws \App\Services\Storage\Exceptions\StoreException */
    public function list(string $prefix, int $limit = 100): array;

    /** Cheap round-trip proving credentials and bucket access (write, read, delete a tiny probe object). */
    public function probe(): array;

    public function providerLimitBytes(): ?int;
}
