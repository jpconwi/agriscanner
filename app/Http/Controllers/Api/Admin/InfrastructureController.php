<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PendingOperation;
use App\Models\SystemHealthCheck;
use App\Http\Requests\Admin\SettingsRequest;
use App\Services\Health\DatabaseHealthService;
use App\Services\Health\SystemHealthService;
use App\Services\Quota\QuotaService;
use App\Services\Quota\SettingsService;
use App\Services\Scans\PendingProcessor;
use App\Services\Storage\StorageManager;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InfrastructureController extends Controller
{
    public function aiHealth(SystemHealthService $health): JsonResponse
    {
        return ApiResponse::ok($health->aiReport());
    }

    public function storage(SystemHealthService $health): JsonResponse
    {
        return ApiResponse::ok($health->storageReport());
    }

    public function databaseHealth(DatabaseHealthService $db): JsonResponse
    {
        return ApiResponse::ok($this->databaseReport($db));
    }

    /** @return array{primary: array, secondary: array} */
    public function databaseReport(DatabaseHealthService $db): array
    {
        $decorate = function (array $x, string $service) use ($db) {
            if (! ($x['configured'] ?? true)) {
                return $x;
            }

            return $x + [
                'last_success_at' => $db->lastSuccess($service),
                'errors_24h' => SystemHealthCheck::where('service', $service)->where('status', 'down')->where('checked_at', '>=', now()->subDay())->count(),
            ];
        };

        return [
            'primary' => $decorate($db->check('pgsql'), 'database_primary'),
            'secondary' => $decorate($db->check('pgsql_secondary'), 'database_secondary'),
        ];
    }

    public function systemHealth(SystemHealthService $health): JsonResponse
    {
        return ApiResponse::ok(['checks' => $health->latestChecks(), 'failed_jobs' => $this->failedJobs()]);
    }

    public function failedJobs(): \Illuminate\Support\Collection
    {
        return Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->latest('id')->limit(20)->get(['id', 'queue', 'failed_at', 'exception'])
                ->map(fn ($j) => ['id' => $j->id, 'queue' => $j->queue, 'failed_at' => $j->failed_at, 'summary' => Str::limit(strtok((string) $j->exception, "\n") ?: '', 160)])
            : collect();
    }

    public function runChecks(Request $request, SystemHealthService $health): JsonResponse
    {
        $checks = $health->runAll();
        AuditLog::record($request->user()->id, 'admin.health_checks_run');

        return ApiResponse::ok(['checks' => $checks], 'Health checks completed.');
    }

    public function settings(SettingsService $settings): JsonResponse
    {
        return ApiResponse::ok($settings->all());
    }

    public function saveSettings(SettingsRequest $request, SettingsService $settings): JsonResponse
    {
        $out = $settings->update($request->validated());
        AuditLog::record($request->user()->id, 'admin.settings_updated', null, null, $request->validated());

        return ApiResponse::ok($out, 'Settings saved.');
    }

    public function pending(Request $request)
    {
        return ApiResponse::paginated($this->pendingPage());
    }

    public function pendingPage(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $page = PendingOperation::with('user:id,email')->latest('id')->paginate(20)->withQueryString();
        $page->getCollection()->transform(fn ($o) => [
            'id' => $o->id, 'type' => $o->type, 'status' => $o->status, 'attempts' => $o->attempts,
            'last_error' => $o->last_error, 'created_at' => $o->created_at?->toISOString(), 'user_email' => $o->user?->email,
        ]);

        return $page;
    }

    public function retryPending(Request $request, PendingOperation $operation, PendingProcessor $processor): JsonResponse
    {
        $ok = $processor->retryNow($operation);
        AuditLog::record($request->user()->id, 'admin.pending_retried', 'pending_operation', $operation->id, ['ok' => $ok]);

        return ApiResponse::ok(['completed' => $ok], $ok ? 'Operation completed.' : 'Retry did not succeed yet; see the last error.');
    }

    public function reconcile(Request $request, QuotaService $quota, StorageManager $storage): JsonResponse
    {
        $r = $quota->reconcile(null, $storage);
        AuditLog::record($request->user()->id, 'admin.storage_reconciled', null, null, $r);

        return ApiResponse::ok($r, "Reconciled usage for all accounts: {$r['users_fixed']} corrected, {$r['files_checked']} files verified, {$r['files_flagged']} flagged.");
    }

    public function logs()
    {
        return ApiResponse::paginated($this->logsPage());
    }

    public function logsPage(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $page = \App\Models\AuditLog::with('user:id,email')->latest('id')->paginate(30)->withQueryString();
        $page->getCollection()->transform(fn ($l) => [
            'id' => $l->id, 'created_at' => $l->created_at?->toISOString(), 'actor' => $l->user?->email,
            'action' => $l->action, 'target' => $l->target_type ? $l->target_type.'#'.$l->target_id : null,
        ]);

        return $page;
    }
}
