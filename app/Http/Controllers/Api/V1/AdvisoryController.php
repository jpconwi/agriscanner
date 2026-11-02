<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdvisoryResource;
use App\Models\Advisory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdvisoryController extends Controller
{
    public function index(Request $request)
    {
        $q = Advisory::published()->latest('published_at');
        if ($term = trim((string) $request->query('q', ''))) {
            // ILIKE with escaped wildcards: user input is never treated as a pattern.
            $like = '%'.addcslashes($term, '%_\\').'%';
            $op = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $q->where(fn ($qq) => $qq->where('title', $op, $like)->orWhere('summary', $op, $like)->orWhere('category', $op, $like));
        }

        return AdvisoryResource::collection($q->paginate(12));
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::ok(new AdvisoryResource(Advisory::published()->findOrFail($id)));
    }
}
