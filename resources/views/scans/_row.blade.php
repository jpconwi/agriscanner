@php
    $label = $s['is_healthy'] ? 'Healthy' : ($s['disease']['name'] ?? $s['predicted_label'] ?? 'Unidentified');
    $badge = $s['status'] !== 'completed' ? ['badge-danger', $s['status']] : ($s['is_healthy'] ? ['badge-success', 'Healthy'] : ['badge-warning', 'Issue']);
@endphp
<li class="flex items-center gap-3 py-3">
    <a href="{{ route('scans.show', $s['id']) }}" class="flex min-w-0 flex-1 items-center gap-3 hover:bg-leaf-50/60">
        @if ($s['thumbnail_url'])
            <img src="{{ $s['thumbnail_url'] }}" alt="" loading="lazy" class="h-12 w-12 rounded-lg object-cover">
        @else
            <div class="h-12 w-12 rounded-lg bg-sand-200/60"></div>
        @endif
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-ink">{{ $label }}</p>
            <p class="text-xs text-ink-soft">{{ $s['crop']['name'] ?? 'Crop unknown' }} · @date($s['created_at'])</p>
        </div>
        <span class="badge {{ $badge[0] }}">{{ $badge[1] }}</span>
    </a>
</li>
