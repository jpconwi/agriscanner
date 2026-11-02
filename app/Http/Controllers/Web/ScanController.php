<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScanRequest;
use App\Http\Resources\ScanResource;
use App\Models\Crop;
use App\Models\Scan;
use App\Services\Ai\Exceptions\InvalidImageException;
use App\Services\Quota\QuotaService;
use App\Services\Scans\ScanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ScanController extends Controller
{
    private const WITH = ['crop', 'file.replicas', 'result.disease', 'result.crop'];

    public function create(Request $request, QuotaService $quota)
    {
        return view('scans.create', [
            'crops' => Crop::orderBy('name')->get(['id', 'name']),
            'usage' => $quota->usageFor($request->user()),
            'maxMb' => round(config('agriscan.uploads.max_kb') / 1024, 1),
        ]);
    }

    public function store(StoreScanRequest $request, ScanService $service): RedirectResponse
    {
        try {
            $scan = $service->create($request->user(), $request->file('image'), $request->integer('crop_id') ?: null, $request->validated('idempotency_key'));
        } catch (InvalidImageException $e) {
            // The photo itself is the problem: drop the retry key so the next upload starts a fresh scan.
            return back()->withInput($request->except('idempotency_key'))->with('error', $e->getMessage());
        }
        // Other failures (quota, storage, AI outage, database outage) are turned into a flash message by the global handler,
        // keeping the idempotency key so a retry of the same photo never double-charges quota.

        return redirect()->route('scans.show', $scan->uuid)->with('status', 'Scan complete.');
    }

    public function index(Request $request)
    {
        $scans = $request->user()->scans()->with(self::WITH)->latest('id')->paginate(12);
        $scans->through(fn (Scan $s) => (new ScanResource($s))->resolve());

        return view('scans.index', ['scans' => $scans]);
    }

    public function show(Request $request, string $id)
    {
        return view('scans.show', ['scan' => (new ScanResource($this->find($request, $id)))->resolve()]);
    }

    public function destroy(Request $request, string $id, ScanService $service): RedirectResponse
    {
        $scan = $this->find($request, $id);
        Gate::authorize('delete', $scan);
        $service->deleteScan($request->user(), $scan);

        return redirect()->route('scans.index')->with('status', 'Scan deleted and storage freed.');
    }

    /** Scoped to the signed-in user: someone else's id is a plain 404 (no existence leak). */
    private function find(Request $request, string $id): Scan
    {
        $scan = $request->user()->scans()->with(self::WITH)->where('uuid', $id)->firstOrFail();
        Gate::authorize('view', $scan);

        return $scan;
    }
}
