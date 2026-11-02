<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PendingOperation;
use App\Models\Scan;
use App\Models\ScanResult;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Health\SystemHealthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function __invoke(SystemHealthService $health): JsonResponse
    {
        $byDay = collect(range(13, 0))->map(function (int $d) {
            $day = now()->startOfDay()->subDays($d);

            return ['label' => $day->format('M j'), 'count' => Scan::whereBetween('created_at', [$day, (clone $day)->addDay()])->count()];
        })->values();

        $top = ScanResult::query()->join('diseases', 'diseases.id', '=', 'scan_results.disease_id')
            ->groupBy('diseases.name')->orderByDesc(DB::raw('count(*)'))->limit(8)
            ->get(['diseases.name', DB::raw('count(*) as count')])->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->count]);

        return ApiResponse::ok([
            'users_total' => User::count(),
            'users_new_7d' => User::where('created_at', '>=', now()->subDays(7))->count(),
            'scans_total' => Scan::count(),
            'scans_7d' => Scan::where('created_at', '>=', now()->subDays(7))->count(),
            'scans_failed' => Scan::where('status', Scan::FAILED)->count(),
            // Logical bytes: each photo counted once regardless of replicas.
            'storage_used_bytes' => (int) StoredFile::where('status', StoredFile::AVAILABLE)->sum('size_bytes'),
            'pending_operations' => PendingOperation::whereIn('status', ['pending', 'processing', 'failed'])->count(),
            'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            'scans_by_day' => $byDay,
            'top_diseases' => $top,
            'services' => $health->latestChecks(),
        ]);
    }
}
