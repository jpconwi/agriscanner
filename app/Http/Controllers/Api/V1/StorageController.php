<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Quota\QuotaService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorageController extends Controller
{
    public function usage(Request $request, QuotaService $quota): JsonResponse
    {
        return ApiResponse::ok($quota->usageFor($request->user()));
    }

    public function quota(Request $request, QuotaService $quota): JsonResponse
    {
        $u = $quota->usageFor($request->user());

        return ApiResponse::ok(['quota_bytes' => $u['quota_bytes'], 'used_bytes' => $u['used_bytes'], 'remaining_bytes' => $u['remaining_bytes'], 'warning_level' => $u['warning_level']]);
    }
}
