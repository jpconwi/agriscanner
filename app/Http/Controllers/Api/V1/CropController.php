<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Crop;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CropController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::ok(Crop::orderBy('name')->get(['id', 'name']));
    }
}
