<h2 class="mb-3 text-lg font-semibold text-forest-950">Audit log</h2>
@if ($logs->isEmpty())
    <p class="text-sm text-ink-soft">No entries yet.</p>
@else
    <div class="card overflow-x-auto p-0">
        <table class="w-full min-w-[32rem] text-left text-sm">
            <thead class="border-b border-sand-200 bg-paper"><tr><th class="th">When</th><th class="th">Actor</th><th class="th">Action</th><th class="th">Target</th></tr></thead>
            <tbody class="divide-y divide-sand-200">
            @foreach ($logs as $l)
                <tr><td class="td whitespace-nowrap">@date($l['created_at'])</td><td class="td">{{ $l['actor'] ?? 'system' }}</td><td class="td">{{ $l['action'] }}</td><td class="td">{{ $l['target'] ?? '—' }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <x-pager :paginator="$logs" />
@endif
<h2 class="mb-3 mt-8 text-lg font-semibold text-forest-950">Failed jobs</h2>
@if ($failedJobs->isEmpty())
    <p class="text-sm text-ink-soft">No failed jobs.</p>
@else
    <ul class="card divide-y divide-sand-200 p-0 text-sm">
        @foreach ($failedJobs as $j)<li class="px-4 py-3"><span class="text-ink-soft">@date($j['failed_at']) · {{ $j['queue'] }}</span><br>{{ $j['summary'] }}</li>@endforeach
    </ul>
@endif
