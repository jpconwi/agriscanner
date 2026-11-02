@extends('layouts.app')
@section('title', 'Scan history')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div><h1 class="text-2xl font-semibold text-forest-950 sm:text-3xl">Scan history</h1><p class="mt-1 text-sm text-ink-soft">Every analysis you have saved.</p></div>
    <a href="{{ route('scans.create') }}" class="btn btn-primary">New scan</a>
</div>

@if ($scans->isEmpty())
    <div class="flex flex-col items-center rounded-xl border border-dashed border-sand-200 bg-white/60 px-6 py-14 text-center">
        <h2 class="text-lg font-semibold text-ink">No saved scans</h2>
        <p class="mt-1 text-sm text-ink-soft">Scans you run will appear here.</p>
        <a href="{{ route('scans.create') }}" class="btn btn-primary mt-4">Scan a crop</a>
    </div>
@else
    <ul class="space-y-3">
        @foreach ($scans as $s)
            <li class="card flex items-center gap-3 p-3 sm:p-4">
                <ul class="min-w-0 flex-1">@include('scans._row', ['s' => $s])</ul>
                <x-confirm :action="route('scans.destroy', $s['id'])" title="Delete this scan?" message="The photo and its analysis will be permanently removed and the storage freed. This cannot be undone.">Delete</x-confirm>
            </li>
        @endforeach
    </ul>
    <x-pager :paginator="$scans" />
@endif
@endsection
