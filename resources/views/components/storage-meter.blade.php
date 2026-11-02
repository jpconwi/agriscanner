@props(['usage', 'compact' => false])
@php
    $pct = $usage['percent_used'];
    $msg = match ($usage['warning_level']) {
        'full' => 'Your storage is full. New uploads are blocked until you delete scans.',
        'critical' => 'Your storage is almost full. Consider deleting old scans.',
        'warning' => 'You have used most of your storage allowance.',
        default => null,
    };
@endphp
<div>
    <div class="mb-2 flex items-baseline justify-between gap-2">
        <p class="text-sm font-medium text-ink">@bytes($usage['used_bytes']) / @bytes($usage['quota_bytes'])</p>
        <p class="text-sm text-ink-soft">{{ number_format($pct, $pct < 10 ? 1 : 0) }}% used</p>
    </div>
    <div class="h-3 w-full overflow-hidden rounded-full bg-sand-200/70" role="progressbar" aria-valuenow="{{ round($pct) }}" aria-valuemin="0" aria-valuemax="100">
        <div class="h-full rounded-full transition-all {{ \App\Support\Format::levelColor($usage['warning_level']) }}" style="width: {{ min(100, max(0, $pct)) }}%"></div>
    </div>
    @unless ($compact)<p class="mt-2 text-xs text-ink-soft">@bytes($usage['remaining_bytes']) remaining</p>@endunless
    @if ($msg)
        <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900" role="alert">{{ $msg }} @if ($compact)<a href="{{ route('storage') }}" class="underline">Manage</a>@endif</p>
    @endif
</div>
