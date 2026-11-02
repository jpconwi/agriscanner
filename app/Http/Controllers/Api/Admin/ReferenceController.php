<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveCropRequest;
use App\Http\Requests\Admin\SaveDiseaseRequest;
use App\Http\Resources\DiseaseResource;
use App\Models\AuditLog;
use App\Models\Crop;
use App\Models\Disease;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferenceController extends Controller
{
    public function crops(): JsonResponse
    {
        return ApiResponse::ok(Crop::withCount('diseases')->orderBy('name')->get(['id', 'name', 'scientific_name', 'description']));
    }

    public function storeCrop(SaveCropRequest $request): JsonResponse
    {
        $crop = Crop::create($request->validated());
        AuditLog::record($request->user()->id, 'admin.crop_created', 'crop', $crop->id);

        return ApiResponse::ok($crop, 'Crop saved.', 201);
    }

    public function updateCrop(SaveCropRequest $request, Crop $crop): JsonResponse
    {
        $crop->update($request->validated());
        AuditLog::record($request->user()->id, 'admin.crop_updated', 'crop', $crop->id);

        return ApiResponse::ok($crop, 'Crop saved.');
    }

    public function destroyCrop(Request $request, Crop $crop): JsonResponse
    {
        if ($crop->diseases()->exists()) {
            return ApiResponse::error('This crop still has diseases. Delete or reassign them first.', 422);
        }
        $crop->delete();
        AuditLog::record($request->user()->id, 'admin.crop_deleted', 'crop', $crop->id);

        return ApiResponse::ok(null, 'Crop deleted.');
    }

    public function diseases(Request $request)
    {
        return DiseaseResource::collection(Disease::with(['crop', 'symptomRows', 'treatmentRows'])->orderBy('name')->paginate(20));
    }

    public function storeDisease(SaveDiseaseRequest $request): JsonResponse
    {
        $disease = $this->persist(new Disease, $request);
        AuditLog::record($request->user()->id, 'admin.disease_created', 'disease', $disease->id);

        return ApiResponse::ok(new DiseaseResource($disease->load('crop')), 'Disease saved.', 201);
    }

    public function updateDisease(SaveDiseaseRequest $request, Disease $disease): JsonResponse
    {
        $this->persist($disease, $request);
        AuditLog::record($request->user()->id, 'admin.disease_updated', 'disease', $disease->id);

        return ApiResponse::ok(new DiseaseResource($disease->load('crop')), 'Disease saved.');
    }

    public function destroyDisease(Request $request, Disease $disease): JsonResponse
    {
        // Past scans keep their own recommendation snapshot, so deleting is safe.
        $disease->delete();
        AuditLog::record($request->user()->id, 'admin.disease_deleted', 'disease', $disease->id);

        return ApiResponse::ok(null, 'Disease deleted.');
    }

    private function persist(Disease $disease, $request): Disease
    {
        return $disease->saveWithReference($request->validated());
    }
}
