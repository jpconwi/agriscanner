<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveAdvisoryRequest;
use App\Http\Resources\AdvisoryResource;
use App\Models\Advisory;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvisoryController extends Controller
{
    public function index()
    {
        return AdvisoryResource::collection(Advisory::latest('id')->paginate(20));
    }

    public function store(SaveAdvisoryRequest $request): JsonResponse
    {
        $a = new Advisory($request->validated());
        $a->author_id = $request->user()->id;
        $a->published_at = $a->is_published ? now() : null;
        $a->save();
        AuditLog::record($request->user()->id, 'admin.advisory_created', 'advisory', $a->id);

        return ApiResponse::ok(new AdvisoryResource($a), 'Advisory saved.', 201);
    }

    public function update(SaveAdvisoryRequest $request, Advisory $advisory): JsonResponse
    {
        $advisory->fill($request->validated());
        if ($advisory->is_published && ! $advisory->published_at) {
            $advisory->published_at = now();
        } elseif (! $advisory->is_published) {
            $advisory->published_at = null;
        }
        $advisory->save();
        AuditLog::record($request->user()->id, 'admin.advisory_updated', 'advisory', $advisory->id);

        return ApiResponse::ok(new AdvisoryResource($advisory), 'Advisory saved.');
    }

    public function destroy(Request $request, Advisory $advisory): JsonResponse
    {
        $advisory->delete();
        AuditLog::record($request->user()->id, 'admin.advisory_deleted', 'advisory', $advisory->id);

        return ApiResponse::ok(null, 'Advisory deleted.');
    }
}
