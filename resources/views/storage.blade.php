@extends('layouts.app')
@section('title', 'My storage')

@section('content')
<div class="mb-6"><h1 class="text-2xl font-semibold text-forest-950 sm:text-3xl">My storage</h1><p class="mt-1 text-sm text-ink-soft">Your personal allowance for crop photos. This is separate from the platform's own infrastructure limits.</p></div>

<div class="space-y-6">
    <div class="card">
        <h2 class="mb-4 text-lg font-semibold text-forest-950">Account quota</h2>
        <x-storage-meter :usage="$u" />
        <p class="mt-4 text-xs text-ink-soft">Usage counts each photo once, even if it is kept in more than one place for safety. Nothing is ever deleted automatically when you reach your limit.
            @if ($u['last_reconciled_at'])Last verified against stored files: @date($u['last_reconciled_at']).@endif</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([['Stored images', $u['image_count'], null], ['Saved scans', $u['scan_count'], null], ['Pending uploads', $u['pending_uploads'], $u['pending_uploads'] ? 'Waiting to be saved safely' : null], ['Failed uploads', $u['failed_uploads'], null]] as [$label, $value, $hint])
            <div class="card"><p class="text-xs font-medium uppercase tracking-wide text-ink-soft">{{ $label }}</p><p class="mt-1 text-2xl font-semibold text-forest-950">{{ $value }}</p>@if ($hint)<p class="mt-1 text-xs text-ink-soft">{{ $hint }}</p>@endif</div>
        @endforeach
    </div>

    <div class="card flex flex-wrap items-center justify-between gap-3">
        <div><p class="text-sm font-medium text-ink">Free up space</p><p class="text-xs text-ink-soft">Delete scans you no longer need. @bytes($u['remaining_bytes']) remaining.</p></div>
        <a href="{{ route('scans.index') }}" class="btn btn-secondary">Manage scans</a>
    </div>
</div>
@endsection
