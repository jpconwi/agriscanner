@extends('layouts.app')
@section('title', $advisory->title)

@section('content')
<a href="{{ route('advisory.index') }}" class="mb-4 inline-block text-sm text-forest-800 underline">← All advisories</a>
<article class="max-w-3xl">
    @if ($advisory->category)<span class="badge badge-success">{{ $advisory->category }}</span>@endif
    <h1 class="mt-3 text-3xl font-semibold text-forest-950 sm:text-4xl">{{ $advisory->title }}</h1>
    <p class="mt-2 text-sm text-ink-soft">@date($advisory->published_at)</p>
    @if ($advisory->summary)<p class="mt-5 text-lg text-ink-soft">{{ $advisory->summary }}</p>@endif
    {{-- Escaped plain text only: advisory bodies are never rendered as HTML. --}}
    <div class="mt-6 space-y-4 text-base leading-relaxed text-ink">
        @foreach (preg_split('/\R{2,}/', $advisory->body) as $para)<p class="whitespace-pre-line">{{ $para }}</p>@endforeach
    </div>
</article>
@endsection
