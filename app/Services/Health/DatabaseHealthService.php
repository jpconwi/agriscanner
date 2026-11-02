<?php

namespace App\Services\Health;

use App\Models\SystemHealthCheck;
use App\Services\Quota\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Probes a database connection. Capacity is only reported when it is genuinely known
 * (an operator-supplied limit or the Neon API); otherwise it is null and shown as "Unknown".
 */
class DatabaseHealthService
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    /** @return array<string, mixed> */
    public function check(string $connection = 'pgsql', bool $record = true): array
    {
        $role = $connection === 'pgsql' ? 'primary' : 'secondary';
        $secondary = $role === 'secondary';

        if ($secondary && ! config('agriscan.database.secondary_enabled')) {
            return ['configured' => false, 'status' => 'unconfigured', 'role' => $role];
        }

        $started = microtime(true);
        try {
            $db = DB::connection($connection);
            $db->selectOne('select 1');
            $latency = (int) round((microtime(true) - $started) * 1000);

            $size = null;
            if ($db->getDriverName() === 'pgsql') {
                $size = (int) ($db->selectOne('select pg_database_size(current_database()) as s')->s ?? 0) ?: null;
            }
            $capacity = $secondary ? null : (config('agriscan.database.capacity_bytes') ?: $this->neonCapacity());
            $percent = ($size !== null && $capacity) ? $size / $capacity * 100 : null;
            $warnAt = $this->settings->get('db_warn_percent');
            $status = ($percent !== null && $percent >= $warnAt) ? 'degraded' : 'healthy';

            $out = [
                'configured' => true, 'role' => $role, 'status' => $status, 'latency_ms' => $latency,
                'size_bytes' => $size, 'capacity_bytes' => $capacity, 'percent_used' => $percent === null ? null : round($percent, 1),
                'warning_level' => $percent === null ? 'ok' : ($percent >= 100 ? 'full' : ($percent >= $warnAt ? 'warning' : 'ok')),
                'last_error' => $percent !== null && $percent >= $warnAt ? 'Database size is at '.round($percent).'% of the configured capacity.' : null,
            ];
        } catch (Throwable $e) {
            $out = [
                'configured' => true, 'role' => $role, 'status' => 'down', 'latency_ms' => null, 'size_bytes' => null,
                'capacity_bytes' => null, 'warning_level' => 'ok',
                // Never include the DSN or credentials.
                'last_error' => Str::limit(preg_replace('/(password|host|user)=\S+/i', '$1=[hidden]', $e->getMessage()) ?? 'connection failed', 200),
            ];
        }

        if ($record) {
            $this->record('database_'.$role, $out);
        }

        return $out;
    }

    private function record(string $service, array $out): void
    {
        try {
            SystemHealthCheck::create([
                'service' => $service, 'status' => $out['status'], 'latency_ms' => $out['latency_ms'] ?? null,
                'message' => $out['last_error'] ?? null,
                'details' => ['size_bytes' => $out['size_bytes'] ?? null, 'capacity_bytes' => $out['capacity_bytes'] ?? null],
                'checked_at' => now(),
            ]);
        } catch (Throwable) {
            // If the primary is down we cannot write to it; the live check result is still returned.
        }
    }

    /** Neon's management API exposes plan limits only for some plans; failure simply means "Unknown". */
    private function neonCapacity(): ?int
    {
        $key = config('agriscan.database.neon.api_key');
        $project = config('agriscan.database.neon.project_id');
        if (! $key || ! $project) {
            return null;
        }
        try {
            $r = Http::withToken($key)->acceptJson()->timeout(8)->get("https://console.neon.tech/api/v2/projects/{$project}");
            $limit = $r->json('project.settings.quota.logical_size_bytes');

            return is_numeric($limit) && $limit > 0 ? (int) $limit : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Most recent stored result per service (for dashboards when a live probe is not wanted). */
    public function lastSuccess(string $service): ?string
    {
        return SystemHealthCheck::where('service', $service)->where('status', 'healthy')->latest('checked_at')->value('checked_at');
    }
}
