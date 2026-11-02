<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScanResource;
use App\Models\Scan;
use App\Models\ScanResult;
use App\Services\Quota\QuotaService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request, QuotaService $quota): JsonResponse
    {
        $user = $request->user();
        $completed = fn () => ScanResult::whereHas('scan', fn ($q) => $q->where('user_id', $user->id)->where('status', Scan::COMPLETED));

        $weekly = collect(range(7, 0))->map(function (int $w) use ($user) {
            $from = now()->startOfWeek()->subWeeks($w);

            return ['label' => $from->format('M j'), 'count' => Scan::where('user_id', $user->id)->whereBetween('created_at', [$from, (clone $from)->addWeek()])->count()];
        })->values();

        $byDisease = ScanResult::query()
            ->join('scans', 'scans.id', '=', 'scan_results.scan_id')
            ->join('diseases', 'diseases.id', '=', 'scan_results.disease_id')
            ->where('scans.user_id', $user->id)
            ->groupBy('diseases.name')->orderByDesc(DB::raw('count(*)'))->limit(6)
            ->get(['diseases.name', DB::raw('count(*) as count')])
            ->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->count]);

        $recent = $user->scans()->with(['crop', 'file.replicas', 'result.disease', 'result.crop'])->latest('id')->limit(5)->get();

        return ApiResponse::ok([
            'total_scans' => $user->scans()->count(),
            'healthy_scans' => $completed()->where('is_healthy', true)->count(),
            'issue_scans' => $completed()->where('is_healthy', false)->count(),
            'storage' => $quota->usageFor($user),
            'weekly' => $weekly,
            'by_disease' => $byDisease,
            'recent' => ScanResource::collection($recent)->resolve(),
        ]);
    }
}
