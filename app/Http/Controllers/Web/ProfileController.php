<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        return view('profile', ['user' => $request->user()->load('profile')]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->update(['name' => $request->validated('name')]);
        $user->profile()->updateOrCreate([], $request->safe()->only(['phone', 'farm_name', 'location', 'farm_size_ha', 'main_crops']));

        return back()->with('status', 'Profile saved.');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('password', [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'The current password is incorrect.'], 'password');
        }
        $user->update(['password' => $data['password']]);
        $user->tokens()->delete();
        // Invalidate this user's other browser sessions and refresh the stored hash for this one.
        Auth::logoutOtherDevices($data['password']);
        AuditLog::record($user->id, 'user.password_changed', 'user', $user->id);

        return back()->with('status', 'Password updated.');
    }

    public function destroy(Request $request, AccountService $accounts): RedirectResponse
    {
        $data = $request->validateWithBag('delete', ['password' => ['required', 'string']]);
        $user = $request->user();
        if (! Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => 'The password is incorrect.'], 'delete');
        }
        if ($user->isAdmin() && User::where('role', 'admin')->where('is_active', true)->count() <= 1) {
            return back()->with('error', 'You are the only administrator. Promote another admin before deleting this account.');
        }

        $accounts->delete($user); // photos are removed from every replica before any row is dropped
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', 'Your account and all stored photos have been deleted.');
    }
}
