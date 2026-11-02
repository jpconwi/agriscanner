<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccountService;
use App\Services\Quota\QuotaService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(Request $request)
    {
        return ApiResponse::paginated($this->listing($request));
    }

    /** Shared by the JSON API and the admin web page. Quota/usage are real values from the accounting tables. */
    public function listing(Request $request): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $default = app(\App\Services\Quota\SettingsService::class)->get('default_quota_bytes');
        $q = User::query()
            ->leftJoin('storage_quotas as sq', 'sq.user_id', '=', 'users.id')
            ->leftJoin('storage_usage as su', 'su.user_id', '=', 'users.id')
            ->select('users.*', DB::raw('coalesce(sq.quota_bytes, '.$default.') as quota_bytes'), DB::raw('coalesce(su.used_bytes, 0) as used_bytes'))
            ->withCount('scans')
            ->latest('users.id');

        if ($term = trim((string) $request->query('q', ''))) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $op = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $q->where(fn ($qq) => $qq->where('users.name', $op, $like)->orWhere('users.email', $op, $like));
        }

        $page = $q->paginate(20)->withQueryString();
        $page->getCollection()->transform(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'is_active' => (bool) $u->is_active,
            'quota_bytes' => (int) $u->quota_bytes, 'used_bytes' => (int) $u->used_bytes, 'scan_count' => $u->scans_count,
            'created_at' => $u->created_at?->toISOString(),
        ]);

        return $page;
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validated();

        // Guard against locking the platform out of administration.
        $demoting = isset($data['role']) && $data['role'] !== 'admin' && $user->isAdmin();
        $disabling = array_key_exists('is_active', $data) && ! $data['is_active'] && $user->isAdmin();
        if ($user->id === $actor->id && ($demoting || $disabling)) {
            return ApiResponse::error('You cannot demote or disable your own administrator account.', 422);
        }
        if (($demoting || $disabling) && User::where('role', 'admin')->where('is_active', true)->where('id', '!=', $user->id)->doesntExist()) {
            return ApiResponse::error('At least one active administrator is required.', 422);
        }

        // role / is_active are not mass-assignable; set explicitly.
        if (isset($data['role'])) {
            $user->role = $data['role'];
        }
        if (array_key_exists('is_active', $data)) {
            $user->is_active = (bool) $data['is_active'];
            if (! $user->is_active) {
                $user->tokens()->delete();
            }
        }
        $user->save();
        AuditLog::record($actor->id, 'admin.user_updated', 'user', $user->id, array_intersect_key($data, ['role' => 1, 'is_active' => 1]));

        return ApiResponse::ok(['id' => $user->id, 'role' => $user->role, 'is_active' => $user->is_active], 'User updated.');
    }

    public function quota(Request $request, User $user, QuotaService $quota): JsonResponse
    {
        $data = $request->validate(['quota_bytes' => ['required', 'integer', 'min:1048576', 'max:1099511627776']]);
        // Lowering a quota below current usage only blocks new uploads; nothing is deleted.
        $quota->setQuota($user, $data['quota_bytes']);
        AuditLog::record($request->user()->id, 'admin.quota_set', 'user', $user->id, ['quota_bytes' => $data['quota_bytes']]);

        return ApiResponse::ok($quota->usageFor($user), 'Quota updated.');
    }

    public function destroy(Request $request, User $user, AccountService $accounts): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return ApiResponse::error('You cannot delete your own account here. Use your profile page.', 422);
        }
        if ($user->isAdmin() && User::where('role', 'admin')->where('is_active', true)->where('id', '!=', $user->id)->doesntExist()) {
            return ApiResponse::error('At least one active administrator is required.', 422);
        }
        $accounts->delete($user, $request->user());

        return ApiResponse::ok(null, 'User deleted.');
    }
}
