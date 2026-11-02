@extends('layouts.app')
@section('title', 'System monitoring')

@section('content')
@php use App\Support\Format; @endphp
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div><h1 class="text-2xl font-semibold text-forest-950 sm:text-3xl">System monitoring</h1><p class="mt-1 text-sm text-ink-soft">AI providers, database, image storage, queues and settings. Unknown values are shown as “Unknown”, never guessed.</p></div>
    <form method="POST" action="{{ route('admin.checks.run') }}">@csrf<button class="btn btn-secondary">Run health checks now</button></form>
</div>

@if (count($checks))
    <div class="mb-6 flex flex-wrap gap-2" aria-label="Latest health checks">
        @foreach ($checks as $c)
            <span class="badge {{ Format::tone($c['status']) }}" title="{{ $c['message'] }} · @date($c['checked_at'])">{{ str_replace('_', ' ', $c['service']) }}: {{ $c['status'] }}</span>
        @endforeach
    </div>
@endif

<nav class="mb-6 flex gap-1 overflow-x-auto border-b border-sand-200" aria-label="Monitoring sections">
    @foreach ($tabs as $key => $label)
        <a href="{{ route('admin.infrastructure', ['tab' => $key]) }}" @if ($tab === $key) aria-current="page" @endif
           class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium {{ $tab === $key ? 'border-forest-700 text-forest-900' : 'border-transparent text-ink-soft hover:text-ink' }}">{{ $label }}</a>
    @endforeach
</nav>

@include('admin.infra.'.$tab)
@endsection
