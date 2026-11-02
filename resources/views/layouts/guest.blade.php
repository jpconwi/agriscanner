@extends('layouts.base')

@section('body')
<div class="flex min-h-screen items-center justify-center px-4 py-10">
    <div class="w-full max-w-md">
        <a href="{{ route('home') }}" class="mb-6 flex items-center justify-center gap-2 text-forest-950">
            <img src="{{ asset('favicon.svg') }}" alt="" class="h-8 w-8">
            <span class="font-display text-xl">AgriScan</span>
        </a>
        <div class="mb-4">@include('partials.flash')</div>
        <div class="rounded-2xl border border-sand-200 bg-white p-6 shadow-sm sm:p-8">
            <h1 class="text-2xl font-semibold text-forest-950">@yield('heading')</h1>
            <p class="mb-6 mt-1 text-sm text-ink-soft">@yield('subheading')</p>
            @yield('content')
        </div>
        <p class="mt-4 text-center text-sm text-ink-soft">@yield('footer')</p>
    </div>
</div>
@endsection
