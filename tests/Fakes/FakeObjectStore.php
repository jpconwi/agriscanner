<?php

namespace Tests\Fakes;

use App\Services\Storage\Contracts\ObjectStore;
use App\Services\Storage\Exceptions\StoreException;

/** In-memory object store with switchable failure modes. */
class FakeObjectStore implements ObjectStore
{
    /** @var array<string, string> */
    public array $objects = [];

    public int $putFailures = 0;        // fail the next N put() calls (transient)

    public bool $alwaysFailPut = false;

    public bool $corruptWrites = false; // store truncated bytes so verification fails

    public bool $failDelete = false;

    public bool $configured = true;

    public int $putCalls = 0;

    public function __construct(private readonly string $name = 'r2')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return 'Fake '.$this->name;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function put(string $key, string $contents, string $mime): void
    {
        $this->putCalls++;
        if ($this->alwaysFailPut) {
            throw new StoreException($this->name.': simulated outage', true);
        }
        if ($this->putFailures > 0) {
            $this->putFailures--;
            throw new StoreException($this->name.': simulated transient error', true);
        }
        $this->objects[$key] = $this->corruptWrites ? substr($contents, 0, max(0, strlen($contents) - 1)) : $contents;
    }

    public function size(string $key): ?int
    {
        return isset($this->objects[$key]) ? strlen($this->objects[$key]) : null;
    }

    public function get(string $key): string
    {
        return $this->objects[$key] ?? throw new StoreException($this->name.': not found', false);
    }

    public function delete(string $key): void
    {
        if ($this->failDelete) {
            throw new StoreException($this->name.': simulated delete failure', true);
        }
        unset($this->objects[$key]);
    }

    public function temporaryUrl(string $key, \DateTimeInterface $expiresAt): string
    {
        return 'https://signed.example/'.$this->name.'/'.$key.'?expires='.$expiresAt->getTimestamp();
    }

    public function list(string $prefix, int $limit = 100): array
    {
        return array_slice(array_values(array_filter(array_keys($this->objects), fn ($k) => str_starts_with($k, $prefix))), 0, $limit);
    }

    public function probe(): array
    {
        return ['ok' => ! $this->alwaysFailPut, 'latency_ms' => 1, 'message' => $this->alwaysFailPut ? 'simulated outage' : 'ok'];
    }

    public function providerLimitBytes(): ?int
    {
        return null;
    }
}
