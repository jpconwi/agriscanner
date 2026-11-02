@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-forest-950 sm:text-3xl">Hello, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}</h1>
        <p class="mt-1 text-sm text-ink-soft">Here is how your crops have been doing.</p>
    </div>
    <a href="{{ route('scans.create') }}" class="btn btn-primary">New scan</a>
</div>

<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="card"><p class="text-xs font-medium uppercase tracking-wide text-ink-soft">Total scans</p><p class="mt-1 text-2xl font-semibold text-forest-950">{{ $d['total_scans'] }}</p></div>
    <div class="card"><p class="text-xs font-medium uppercase tracking-wide text-ink-soft">Healthy</p><p class="mt-1 text-2xl font-semibold text-forest-950">{{ $d['healthy_scans'] }}</p>
        @if ($d['total_scans'])<p class="mt-1 text-xs text-ink-soft">{{ round($d['healthy_scans'] / $d['total_scans'] * 100) }}% of scans</p>@endif</div>
    <div class="card"><p class="text-xs font-medium uppercase tracking-wide text-ink-soft">Issues found</p><p class="mt-1 text-2xl font-semibold text-forest-950">{{ $d['issue_scans'] }}</p></div>
    <div class="card"><p class="mb-2 text-xs font-medium uppercase tracking-wide text-ink-soft">Storage</p><x-storage-meter :usage="$d['storage']" compact /></div>
</div>

@if ($d['total_scans'] === 0)
    <div class="mt-6 flex flex-col items-center rounded-xl border border-dashed border-sand-200 bg-white/60 px-6 py-14 text-center">
        <h2 class="text-lg font-semibold text-ink">No scans yet</h2>
        <p class="mt-1 max-w-sm text-sm text-ink-soft">Upload your first crop photo to see disease insights here.</p>
        <a href="{{ route('scans.create') }}" class="btn btn-primary mt-4">Scan a crop</a>
    </div>
@else
    <div class="mt-6 grid gap-4 lg:grid-cols-2">
        <div class="card">
            <h2 class="mb-4 text-lg font-semibold text-forest-950">Scans per week</h2>
            <div class="h-56"><canvas id="weekly" role="img" aria-label="Line chart of scans per week"></canvas></div>
        </div>
        <div class="card">
            <h2 class="mb-4 text-lg font-semibold text-forest-950">Most common findings</h2>
            @if (count($d['by_disease']))
                <div class="h-56"><canvas id="diseases" role="img" aria-label="Bar chart of most common findings"></canvas></div>
            @else
                <p class="text-sm text-ink-soft">No disease findings yet.</p>
            @endif
        </div>
    </div>

    <div class="card mt-6">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-forest-950">Recent scans</h2>
            <a href="{{ route('scans.index') }}" class="text-sm text-forest-800 underline">View all</a>
        </div>
        <ul class="divide-y divide-sand-200">
            @foreach ($d['recent'] as $s)
                @include('scans._row', ['s' => $s])
            @endforeach
        </ul>
    </div>
@endif
@endsection

@push('scripts')
@if ($d['total_scans'] > 0)
<script src="{{ asset('js/chart.umd.js') }}"></script>
<script>
    (function () {
        var weekly = @json($d['weekly']);
        var diseases = @json($d['by_disease']);
        Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
        new Chart(document.getElementById('weekly'), {
            type: 'line',
            data: { labels: weekly.map(function (w) { return w.label; }), datasets: [{ label: 'Scans', data: weekly.map(function (w) { return w.count; }), borderColor: '#1f7f42', backgroundColor: '#1f7f42', tension: 0.3, pointRadius: 3 }] },
            options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
        });
        var el = document.getElementById('diseases');
        if (el) {
            new Chart(el, {
                type: 'bar',
                data: { labels: diseases.map(function (x) { return x.name; }), datasets: [{ label: 'Scans', data: diseases.map(function (x) { return x.count; }), backgroundColor: diseases.map(function (_, i) { return i % 2 ? '#b08a4f' : '#4caf50'; }), borderRadius: 6 }] },
                options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } }
            });
        }
    })();
</script>
@endif
@endpush
