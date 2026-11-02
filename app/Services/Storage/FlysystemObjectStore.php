<?php

namespace App\Services\Storage;

use App\Services\Storage\Contracts\ObjectStore;
use App\Services\Storage\Exceptions\StoreException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Wraps an S3-compatible disk (Cloudflare R2 or any other). Buckets must be private. */
class FlysystemObjectStore implements ObjectStore
{
    private ?Filesystem $disk = null;

    public function __construct(private readonly string $name, private readonly array $config)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->config['label'] ?? $this->name;
    }

    public function isConfigured(): bool
    {
        return ($this->config['enabled'] ?? false)
            && filled($this->config['key'] ?? null)
            && filled($this->config['secret'] ?? null)
            && filled($this->config['bucket'] ?? null)
            && filled($this->config['endpoint'] ?? null);
    }

    public function providerLimitBytes(): ?int
    {
        return $this->config['provider_limit_bytes'] ?? null;
    }

    private function disk(): Filesystem
    {
        // Built lazily from config; never registered as a global disk and never "local".
        return $this->disk ??= Storage::build([
            'driver' => 's3',
            'key' => $this->config['key'],
            'secret' => $this->config['secret'],
            'region' => $this->config['region'] ?? 'auto',
            'bucket' => $this->config['bucket'],
            'endpoint' => $this->config['endpoint'],
            'use_path_style_endpoint' => $this->config['use_path_style_endpoint'] ?? true,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ]);
    }

    private function wrap(Throwable $e): StoreException
    {
        // Messages from the SDK can echo endpoints; keep only a sanitized summary.
        $msg = Str::limit(preg_replace('/https?:\/\/\S+/', '[url]', $e->getMessage()) ?? 'storage error', 180);
        $transient = ! str_contains(strtolower($e->getMessage()), 'accessdenied')
            && ! str_contains(strtolower($e->getMessage()), 'invalidaccesskey')
            && ! str_contains(strtolower($e->getMessage()), 'nosuchbucket')
            && ! str_contains(strtolower($e->getMessage()), 'signaturedoesnotmatch');

        return new StoreException($this->name.': '.$msg, $transient, $e);
    }

    public function put(string $key, string $contents, string $mime): void
    {
        try {
            $this->disk()->put($key, $contents, ['visibility' => 'private', 'mimetype' => $mime, 'ContentType' => $mime]);
        } catch (Throwable $e) {
            throw $this->wrap($e);
        }
    }

    public function size(string $key): ?int
    {
        try {
            return $this->disk()->exists($key) ? (int) $this->disk()->size($key) : null;
        } catch (Throwable $e) {
            throw $this->wrap($e);
        }
    }

    public function get(string $key): string
    {
        try {
            return (string) $this->disk()->get($key);
        } catch (Throwable $e) {
            throw $this->wrap($e);
        }
    }

    public function delete(string $key): void
    {
        try {
            $this->disk()->delete($key);
        } catch (Throwable $e) {
            throw $this->wrap($e);
        }
    }

    public function temporaryUrl(string $key, \DateTimeInterface $expiresAt): string
    {
        try {
            return $this->disk()->temporaryUrl($key, $expiresAt);
        } catch (Throwable $e) {
            throw $this->wrap($e);
        }
    }

    public function list(string $prefix, int $limit = 100): array
    {
        try {
            return array_slice(array_values($this->disk()->files($prefix)), 0, $limit);
        } catch (Throwable $e) {
            throw $this->wrap($e);
        }
    }

    public function probe(): array
    {
        $key = 'health/'.Str::uuid().'.txt';
        $payload = 'agriscan-probe';
        $started = microtime(true);
        try {
            $this->put($key, $payload, 'text/plain');
            $ok = $this->get($key) === $payload && $this->size($key) === strlen($payload);
            $this->delete($key);

            return ['ok' => $ok, 'latency_ms' => (int) round((microtime(true) - $started) * 1000), 'message' => $ok ? 'Upload, retrieval and deletion verified.' : 'Round trip returned unexpected data.'];
        } catch (StoreException $e) {
            return ['ok' => false, 'latency_ms' => (int) round((microtime(true) - $started) * 1000), 'message' => $e->getMessage()];
        }
    }
}
