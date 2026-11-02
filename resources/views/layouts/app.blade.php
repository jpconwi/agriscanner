@extends('layouts.base')

@php
    $farmerNav = [
        ['dashboard', 'Dashboard', 'dashboard', 'M3 12l9-9 9 9M5 10v10h14V10'],
        ['scans.create', 'Scan a crop', 'scans.create', 'M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2M4 12h16'],
        ['scans.index', 'Scan history', ['scans.index', 'scans.show'], 'M12 8v4l3 3M21 12a9 9 0 11-3-6.7L21 8M21 3v5h-5'],
        ['advisory.index', 'Advisories', 'advisory.*', 'M4 5a2 2 0 012-2h12v18H6a2 2 0 01-2-2V5zM8 7h6'],
        ['storage', 'My storage', 'storage', 'M4 7c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3zM4 7v10c0 1.7 3.6 3 8 3s8-1.3 8-3V7'],
        ['profile.edit', 'Profile', 'profile.*', 'M12 12a4 4 0 100-8 4 4 0 000 8zM4 21a8 8 0 0116 0'],
    ];
    $adminNav = [
        ['admin.dashboard', 'Overview', 'admin.dashboard', 'M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7l8-4z'],
        ['admin.users', 'Users', 'admin.users*', 'M16 11a3 3 0 100-6M8 11a3 3 0 100-6M2 20a6 6 0 0112 0M14 14a6 6 0 018 6'],
        ['admin.reference', 'Crops & diseases', 'admin.reference', 'M12 21V9M12 9c0-4 3-6 7-6 0 4-2 7-7 6zM12 13c0-3-2-5-6-5 0 3 2 6 6 5z'],
        ['admin.advisories', 'Advisories', 'admin.advisories*', 'M4 6h16M4 12h16M4 18h10'],
        ['admin.infrastructure', 'Infrastructure', 'admin.infrastructure', 'M3 12h4l3-8 4 16 3-8h4'],
    ];
@endphp

@section('body')
<div class="min-h-screen lg:flex" x-data="{ menu: false }" @keydown.escape.window="menu = false">
    {{-- Desktop sidebar --}}
    <aside class="hidden w-64 shrink-0 bg-forest-900 p-4 lg:sticky lg:top-0 lg:block lg:h-screen">
        @include('partials.sidebar', ['farmerNav' => $farmerNav, 'adminNav' => $adminNav])
    </aside>

    {{-- Mobile top bar + drawer --}}
    <header class="sticky top-0 z-30 flex items-center justify-between bg-forest-900 px-4 py-3 text-white lg:hidden">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2"><img src="{{ asset('favicon.svg') }}" alt="" class="h-6 w-6"><span class="font-display text-lg">AgriScan</span></a>
        <button type="button" @click="menu = true" aria-label="Open menu" class="p-1">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
    </header>
    <div x-show="menu" x-cloak class="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="Navigation">
        <div class="absolute inset-0 bg-forest-950/60" @click="menu = false"></div>
        <div class="absolute inset-y-0 left-0 w-72 bg-forest-900 p-4">
            <button type="button" @click="menu = false" aria-label="Close menu" class="absolute right-3 top-3 text-white">✕</button>
            @include('partials.sidebar', ['farmerNav' => $farmerNav, 'adminNav' => $adminNav])
        </div>
    </div>

    <main class="min-w-0 flex-1 px-4 py-6 sm:px-8 lg:py-10">
        <div class="mx-auto max-w-6xl">
            <div class="mb-5">@include('partials.flash')</div>
            @yield('content')
        </div>
    </main>
</div>
@endsection
