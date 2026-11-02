@php
    $pct = $scan['confidence'] === null ? null : round($scan['confidence'] * 100);
    $sections = [
        ['Symptoms to look for', $scan['symptoms']], ['Possible contributing factors', $scan['contributing_factors']],
        ['Treatment options', $scan['treatments']], ['Prevention', $scan['prevention']],
    ];
@endphp
<div class="space-y-4">
    <div class="card grid gap-5 md:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
        @if ($scan['image_url'])
            <img src="{{ $scan['image_url'] }}" alt="Scanned crop" class="aspect-[4/3] w-full rounded-lg object-cover">
        @else
            <div class="flex aspect-[4/3] items-center justify-center rounded-lg bg-sand-200/50 text-sm text-ink-soft">Image unavailable</div>
        @endif
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="badge {{ $scan['is_healthy'] ? 'badge-success' : 'badge-warning' }}">{{ $scan['is_healthy'] ? 'Appears healthy' : 'Possible issue' }}</span>
                @if ($scan['status'] !== 'completed')<span class="badge badge-danger">{{ $scan['status'] }}</span>@endif
            </div>
            <h2 class="mt-3 text-3xl font-semibold text-forest-950">{{ $scan['is_healthy'] ? 'No disease detected' : ($scan['disease']['name'] ?? $scan['predicted_label'] ?? 'Unidentified condition') }}</h2>
            <p class="mt-1 text-sm text-ink-soft">Crop: {{ $scan['crop']['name'] ?? $scan['crop_label'] ?? 'Not determined' }}</p>

            <div class="mt-5">
                <p class="text-xs font-medium uppercase tracking-wide text-ink-soft">Model confidence</p>
                @if ($pct === null)
                    <p class="mt-1 text-sm text-ink">Not provided by the model</p>
                @else
                    <div class="mt-1 h-2.5 overflow-hidden rounded-full bg-sand-200/70"><div class="h-full rounded-full bg-leaf-500" style="width: {{ $pct }}%"></div></div>
                    <p class="mt-1 text-sm text-ink">{{ $pct }}%</p>
                @endif
            </div>

            <dl class="mt-5 grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-ink-soft">Provider</dt><dd class="font-medium">{{ $scan['provider'] ?? '—' }}</dd></div>
                <div><dt class="text-ink-soft">Model</dt><dd class="truncate font-medium" title="{{ $scan['model'] }}">{{ $scan['model'] ?? '—' }}</dd></div>
                <div class="col-span-2"><dt class="text-ink-soft">Scanned</dt><dd class="font-medium">@date($scan['created_at'])</dd></div>
            </dl>
        </div>
    </div>

    @if ($scan['status'] !== 'completed')
        <div class="card border-amber-300 bg-amber-50 text-sm text-amber-900">This scan has not completed analysis. {{ $scan['failure_message'] ?? 'You can delete it and try again.' }}</div>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($sections as [$title, $items])
            @if (count($items))
                <div class="card">
                    <h3 class="mb-3 text-base font-semibold text-forest-950">{{ $title }}</h3>
                    <ul class="space-y-1.5 text-sm text-ink">
                        @foreach ($items as $item)<li class="flex gap-2"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-leaf-500"></span>{{ $item }}</li>@endforeach
                    </ul>
                </div>
            @endif
        @endforeach
    </div>

    @if ($scan['follow_up'])
        <div class="card"><h3 class="mb-2 text-base font-semibold text-forest-950">Follow-up monitoring</h3><p class="text-sm text-ink">{{ $scan['follow_up'] }}</p></div>
    @endif

    <p class="rounded-lg bg-sand-200/40 px-4 py-3 text-xs text-ink-soft">{{ $scan['disclaimer'] }}</p>
</div>
