<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScanRequest;
use App\Http\Resources\ScanResource;
use App\Models\Scan;
use App\Services\Scans\ScanService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ScanController extends Controller
{
    private const WITH = ['crop', 'file.replicas', 'result.disease', 'result.crop'];

    public function index(Request $request)
    {
        $scans = $request->user()->scans()->with(self::WITH)->latest('id')->paginate(min(50, max(5, (int) $request->query('per_page', 15))));

        return ScanResource::collection($scans);
    }

    public function store(StoreScanRequest $request, ScanService $service): JsonResponse
    {
        $scan = $service->create($request->user(), $request->file('image'), $request->integer('crop_id') ?: null, $request->validated('idempotency_key'));

        return ApiResponse::ok(new ScanResource($scan), 'Scan complete.', 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok(new ScanResource($this->find($request, $id)));
    }

    public function destroy(Request $request, string $id, ScanService $service): JsonResponse
    {
        $scan = $this->find($request, $id);
        Gate::authorize('delete', $scan);
        $service->deleteScan($request->user(), $scan);

        return ApiResponse::ok(null, 'Scan deleted.');
    }

    /** Scoped to the caller: another user's scan id is a plain 404 (no existence leak). */
    private function find(Request $request, string $id): Scan
    {
        $scan = $request->user()->scans()->with(self::WITH)->where('uuid', $id)->firstOrFail();
        Gate::authorize('view', $scan);

        return $scan;
    }
}
