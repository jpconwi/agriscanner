<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Lets a FREE external cron service (e.g. cron-job.org) drive the scheduler when no paid background worker exists.
 * Protected by a shared secret compared in constant time; disabled when INTERNAL_CRON_TOKEN is empty.
 */
class InternalController extends Controller
{
    public function schedule(Request $request): JsonResponse
    {
        $token = (string) config('agriscan.health.cron_token');
        $given = (string) ($request->bearerToken() ?? '');
        if ($token === '' || strlen($token) < 24 || ! hash_equals($token, $given)) {
            return ApiResponse::error('Not found.', 404);
        }
        Artisan::call('schedule:run');

        return ApiResponse::ok(null, 'Scheduler ran.');
    }

    /** Liveness for Render: no database or external calls, so a Neon outage never takes the web service out of rotation. */
    public function health(): JsonResponse
    {
        return ApiResponse::ok(['status' => 'ok', 'time' => now()->toISOString()]);
    }
}
