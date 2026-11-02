@extends('layouts.app')
@section('title', 'Users')

@section('content')
<div class="mb-6"><h1 class="text-2xl font-semibold text-forest-950 sm:text-3xl">Users</h1><p class="mt-1 text-sm text-ink-soft">Manage accounts, roles and storage quotas. Permissions are enforced by the server.</p></div>

<form method="GET" action="{{ route('admin.users') }}" class="mb-5 flex gap-2" role="search">
    <input type="search" name="q" value="{{ $term }}" placeholder="Search name or email" aria-label="Search users" class="input flex-1">
    <button type="submit" class="btn btn-secondary">Search</button>
</form>

@if ($users->isEmpty())
    <div class="rounded-xl border border-dashed border-sand-200 bg-white/60 px-6 py-14 text-center text-sm text-ink-soft">No users found.</div>
@else
<div class="card overflow-x-auto p-0">
    <table class="w-full min-w-[44rem] text-left text-sm">
        <thead class="border-b border-sand-200 bg-paper"><tr><th class="th">User</th><th class="th">Role</th><th class="th">Storage</th><th class="th">Scans</th><th class="th">Joined</th><th class="th"><span class="sr-only">Actions</span></th></tr></thead>
        <tbody class="divide-y divide-sand-200">
            @foreach ($users as $u)
                <tr>
                    <td class="td"><p class="font-medium text-ink">{{ $u['name'] }}</p><p class="text-xs text-ink-soft">{{ $u['email'] }}</p></td>
                    <td class="td"><span class="badge {{ $u['role'] === 'admin' ? 'badge-info' : 'badge-neutral' }}">{{ $u['role'] }}</span>@unless ($u['is_active'])<span class="badge badge-danger ml-1">disabled</span>@endunless</td>
                    <td class="td">@bytes($u['used_bytes']) / @bytes($u['quota_bytes'])</td>
                    <td class="td">{{ $u['scan_count'] }}</td>
                    <td class="td text-ink-soft">@date($u['created_at'])</td>
                    <td class="td">
                        <div class="flex justify-end gap-1">
                            <x-modal :title="'Edit '.$u['name']">
                                <x-slot:trigger><button type="button" class="btn btn-ghost btn-sm" aria-label="Edit {{ $u['name'] }}">Edit</button></x-slot:trigger>
                                <form method="POST" action="{{ route('admin.users.update', $u['id']) }}" class="space-y-4">
                                    @csrf @method('PATCH')
                                    <x-field name="role" label="Role" for="role-{{ $u['id'] }}">
                                        <select id="role-{{ $u['id'] }}" name="role" class="input" @disabled($u['id'] === auth()->id())>
                                            <option value="farmer" @selected($u['role'] === 'farmer')>Farmer</option>
                                            <option value="admin" @selected($u['role'] === 'admin')>Administrator</option>
                                        </select>
                                        @if ($u['id'] === auth()->id())<input type="hidden" name="role" value="{{ $u['role'] }}">@endif
                                    </x-field>
                                    <x-field name="quota_mb" label="Storage quota (MB)" for="quota-{{ $u['id'] }}" hint="Lowering a quota never deletes files; it only blocks new uploads.">
                                        <input id="quota-{{ $u['id'] }}" name="quota_mb" type="number" min="1" step="1" value="{{ (int) round($u['quota_bytes'] / 1048576) }}" class="input">
                                    </x-field>
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" name="is_active" value="1" @checked($u['is_active']) @disabled($u['id'] === auth()->id())>
                                        Account is active
                                    </label>
                                    @if ($u['id'] === auth()->id())<input type="hidden" name="is_active" value="1">@endif
                                    <div class="flex justify-end"><button type="submit" class="btn btn-primary">Save</button></div>
                                </form>
                            </x-modal>
                            <x-confirm :action="route('admin.users.destroy', $u['id'])" title="Delete this user?" :message="'This permanently removes '.$u['name'].', their scans and all stored photos.'" confirm="Delete user" :disabled="$u['id'] === auth()->id()">Delete</x-confirm>
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
<x-pager :paginator="$users" />
@endif
@endsection
