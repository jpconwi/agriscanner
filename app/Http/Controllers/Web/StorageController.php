<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Quota\QuotaService;
use Illuminate\Http\Request;

class StorageController extends Controller
{
    public function __invoke(Request $request, QuotaService $quota)
    {
        return view('storage', ['u' => $quota->usageFor($request->user())]);
    }
}
