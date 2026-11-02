<div class="flex h-full flex-col">
    <a href="{{ route('dashboard') }}" class="flex items-center gap-2 px-2 pb-6 pt-1 text-white">
        <img src="{{ asset('favicon.svg') }}" alt="" class="h-8 w-8"><span class="font-display text-xl">AgriScan</span>
    </a>
    <div class="flex-1 space-y-6 overflow-y-auto">
        <nav aria-label="Farm">
            <p class="mb-2 px-3 text-xs font-medium uppercase tracking-wider text-leaf-300/80">Farm</p>
            <div class="space-y-1">
                @foreach ($farmerNav as [$route, $label, $match, $icon])
                    <a href="{{ route($route) }}" @if (request()->routeIs(...(array) $match)) aria-current="page" @endif
                       class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors {{ request()->routeIs(...(array) $match) ? 'bg-leaf-300 text-forest-950' : 'text-leaf-100 hover:bg-forest-800' }}">
                        <svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="{{ $icon }}"/></svg>{{ $label }}
                    </a>
                @endforeach
            </div>
        </nav>
        {{-- Cosmetic only: every admin route is also protected by the `admin` middleware. --}}
        @if (auth()->user()->isAdmin())
            <nav aria-label="Administration">
                <p class="mb-2 px-3 text-xs font-medium uppercase tracking-wider text-leaf-300/80">Administration</p>
                <div class="space-y-1">
                    @foreach ($adminNav as [$route, $label, $match, $icon])
                        <a href="{{ route($route) }}" @if (request()->routeIs(...(array) $match)) aria-current="page" @endif
                           class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors {{ request()->routeIs(...(array) $match) ? 'bg-leaf-300 text-forest-950' : 'text-leaf-100 hover:bg-forest-800' }}">
                            <svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="{{ $icon }}"/></svg>{{ $label }}
                        </a>
                    @endforeach
                </div>
            </nav>
        @endif
    </div>
    <div class="mt-4 border-t border-forest-800 pt-4">
        <p class="truncate px-3 text-sm font-medium text-white">{{ auth()->user()->name }}</p>
        <p class="truncate px-3 pb-2 text-xs text-leaf-100/80">{{ auth()->user()->email }}</p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-leaf-100 hover:bg-forest-800">Sign out</button>
        </form>
    </div>
</div>
