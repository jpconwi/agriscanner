<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Api\Admin\UserController as ApiUsers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccountService;
use App\Services\Quota\QuotaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.users', ['users' => (new ApiUsers)->listing($request), 'term' => trim((string) $request->query('q', ''))]);
    }

    /** Role, active flag and quota in one form. Last-admin protections are identical to the API. */
    public function update(UpdateUserRequest $request, User $user, QuotaService $quota): RedirectResponse
    {
        $actor = $request->user();
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        $demoting = isset($data['role']) && $data['role'] !== 'admin' && $user->isAdmin();
        $disabling = ! $data['is_active'] && $user->isAdmin();
        if ($user->id === $actor->id && ($demoting || $disabling)) {
            return back()->with('error', 'You cannot demote or disable your own administrator account.');
        }
        if (($demoting || $disabling) && User::where('role', 'admin')->where('is_active', true)->where('id', '!=', $user->id)->doesntExist()) {
            return back()->with('error', 'At least one active administrator is required.');
        }

        $quotaBytes = null;
        if ($request->filled('quota_mb')) {
            $request->validate(['quota_mb' => ['numeric', 'min:1', 'max:1048576']]);
            $quotaBytes = (int) round((float) $request->input('quota_mb') * 1048576);
        }

        if (isset($data['role'])) {
            $user->role = $data['role'];
        }
        $user->is_active = $data['is_active'];
        $user->save();
        if (! $user->is_active) {
            $user->tokens()->delete();
        }
        if ($quotaBytes !== null) {
            // Lowering a quota below usage only blocks new uploads; nothing is deleted.
            $quota->setQuota($user, $quotaBytes);
        }
        AuditLog::record($actor->id, 'admin.user_updated', 'user', $user->id, ['role' => $user->role, 'is_active' => $user->is_active, 'quota_bytes' => $quotaBytes]);

        return back()->with('status', 'User updated.');
    }

    public function destroy(Request $request, User $user, AccountService $accounts): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete your own account here. Use your profile page.');
        }
        if ($user->isAdmin() && User::where('role', 'admin')->where('is_active', true)->where('id', '!=', $user->id)->doesntExist()) {
            return back()->with('error', 'At least one active administrator is required.');
        }
        $accounts->delete($user, $request->user());

        return back()->with('status', 'User deleted along with their stored photos.');
    }
}
