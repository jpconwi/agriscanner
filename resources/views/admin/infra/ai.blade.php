@php use App\Support\Format; @endphp
<p class="mb-4 text-sm text-ink-soft">Order used for each scan: <strong>{{ count($ai['chain']) ? implode(' → ', $ai['chain']) : 'none configured' }}</strong>. If every provider fails, the scan fails clearly; no result is invented.</p>
<div class="grid gap-4 md:grid-cols-2">
@foreach ($ai['providers'] as $p)
    <div class="card p-5">
        <div class="mb-3 flex items-center justify-between"><h2 class="font-semibold capitalize text-forest-950">{{ $p['name'] }}</h2><span class="badge {{ Format::tone($p['status']) }}">{{ $p['status'] }}</span></div>
        <dl class="grid grid-cols-2 gap-y-2 text-sm">
            <dt class="text-ink-soft">Model</dt><dd class="truncate">{{ $p['model'] ?: 'Not set' }}</dd>
            <dt class="text-ink-soft">Requests (24h)</dt><dd>{{ $p['requests_24h'] }}</dd>
            <dt class="text-ink-soft">Success rate</dt><dd>{{ $p['success_rate'] === null ? 'Unknown' : round($p['success_rate'] * 100).'%' }}</dd>
            <dt class="text-ink-soft">Avg latency</dt><dd>{{ $p['avg_latency_ms'] === null ? 'Unknown' : $p['avg_latency_ms'].' ms' }}</dd>
            <dt class="text-ink-soft">Last error</dt><dd>{{ $p['last_error_category'] ?? '—' }}</dd>
            <dt class="text-ink-soft">Last success</dt><dd>@date($p['last_success_at'])</dd>
        </dl>
        @unless ($p['configured'])<p class="mt-3 text-xs text-ink-soft">Not configured (or not explicitly authorised). It is skipped and never called.</p>@endunless
    </div>
@endforeach
</div>
