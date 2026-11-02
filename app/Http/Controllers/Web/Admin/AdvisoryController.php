<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveAdvisoryRequest;
use App\Models\Advisory;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdvisoryController extends Controller
{
    public function index()
    {
        return view('admin.advisories', ['advisories' => Advisory::latest('id')->paginate(15)]);
    }

    public function store(SaveAdvisoryRequest $request): RedirectResponse
    {
        $a = new Advisory($request->validated());
        $a->author_id = $request->user()->id;
        $a->published_at = $a->is_published ? now() : null;
        $a->save();
        AuditLog::record($request->user()->id, 'admin.advisory_created', 'advisory', $a->id);

        return back()->with('status', 'Advisory saved.');
    }

    public function update(SaveAdvisoryRequest $request, Advisory $advisory): RedirectResponse
    {
        $advisory->fill($request->validated());
        $advisory->published_at = $advisory->is_published ? ($advisory->published_at ?? now()) : null;
        $advisory->save();
        AuditLog::record($request->user()->id, 'admin.advisory_updated', 'advisory', $advisory->id);

        return back()->with('status', 'Advisory saved.');
    }

    public function destroy(Request $request, Advisory $advisory): RedirectResponse
    {
        $advisory->delete();
        AuditLog::record($request->user()->id, 'admin.advisory_deleted', 'advisory', $advisory->id);

        return back()->with('status', 'Advisory deleted.');
    }
}
