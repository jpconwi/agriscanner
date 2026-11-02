<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Quota\QuotaService;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, QuotaService $quota): JsonResponse
    {
        $user = User::create($request->safe()->only(['name', 'email', 'password']));
        $user->refresh(); // load DB defaults (role, is_active)
        // role defaults to "farmer" in the schema; it is never taken from request input.
        $user->profile()->create([]);
        $quota->ensureRows($user);
        AuditLog::record($user->id, 'user.registered', 'user', $user->id);

        return ApiResponse::ok(['user' => new UserResource($user->load('profile')), 'token' => $user->createToken('web')->plainTextToken], 'Account created.', 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email')->lower()->toString())->first();

        // Same message for unknown email and wrong password (no account enumeration). Hash::check runs either way.
        $ok = Hash::check($request->input('password'), $user?->password ?? '$2y$12$0pjD4Ahs4axK.6dDgpfCbOK3o691c5XBzhpfvuXqsNPzHqSKKt1si');
        if (! $user || ! $ok) {
            AuditLog::record($user?->id, 'auth.login_failed', 'user', $user?->id);

            return ApiResponse::error('The email or password is incorrect.', 422, ['errors' => ['email' => ['The email or password is incorrect.']]]);
        }
        if (! $user->is_active) {
            return ApiResponse::error('This account has been disabled. Contact an administrator.', 403, ['code' => 'account_disabled']);
        }

        return ApiResponse::ok(['user' => new UserResource($user->load('profile')), 'token' => $user->createToken('web')->plainTextToken]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        }

        return ApiResponse::ok(null, 'Signed out.');
    }

    public function user(Request $request): JsonResponse
    {
        return ApiResponse::ok(new UserResource($request->user()->load('profile')));
    }

    /** Always answers the same way so the endpoint cannot be used to discover registered emails. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        try {
            Password::sendResetLink($request->only('email'));
        } catch (\Throwable $e) {
            report($e);
        }

        return ApiResponse::ok(null, 'If that email is registered, a reset link has been sent.');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ]);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete(); // sign out everywhere after a reset
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error('This reset link is invalid or has expired. Please request a new one.', 422, ['errors' => ['token' => ['This reset link is invalid or has expired.']]]);
        }

        return ApiResponse::ok(null, 'Password updated.');
    }
}
