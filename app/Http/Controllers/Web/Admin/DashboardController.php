<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Api\Admin\DashboardController as ApiDashboard;
use App\Http\Controllers\Controller;
use App\Services\Health\SystemHealthService;

class DashboardController extends Controller
{
    public function __invoke(SystemHealthService $health)
    {
        $data = json_decode((new ApiDashboard)($health)->getContent(), true)['data'];

        return view('admin.dashboard', ['d' => $data]);
    }
}
