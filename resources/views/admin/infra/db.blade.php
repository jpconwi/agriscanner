@php use App\Support\Format; @endphp
<div class="grid gap-4 md:grid-cols-2">
@foreach (['primary' => 'Primary database (Neon)', 'secondary' => 'Secondary database (recovery only)'] as $key => $title)
    @php $d = $database[$key]; @endphp
    <div class="card p-5">
        <div class="mb-3 flex items-center justify-between"><h2 class="font-semibold text-forest-950">{{ $title }}</h2><span class="badge {{ Format::tone($d['status']) }}">{{ $d['status'] }}</span></div>
        @if (! ($d['configured'] ?? true))
            <p class="text-sm text-ink-soft">Not enabled. The secondary is never read automatically and is used only for explicit recovery and the pending-operation journal.</p>
        @else
            <dl class="grid grid-cols-2 gap-y-2 text-sm">
                <dt class="text-ink-soft">Latency</dt><dd>{{ isset($d['latency_ms']) ? $d['latency_ms'].' ms' : 'Unknown' }}</dd>
                <dt class="text-ink-soft">Size</dt><dd>@bytes($d['size_bytes'] ?? null)</dd>
                <dt class="text-ink-soft">Capacity</dt><dd>@bytes($d['capacity_bytes'] ?? null)</dd>
                <dt class="text-ink-soft">Used</dt><dd>{{ isset($d['percent_used']) ? $d['percent_used'].'%' : 'Unknown' }}</dd>
                <dt class="text-ink-soft">Last healthy</dt><dd>@date($d['last_success_at'] ?? null)</dd>
                <dt class="text-ink-soft">Failures (24h)</dt><dd>{{ $d['errors_24h'] ?? 0 }}</dd>
            </dl>
            @if (isset($d['percent_used']))
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-sand-100" role="progressbar" aria-valuenow="{{ min(100, $d['percent_used']) }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full {{ Format::levelColor($d['warning_level'] ?? 'ok') }}" style="width: {{ min(100, $d['percent_used']) }}%"></div></div>
            @endif
            @if (! empty($d['last_error']))<p class="mt-3 text-sm text-clay-700">{{ $d['last_error'] }}</p>@endif
        @endif
    </div>
@endforeach
</div>
<p class="mt-4 text-sm text-ink-soft">Neon’s limit is not available through SQL. Set <code>PRIMARY_DB_CAPACITY_BYTES</code> (or the Neon API variables) to see a percentage; otherwise capacity stays “Unknown”.</p>
