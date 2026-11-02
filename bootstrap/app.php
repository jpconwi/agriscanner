<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\ForceJsonResponse;
use App\Services\Ai\Exceptions\AnalysisUnavailableException;
use App\Services\Ai\Exceptions\InvalidImageException;
use App\Services\Quota\QuotaExceededException;
use App\Services\Scans\ScanNotSavedException;
use App\Services\Storage\Exceptions\StorageUnavailableException;
use App\Support\ApiResponse;
use App\Support\DatabaseOutage;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Render terminates TLS in front of the container.
        $middleware->trustProxies(at: '*');
        $middleware->api(prepend: [ForceJsonResponse::class]);
        $middleware->redirectGuestsTo(fn (Request $r) => $r->is('api/*') ? null : route('login'));
        $middleware->redirectUsersTo('/dashboard');
        // Lets a password change sign out the user's other browsers (cookie sessions carry the password hash).
        $middleware->authenticateSessions();
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'active' => EnsureAccountActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $wantsJson = static fn (Request $r) => $r->is('api/*') || $r->expectsJson();

        $exceptions->render(function (ValidationException $e, Request $r) use ($wantsJson) {
            if ($wantsJson($r)) {
                return ApiResponse::error('The given data was invalid.', 422, ['errors' => $e->errors()]);
            }
        });
        $exceptions->render(function (AuthenticationException $e, Request $r) use ($wantsJson) {
            if ($wantsJson($r)) {
                return ApiResponse::error('Authentication required.', 401);
            }
        });
        $exceptions->render(function (AuthorizationException $e, Request $r) use ($wantsJson) {
            if ($wantsJson($r)) {
                return ApiResponse::error('You are not allowed to do that.', 403);
            }
        });
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $r) use ($wantsJson) {
            if ($wantsJson($r)) {
                return ApiResponse::error('Not found.', 404);
            }
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $r) use ($wantsJson) {
            if ($wantsJson($r)) {
                $retry = (int) ($e->getHeaders()['Retry-After'] ?? 60);

                return ApiResponse::error('Too many requests. Please slow down and try again shortly.', 429, ['code' => 'rate_limited'], ['Retry-After' => $retry]);
            }

            return response()->view('errors.429', [], 429, ['Retry-After' => (int) ($e->getHeaders()['Retry-After'] ?? 60)]);
        });
        // Domain failures: JSON for the API, a flash message + back-redirect for the browser UI.
        $domain = static function (string $message, int $status, string $code, array $extra = [], array $headers = []) use ($wantsJson) {
            return static function (Request $r) use ($message, $status, $code, $extra, $headers, $wantsJson) {
                if ($wantsJson($r)) {
                    return ApiResponse::error($message, $status, ['code' => $code] + $extra, $headers);
                }

                return redirect()->back()->withInput()->with('error', $message);
            };
        };
        $exceptions->render(function (QuotaExceededException $e, Request $r) use ($domain) {
            return $domain($e->getMessage(), 422, 'quota_exceeded')($r);
        });
        $exceptions->render(function (InvalidImageException $e, Request $r) use ($domain) {
            return $domain($e->getMessage(), 422, 'invalid_image')($r);
        });
        $exceptions->render(function (AnalysisUnavailableException $e, Request $r) use ($domain) {
            return $domain($e->getMessage(), 503, 'analysis_unavailable')($r);
        });
        $exceptions->render(function (ScanNotSavedException $e, Request $r) use ($domain) {
            return $domain($e->getMessage(), 503, 'scan_not_saved', ['saved' => false, 'recorded_in' => $e->recordedIn], ['Retry-After' => 60])($r);
        });
        $exceptions->render(function (StorageUnavailableException $e, Request $r) use ($domain) {
            return $domain($e->getMessage(), 503, 'storage_unavailable')($r);
        });
        $exceptions->render(function (QueryException $e, Request $r) use ($wantsJson) {
            if (! DatabaseOutage::isConnectionFailure($e)) {
                return null;
            }
            if ($wantsJson($r)) {
                return ApiResponse::error('The database is temporarily unavailable. Nothing was saved. Please try again shortly.', 503, ['code' => 'database_unavailable'], ['Retry-After' => 30]);
            }

            return response()->view('errors.503', ['message' => 'The database is temporarily unavailable. Nothing was saved. Please try again in a moment.'], 503, ['Retry-After' => 30]);
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $r) use ($wantsJson) {
            if ($wantsJson($r)) {
                return ApiResponse::error($e->getMessage() !== '' ? $e->getMessage() : 'Request failed.', $e->getStatusCode());
            }
        });
        // Never leak internals for unexpected failures.
        $exceptions->render(function (Throwable $e, Request $r) use ($wantsJson) {
            if ($wantsJson($r) && ! config('app.debug')) {
                report($e);

                return ApiResponse::error('Something went wrong on our side. Please try again.', 500);
            }
            if (! config('app.debug') && ! $e instanceof HttpExceptionInterface && ! $e instanceof ValidationException && ! $e instanceof AuthenticationException && ! $e instanceof AuthorizationException) {
                report($e);

                return response()->view('errors.500', [], 500);
            }
        });
    })->create();
