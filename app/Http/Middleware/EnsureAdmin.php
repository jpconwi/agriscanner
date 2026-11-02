<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;

/** Server-side role gate for every admin route (web and API). Views also hide admin links, but that is cosmetic. */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->isAdmin()) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return ApiResponse::error('You are not allowed to do that.', 403);
            }
            abort(403);
        }

        return $next($request);
    }
}
