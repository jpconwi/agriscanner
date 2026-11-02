@php use App\Support\Format; @endphp
<div class="mb-4 grid gap-3 sm:grid-cols-4">
    @foreach ([['Logical stored', Format::bytes($storage['logical_bytes'])], ['Files available', $storage['files_available']], ['Files pending', $storage['files_pending']], ['Files failed', $storage['files_failed']]] as [$l, $v])
        <div class="card p-4"><p class="text-xs text-ink-soft">{{ $l }}</p><p class="text-xl font-semibold text-forest-950">{{ $v }}</p></div>
    @endforeach
</div>
<div class="grid gap-4 md:grid-cols-2">
@foreach ($storage['providers'] as $p)
    <div class="card p-5">
        <div class="mb-3 flex items-center justify-between"><h2 class="font-semibold text-forest-950">{{ $p['label'] }}</h2><span class="badge {{ Format::tone($p['status']) }}">{{ $p['status'] }}</span></div>
        <dl class="grid grid-cols-2 gap-y-2 text-sm">
            <dt class="text-ink-soft">Objects</dt><dd>{{ $p['object_count'] }}</dd>
            <dt class="text-ink-soft">Stored here</dt><dd>@bytes($p['bytes'])</dd>
            <dt class="text-ink-soft">Provider limit</dt><dd>@bytes($p['provider_limit_bytes'])</dd>
        </dl>
        @unless ($p['configured'])<p class="mt-3 text-xs text-ink-soft">Not configured.</p>@endunless
    </div>
@endforeach
</div>
<form method="POST" action="{{ route('admin.storage.reconcile') }}" class="mt-6">@csrf
    <button class="btn btn-secondary">Reconcile usage with stored files</button>
    <p class="hint mt-2">Recomputes each account’s usage from verified files and flags missing objects. It never deletes anyone’s files.</p>
</form>
