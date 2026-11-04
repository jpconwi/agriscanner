<?php

namespace App\Services\Storage;

use App\Services\Storage\Contracts\ObjectStore;
use App\Services\Storage\Exceptions\StoreException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cloudinary as an object store, over its REST APIs (no SDK, no extra package).
 *
 * - Every asset is uploaded with type=authenticated, so it has NO public URL; it can only be read with a signed request.
 * - Images use the "image" resource type (public_id = key without extension). Anything else (journal JSON) is stored as "raw".
 * - size() asks the Admin API, so an upload is only "verified" when Cloudinary itself reports the right byte count.
 * - Free-plan note: the Admin API is rate limited (hundreds of calls per hour). A 420/429 is treated as transient.
 */
class CloudinaryObjectStore implements ObjectStore
{
    private const API = 'https://api.cloudinary.com/v1_1';

    private const IMAGE_EXT = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp', 'gif' => 'gif'];

    /** A valid 1x1 PNG used for the health probe, so the probe exercises the real image path. */
    private const PROBE_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==';

    public function __construct(private readonly string $name, private readonly array $config)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->config['label'] ?? 'Cloudinary';
    }

    public function isConfigured(): bool
    {
        return ($this->config['enabled'] ?? false)
            && filled($this->config['cloud_name'] ?? null)
            && filled($this->config['api_key'] ?? null)
            && filled($this->config['api_secret'] ?? null);
    }

    public function providerLimitBytes(): ?int
    {
        return $this->config['provider_limit_bytes'] ?? null;
    }

    // ---- key mapping -------------------------------------------------------------------------------

    private function folder(): string
    {
        $f = trim((string) ($this->config['folder'] ?? 'agriscan'), '/');

        return $f === '' ? '' : $f.'/';
    }

    /** @return array{0: string, 1: string, 2: ?string} [resource_type, public_id, format] */
    private function map(string $key): array
    {
        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        if (isset(self::IMAGE_EXT[$ext])) {
            return ['image', $this->folder().substr($key, 0, -(strlen($ext) + 1)), self::IMAGE_EXT[$ext]];
        }

        return ['raw', $this->folder().$key, null];
    }

    // ---- signing & http ----------------------------------------------------------------------------

    /** Cloudinary signature: sorted "k=v" pairs joined by "&", API secret appended, SHA-1 hex. */
    public function sign(array $params): string
    {
        $params = array_filter($params, fn ($v, $k) => $v !== null && $v !== '' && ! in_array($k, ['file', 'cloud_name', 'resource_type', 'api_key', 'signature'], true), ARRAY_FILTER_USE_BOTH);
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = $k.'='.(is_bool($v) ? ($v ? 'true' : 'false') : $v);
        }

        return sha1(implode('&', $pairs).$this->config['api_secret']);
    }

    private function signed(array $params): array
    {
        $params['timestamp'] = time();

        return $params + ['api_key' => $this->config['api_key'], 'signature' => $this->sign($params)];
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) ($this->config['timeout'] ?? 20))->acceptJson();
    }

    private function admin(): PendingRequest
    {
        return $this->http()->withBasicAuth($this->config['api_key'], $this->config['api_secret']);
    }

    private function url(string $path): string
    {
        return self::API.'/'.$this->config['cloud_name'].'/'.ltrim($path, '/');
    }

    private function fail(Response $r, string $what): StoreException
    {
        $status = $r->status();
        $detail = Str::limit((string) ($r->json('error.message') ?? ''), 140);
        $transient = $status === 408 || $status === 420 || $status === 429 || $status >= 500;
        $hint = match (true) {
            $status === 401 || $status === 403 => 'check CLOUDINARY_API_KEY / CLOUDINARY_API_SECRET',
            $status === 420 || $status === 429 => 'rate limited',
            default => $detail,
        };

        return new StoreException($this->name.': '.$what.' failed (HTTP '.$status.($hint !== '' ? ', '.$hint : '').')', $transient);
    }

    private function wrap(Throwable $e): StoreException
    {
        if ($e instanceof StoreException) {
            return $e;
        }

        return new StoreException($this->name.': '.Str::limit(preg_replace('/https?:\/\/\S+/', '[url]', $e->getMessage()) ?? 'connection error', 160), true, $e);
    }

    // ---- ObjectStore -------------------------------------------------------------------------------

    public function put(string $key, string $contents, string $mime): void
    {
        [$type, $publicId] = $this->map($key);
        try {
            $fields = $this->signed(['public_id' => $publicId, 'type' => 'authenticated', 'overwrite' => 'true']);
            $r = $this->http()->attach('file', $contents, basename($key))->post($this->url($type.'/upload'), $fields);
            if (! $r->successful()) {
                throw $this->fail($r, 'upload');
            }
            if ((int) $r->json('bytes') !== strlen($contents)) {
                throw new StoreException($this->name.': upload verification failed (size mismatch)', true);
            }
        } catch (Throwable $e) {
            throw $this->wrap($e);
        }
    }

    public function size(string $key): ?int
    {
        [$type, $publicId] = $this->map($key);
        try {
            $r = $this->admin()->get($this->url("resources/{$type}/authenticated/".$publicId));
            if ($r->status() === 404) {
                return null;
            }
            if (! $r->successful()) {
                throw $this->fail($r, 'lookup');
            }

            return (int) $r->json('bytes');
        } catch (Throwable $e) {
            throw $this->wrap($e);
        }
    }

    public function get(string $key): string
    {
        try {
            $r = $this->http()->withOptions(['allow_redirects' => true])->get($this->downloadUrl($key, time() + 300));
            if (! $r->successful()) {
                throw $this->fail($r, 'download');
            }

            return $r->body();
        } catch (Throwable $e) {
            throw $this->wrap($e);
        }
    }

    public function delete(string $key): void
    {
        [$type, $publicId] = $this->map($key);
        try {
            $r = $this->http()->asForm()->post($this->url($type.'/destroy'), $this->signed(['public_id' => $publicId, 'type' => 'authenticated']));
            // "not found" is success: deleting a missing object is idempotent.
            if (! $r->successful() && $r->status() !== 404) {
                throw $this->fail($r, 'delete');
            }
        } catch (Throwable $e) {
            throw $this->wrap($e);
        }
    }

    public function temporaryUrl(string $key, \DateTimeInterface $expiresAt): string
    {
        return $this->downloadUrl($key, $expiresAt->getTimestamp());
    }

    /** Signed, expiring "private download" URL for an authenticated asset. The API secret is never part of the URL. */
    private function downloadUrl(string $key, int $expiresAt): string
    {
        [$type, $publicId, $format] = $this->map($key);
        $params = $this->signed(array_filter([
            'public_id' => $publicId, 'format' => $format, 'type' => 'authenticated', 'expires_at' => $expiresAt,
        ], fn ($v) => $v !== null));

        return $this->url($type.'/download').'?'.http_build_query($params);
    }

    public function list(string $prefix, int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        $out = [];
        try {
            foreach (['image', 'raw'] as $type) {
                $r = $this->admin()->get($this->url("resources/{$type}/authenticated"), ['prefix' => $this->folder().$prefix, 'max_results' => $limit]);
                if (! $r->successful()) {
                    throw $this->fail($r, 'listing');
                }
                foreach ($r->json('resources') ?? [] as $row) {
                    $id = (string) ($row['public_id'] ?? '');
                    $key = Str::after($id, $this->folder());
                    $out[] = $type === 'image' ? $key.'.'.($row['format'] ?? 'jpg') : $key;
                }
            }

            return array_slice($out, 0, $limit);
        } catch (Throwable $e) {
            throw $this->wrap($e);
        }
    }

    public function probe(): array
    {
        $key = 'health/'.Str::uuid().'.png';
        $png = base64_decode(self::PROBE_PNG);
        $started = microtime(true);
        $ms = fn () => (int) round((microtime(true) - $started) * 1000);
        try {
            $this->put($key, $png, 'image/png');
            $ok = $this->size($key) === strlen($png);
            $this->delete($key);

            return ['ok' => $ok, 'latency_ms' => $ms(), 'message' => $ok ? 'Upload, verification and deletion confirmed.' : 'Round trip returned unexpected data.'];
        } catch (StoreException $e) {
            try {
                $this->delete($key);
            } catch (Throwable) {
                // best effort cleanup
            }

            return ['ok' => false, 'latency_ms' => $ms(), 'message' => $e->getMessage()];
        }
    }
}
