<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Api\Admin\InfrastructureController as ApiInfra;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SettingsRequest;
use App\Models\AuditLog;
use App\Models\PendingOperation;
use App\Services\Health\DatabaseHealthService;
use App\Services\Health\SystemHealthService;
use App\Services\Quota\QuotaService;
use App\Services\Quota\SettingsService;
use App\Services\Scans\PendingProcessor;
use App\Services\Storage\StorageManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InfrastructureController extends Controller
{
    public const TABS = ['ai' => 'AI providers', 'db' => 'Database', 'storage' => 'Image storage', 'pending' => 'Pending & failed', 'settings' => 'Quotas & thresholds', 'logs' => 'Logs & jobs'];

    public function index(Request $request, SystemHealthService $health, DatabaseHealthService $db, SettingsService $settings)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'ai';
        $api = new ApiInfra;
        $data = ['tab' => $tab, 'tabs' => self::TABS, 'checks' => $health->latestChecks()];

        $data += match ($tab) {
            'ai' => ['ai' => $health->aiReport()],
            'db' => ['database' => $api->databaseReport($db)],
            'storage' => ['storage' => $health->storageReport()],
            'pending' => ['pending' => $api->pendingPage()],
            'settings' => ['settings' => $settings->all()],
            'logs' => ['logs' => $api->logsPage(), 'failedJobs' => $api->failedJobs()],
        };

        return view('admin.infrastructure', $data);
    }

    public function runChecks(Request $request, SystemHealthService $health): RedirectResponse
    {
        $health->runAll();
        AuditLog::record($request->user()->id, 'admin.health_checks_run');

        return back()->with('status', 'Health checks completed.');
    }

    public function saveSettings(SettingsRequest $request, SettingsService $settings): RedirectResponse
    {
        $settings->update($request->validated());
        AuditLog::record($request->user()->id, 'admin.settings_updated', null, null, $request->validated());

        return redirect()->route('admin.infrastructure', ['tab' => 'settings'])->with('status', 'Settings saved. New accounts use the new default quota.');
    }

    public function reconcile(Request $request, QuotaService $quota, StorageManager $storage): RedirectResponse
    {
        $r = $quota->reconcile(null, $storage);
        AuditLog::record($request->user()->id, 'admin.storage_reconciled', null, null, $r);

        return back()->with('status', "Reconciled usage: {$r['users_fixed']} accounts corrected, {$r['files_checked']} files verified, {$r['files_flagged']} flagged.");
    }

    public function retryPending(Request $request, PendingOperation $operation, PendingProcessor $processor): RedirectResponse
    {
        $ok = $processor->retryNow($operation);
        AuditLog::record($request->user()->id, 'admin.pending_retried', 'pending_operation', $operation->id, ['ok' => $ok]);

        return back()->with($ok ? 'status' : 'error', $ok ? 'Operation completed.' : 'Retry did not succeed yet; see the last error.');
    }
}
