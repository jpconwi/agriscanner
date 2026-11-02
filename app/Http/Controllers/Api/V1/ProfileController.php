<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Services\AccountService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['name' => $request->validated('name')]);
        $user->profile()->updateOrCreate([], $request->safe()->only(['phone', 'farm_name', 'location', 'farm_size_ha', 'main_crops']));

        return ApiResponse::ok(new UserResource($user->load('profile')), 'Profile saved.');
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            return ApiResponse::error('The current password is incorrect.', 422, ['errors' => ['current_password' => ['The current password is incorrect.']]]);
        }
        $user->update(['password' => $data['password']]);
        // Keep this session, revoke all others.
        $current = $user->currentAccessToken();
        $user->tokens()->when($current instanceof \Laravel\Sanctum\PersonalAccessToken, fn ($q) => $q->where('id', '!=', $current->id))->delete();
        AuditLog::record($user->id, 'user.password_changed', 'user', $user->id);

        return ApiResponse::ok(null, 'Password updated.');
    }

    public function destroy(Request $request, AccountService $accounts): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();
        if (! Hash::check($data['password'], $user->password)) {
            return ApiResponse::error('The password is incorrect.', 422, ['errors' => ['password' => ['The password is incorrect.']]]);
        }
        if ($user->isAdmin() && \App\Models\User::where('role', 'admin')->where('is_active', true)->count() <= 1) {
            return ApiResponse::error('You are the only administrator. Promote another admin before deleting this account.', 422);
        }
        $accounts->delete($user);

        return ApiResponse::ok(null, 'Account deleted.');
    }
}
