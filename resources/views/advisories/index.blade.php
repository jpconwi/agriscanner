@extends('layouts.app')
@section('title', 'Farm advisories')

@section('content')
<div class="mb-6"><h1 class="text-2xl font-semibold text-forest-950 sm:text-3xl">Farm advisory library</h1><p class="mt-1 text-sm text-ink-soft">Practical guidance curated for growers.</p></div>

<form method="GET" action="{{ route('advisory.index') }}" class="mb-5 flex gap-2" role="search">
    <input type="search" name="q" value="{{ $term }}" placeholder="Search advisories" aria-label="Search advisories" class="input flex-1">
    <button type="submit" class="btn btn-secondary">Search</button>
</form>

@if ($advisories->isEmpty())
    <div class="flex flex-col items-center rounded-xl border border-dashed border-sand-200 bg-white/60 px-6 py-14 text-center">
        <h2 class="text-lg font-semibold text-ink">No advisories found</h2>
        <p class="mt-1 text-sm text-ink-soft">{{ $term !== '' ? 'Try a different search term.' : 'Check back soon for new articles.' }}</p>
    </div>
@else
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($advisories as $a)
            <a href="{{ route('advisory.show', $a->id) }}" class="card block transition-shadow hover:shadow-md">
                @if ($a->category)<span class="badge badge-success">{{ $a->category }}</span>@endif
                <h2 class="mt-3 text-lg font-semibold leading-snug text-forest-950">{{ $a->title }}</h2>
                <p class="mt-2 line-clamp-3 text-sm text-ink-soft">{{ $a->summary }}</p>
                <p class="mt-4 text-xs text-ink-soft">@date($a->published_at)</p>
            </a>
        @endforeach
    </div>
    <x-pager :paginator="$advisories" />
@endif
@endsection
