<?php

namespace App\Services\Health;

use App\Models\AiProviderLog;
use App\Models\PendingOperation;
use App\Models\StoredFile;
use App\Models\StoredFileReplica;
use App\Models\SystemHealthCheck;
use App\Services\Ai\AiAnalysisService;
use App\Services\Storage\StorageManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Runs and records health checks, and builds the admin monitoring views. */
class SystemHealthService
{
    public function __construct(
        private readonly DatabaseHealthService $db,
        private readonly StorageManager $storage,
        private readonly AiAnalysisService $ai,
    ) {
    }

    /** Runs every check and stores the results. Returns the fresh rows. */
    public function runAll(bool $probeExternal = true): array
    {
        $this->db->check('pgsql');
        if (config('agriscan.database.secondary_enabled')) {
            $this->db->check('pgsql_secondary');
        }

        foreach ($this->storage->all() as $store) {
            if (! $store->isConfigured()) {
                $this->save('storage_'.$store->name(), 'unconfigured', null, 'Not configured.');

                continue;
            }
            if (! $probeExternal) {
                continue;
            }
            $p = $store->probe();
            $this->save('storage_'.$store->name(), $p['ok'] ? 'healthy' : 'down', $p['latency_ms'], $p['message']);
        }

        foreach ($this->ai->all() as $provider) {
            if (! $provider->isConfigured()) {
                $this->save('ai_'.$provider->name(), 'unconfigured', null, 'Not configured or not authorised.');

                continue;
            }
            if (! $probeExternal) {
                continue;
            }
            $v = $provider->verify();
            $this->save('ai_'.$provider->name(), $v['status'] ?? ($v['ok'] ? 'healthy' : 'down'), null, $v['message'] ?? null);
        }

        return $this->latestChecks();
    }

    private function save(string $service, string $status, ?int $latency, ?string $message): void
    {
        try {
            SystemHealthCheck::create(['service' => $service, 'status' => $status, 'latency_ms' => $latency, 'message' => $message ? Str::limit($message, 480) : null, 'checked_at' => now()]);
        } catch (Throwable) {
            // primary database unavailable: nothing to persist to
        }
    }

    /** @return list<array<string, mixed>> newest check per service */
    public function latestChecks(): array
    {
        $ids = SystemHealthCheck::query()->select(DB::raw('max(id) as id'))->groupBy('service')->pluck('id');

        return SystemHealthCheck::whereIn('id', $ids)->orderBy('service')->get()
            ->map(fn ($c) => ['service' => $c->service, 'status' => $c->status, 'message' => $c->message, 'latency_ms' => $c->latency_ms, 'checked_at' => $c->checked_at?->toISOString()])
            ->all();
    }

    /** @return array<string, mixed> */
    public function aiReport(): array
    {
        $since = now()->subDay();
        $providers = [];
        foreach ($this->ai->all() as $p) {
            $logs = AiProviderLog::where('provider', $p->name())->where('created_at', '>=', $since);
            $total = (clone $logs)->count();
            $ok = (clone $logs)->where('outcome', 'success')->count();
            $last = $this->latestStatus('ai_'.$p->name());
            $providers[] = [
                'name' => $p->name(),
                'model' => $p->model(),
                'configured' => $p->isConfigured(),
                'status' => $p->isConfigured() ? ($last ?? 'unknown') : 'unconfigured',
                'requests_24h' => $total,
                'success_rate' => $total > 0 ? round($ok / $total, 3) : null,
                'avg_latency_ms' => $ok > 0 ? (int) round((clone $logs)->where('outcome', 'success')->avg('latency_ms')) : null,
                'last_error_category' => AiProviderLog::where('provider', $p->name())->where('outcome', 'failure')->latest('created_at')->value('error_category'),
                'last_success_at' => AiProviderLog::where('provider', $p->name())->where('outcome', 'success')->latest('created_at')->value('created_at'),
            ];
        }

        return ['chain' => array_map(fn ($p) => $p->name(), $this->ai->chain()), 'providers' => $providers];
    }

    /** @return array<string, mixed> */
    public function storageReport(): array
    {
        $providers = [];
        foreach ($this->storage->all() as $s) {
            $q = StoredFileReplica::where('provider', $s->name())->where('status', 'available');
            $bytes = (int) StoredFile::whereIn('id', (clone $q)->select('stored_file_id'))->sum('size_bytes');
            $providers[] = [
                'name' => $s->name(), 'label' => $s->label(), 'configured' => $s->isConfigured(),
                'status' => $s->isConfigured() ? ($this->latestStatus('storage_'.$s->name()) ?? 'unknown') : 'unconfigured',
                'object_count' => (clone $q)->count(), 'bytes' => $bytes, 'provider_limit_bytes' => $s->providerLimitBytes(),
            ];
        }

        return [
            'providers' => $providers,
            'logical_bytes' => (int) StoredFile::where('status', StoredFile::AVAILABLE)->sum('size_bytes'),
            'files_available' => StoredFile::where('status', StoredFile::AVAILABLE)->count(),
            'files_pending' => StoredFile::where('status', StoredFile::PENDING)->count(),
            'files_failed' => StoredFile::where('status', StoredFile::FAILED)->count(),
            'pending_operations' => PendingOperation::whereIn('status', ['pending', 'processing', 'failed'])->count(),
        ];
    }

    private function latestStatus(string $service): ?string
    {
        return SystemHealthCheck::where('service', $service)->latest('checked_at')->value('status');
    }
}
