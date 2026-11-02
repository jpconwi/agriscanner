<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveCropRequest;
use App\Http\Requests\Admin\SaveDiseaseRequest;
use App\Models\AuditLog;
use App\Models\Crop;
use App\Models\Disease;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReferenceController extends Controller
{
    public function index()
    {
        return view('admin.reference', [
            'crops' => Crop::withCount('diseases')->orderBy('name')->get(),
            'diseases' => Disease::with(['crop', 'symptomRows', 'treatmentRows'])->orderBy('name')->paginate(15),
        ]);
    }

    public function storeCrop(SaveCropRequest $request): RedirectResponse
    {
        $crop = Crop::create($request->validated());
        AuditLog::record($request->user()->id, 'admin.crop_created', 'crop', $crop->id);

        return back()->with('status', 'Crop saved.');
    }

    public function updateCrop(SaveCropRequest $request, Crop $crop): RedirectResponse
    {
        $crop->update($request->validated());
        AuditLog::record($request->user()->id, 'admin.crop_updated', 'crop', $crop->id);

        return back()->with('status', 'Crop saved.');
    }

    public function destroyCrop(Request $request, Crop $crop): RedirectResponse
    {
        if ($crop->diseases()->exists()) {
            return back()->with('error', 'This crop still has diseases. Delete or reassign them first.');
        }
        $crop->delete();
        AuditLog::record($request->user()->id, 'admin.crop_deleted', 'crop', $crop->id);

        return back()->with('status', 'Crop deleted.');
    }

    public function storeDisease(SaveDiseaseRequest $request): RedirectResponse
    {
        $d = (new Disease)->saveWithReference($request->validated());
        AuditLog::record($request->user()->id, 'admin.disease_created', 'disease', $d->id);

        return back()->with('status', 'Disease saved.');
    }

    public function updateDisease(SaveDiseaseRequest $request, Disease $disease): RedirectResponse
    {
        $disease->saveWithReference($request->validated());
        AuditLog::record($request->user()->id, 'admin.disease_updated', 'disease', $disease->id);

        return back()->with('status', 'Disease saved.');
    }

    public function destroyDisease(Request $request, Disease $disease): RedirectResponse
    {
        // Past scans keep their own recommendation snapshot, so deleting is safe.
        $disease->delete();
        AuditLog::record($request->user()->id, 'admin.disease_deleted', 'disease', $disease->id);

        return back()->with('status', 'Disease deleted.');
    }
}
