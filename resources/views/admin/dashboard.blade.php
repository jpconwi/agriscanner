@extends('layouts.app')
@section('title', 'Admin overview')

@section('content')
<div class="mb-6"><h1 class="text-2xl font-semibold text-forest-950 sm:text-3xl">Admin overview</h1><p class="mt-1 text-sm text-ink-soft">Platform activity and service status at a glance.</p></div>

<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
    @foreach ([
        ['Users', $d['users_total'], $d['users_new_7d'].' new in 7 days'],
        ['Scans', $d['scans_total'], $d['scans_7d'].' in the last 7 days'],
        ['Failed scans', $d['scans_failed'], null],
        ['Logical storage used', \App\Support\Format::bytes($d['storage_used_bytes']), 'Each photo counted once'],
        ['Pending operations', $d['pending_operations'], $d['pending_operations'] ? 'Needs review' : 'All clear'],
        ['Failed jobs', $d['failed_jobs'], null],
    ] as [$label, $value, $hint])
        <div class="card"><p class="text-xs font-medium uppercase tracking-wide text-ink-soft">{{ $label }}</p><p class="mt-1 text-2xl font-semibold text-forest-950">{{ $value }}</p>@if ($hint)<p class="mt-1 text-xs text-ink-soft">{{ $hint }}</p>@endif</div>
    @endforeach
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-2">
    <div class="card">
        <h2 class="mb-4 text-lg font-semibold text-forest-950">Scans, last 14 days</h2>
        <div class="h-60"><canvas id="byDay" role="img" aria-label="Bar chart of scans per day for the last 14 days"></canvas></div>
    </div>
    <div class="card">
        <h2 class="mb-4 text-lg font-semibold text-forest-950">Service status</h2>
        @if (count($d['services']) === 0)
            <p class="text-sm text-ink-soft">No health checks recorded yet.</p>
        @else
            <ul class="divide-y divide-sand-200 text-sm">
                @foreach ($d['services'] as $s)
                    <li class="flex items-center justify-between py-2.5"><span class="capitalize text-ink">{{ str_replace('_', ' ', $s['service']) }}</span><span class="badge {{ \App\Support\Format::tone($s['status']) }}">{{ $s['status'] }}</span></li>
                @endforeach
            </ul>
        @endif
        <a href="{{ route('admin.infrastructure') }}" class="mt-3 inline-block text-sm text-forest-800 underline">Open infrastructure monitor</a>
    </div>
</div>

<div class="card mt-6">
    <h2 class="mb-4 text-lg font-semibold text-forest-950">Top detected diseases</h2>
    @if (count($d['top_diseases']) === 0)
        <p class="text-sm text-ink-soft">No disease detections recorded yet.</p>
    @else
        <ul class="grid gap-2 sm:grid-cols-2">
            @foreach ($d['top_diseases'] as $x)<li class="flex items-center justify-between rounded-lg bg-paper px-3 py-2 text-sm"><span>{{ $x['name'] }}</span><span class="font-medium">{{ $x['count'] }}</span></li>@endforeach
        </ul>
    @endif
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/chart.umd.js') }}"></script>
<script>
    (function () {
        var days = @json($d['scans_by_day']);
        Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
        new Chart(document.getElementById('byDay'), {
            type: 'bar',
            data: { labels: days.map(function (x) { return x.label; }), datasets: [{ label: 'Scans', data: days.map(function (x) { return x.count; }), backgroundColor: '#1f7f42', borderRadius: 4 }] },
            options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
        });
    })();
</script>
@endpush
