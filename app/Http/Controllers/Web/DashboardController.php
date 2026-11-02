<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\V1\DashboardController as ApiDashboard;
use App\Http\Controllers\Controller;
use App\Services\Quota\QuotaService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, QuotaService $quota)
    {
        // Same numbers as GET /api/v1/dashboard: one implementation, two front doors.
        $data = json_decode((new ApiDashboard)($request, $quota)->getContent(), true)['data'];

        return view('dashboard', ['d' => $data]);
    }
}
