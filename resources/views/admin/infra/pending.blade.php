@php use App\Support\Format; @endphp
@if ($pending->isEmpty())
    <div class="rounded-xl border border-dashed border-sand-200 bg-white/60 px-6 py-12 text-center text-sm text-ink-soft">No pending or failed operations.</div>
@else
    <div class="card overflow-x-auto p-0">
        <table class="w-full min-w-[40rem] text-left text-sm">
            <thead class="border-b border-sand-200 bg-paper"><tr><th class="th">Type</th><th class="th">User</th><th class="th">Status</th><th class="th">Attempts</th><th class="th">Last error</th><th class="th"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody class="divide-y divide-sand-200">
            @foreach ($pending as $o)
                <tr>
                    <td class="td">{{ $o['type'] }}<div class="text-xs text-ink-soft">@date($o['created_at'])</div></td>
                    <td class="td">{{ $o['user_email'] ?? '—' }}</td>
                    <td class="td"><span class="badge {{ Format::tone($o['status']) }}">{{ $o['status'] }}</span></td>
                    <td class="td">{{ $o['attempts'] }}</td>
                    <td class="td max-w-xs truncate" title="{{ $o['last_error'] }}">{{ $o['last_error'] ?? '—' }}</td>
                    <td class="td">@if ($o['status'] !== 'completed')<form method="POST" action="{{ route('admin.pending.retry', $o['id']) }}">@csrf<button class="btn btn-secondary btn-sm">Retry</button></form>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <x-pager :paginator="$pending" />
@endif
