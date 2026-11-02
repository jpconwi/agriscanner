@extends('layouts.base')

@section('body')
<header class="mx-auto flex max-w-6xl items-center justify-between px-4 py-5 sm:px-8">
    <a href="{{ route('home') }}" class="flex items-center gap-2 text-forest-950">
        <img src="{{ asset('favicon.svg') }}" alt="" class="h-8 w-8"><span class="font-display text-xl">AgriScan</span>
    </a>
    <nav class="flex items-center gap-2">
        @auth
            <a href="{{ route('dashboard') }}" class="btn btn-primary btn-sm">Open dashboard</a>
        @else
            <a href="{{ route('login') }}" class="btn btn-ghost btn-sm">Sign in</a>
            <a href="{{ route('register') }}" class="btn btn-primary btn-sm">Create account</a>
        @endauth
    </nav>
</header>

@if (session('status'))
    <div class="mx-auto max-w-6xl px-4 sm:px-8">@include('partials.flash')</div>
@endif

<section class="mx-auto grid max-w-6xl items-center gap-10 px-4 pb-16 pt-10 sm:px-8 lg:grid-cols-2 lg:pt-20">
    <div>
        <p class="mb-4 inline-block rounded-full bg-leaf-100 px-3 py-1 text-xs font-medium text-forest-900">Crop health, made practical</p>
        <h1 class="text-4xl font-semibold leading-[1.1] text-forest-950 sm:text-5xl lg:text-6xl">Spot crop disease early, <span class="text-forest-700">before it spreads.</span></h1>
        <p class="mt-5 max-w-lg text-lg text-ink-soft">Upload a photo and receive a likely diagnosis, the symptoms to confirm, and treatment and prevention guidance drawn from a vetted reference library.</p>
        <div class="mt-8 flex flex-wrap gap-3">
            <a href="{{ route('register') }}" class="btn btn-primary btn-lg">Start scanning →</a>
            <a href="{{ route('login') }}" class="btn btn-secondary btn-lg">I have an account</a>
        </div>
        <p class="mt-4 text-xs text-ink-soft">AI results are advisory, not a confirmed diagnosis. Consult an agronomist for serious outbreaks.</p>
    </div>

    <div class="relative">
        <div class="rounded-3xl bg-forest-900 p-6 text-white shadow-xl">
            <div class="rounded-2xl bg-forest-950/60 p-5">
                <p class="text-xs uppercase tracking-wider text-leaf-300">Sample result</p>
                <p class="mt-2 font-display text-2xl">Tomato — Early blight</p>
                <div class="mt-4 h-2 overflow-hidden rounded-full bg-forest-800"><div class="h-full w-[82%] rounded-full bg-leaf-300"></div></div>
                <p class="mt-1 text-xs text-leaf-100">Model confidence 82%</p>
                <ul class="mt-5 space-y-2 text-sm text-leaf-100">
                    <li>• Concentric brown rings on older leaves</li>
                    <li>• Remove infected foliage and rotate crops</li>
                    <li>• Re-scan in 5–7 days to monitor spread</li>
                </ul>
            </div>
        </div>
        <div class="mt-3 text-xs text-ink-soft sm:absolute sm:-bottom-4 sm:-left-4 sm:mt-0 sm:rounded-xl sm:bg-sand-200 sm:px-4 sm:py-3 sm:text-sm sm:text-ink sm:shadow-md">Illustrative example only</div>
    </div>
</section>

<section class="border-y border-sand-200 bg-white">
    <div class="mx-auto max-w-6xl px-4 py-14 sm:px-8">
        <h2 class="text-3xl font-semibold text-forest-950">How a scan works</h2>
        <ol class="mt-8 grid gap-8 md:grid-cols-3">
            @foreach ([['Photograph the leaf', 'Take a clear, close photo of the affected plant part in daylight.'], ['AI reads the symptoms', 'An image model proposes a likely condition and how sure it is, when it can say.'], ['Get grounded advice', 'Recommendations come from a curated reference library, never from guesswork.']] as $i => [$t, $d])
                <li class="flex gap-4">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-leaf-100 font-display text-lg text-forest-800">{{ $i + 1 }}</div>
                    <div><h3 class="text-lg font-semibold text-ink">{{ $t }}</h3><p class="mt-1 text-sm text-ink-soft">{{ $d }}</p></div>
                </li>
            @endforeach
        </ol>
    </div>
</section>

<section class="mx-auto max-w-6xl px-4 py-14 sm:px-8">
    <div class="rounded-2xl bg-leaf-100 p-8 sm:p-12">
        <h2 class="text-3xl font-semibold text-forest-950">Honest by design</h2>
        <p class="mt-3 max-w-2xl text-ink-soft">If the AI service is unavailable, AgriScan tells you so instead of inventing an answer. Your photos live in private storage, and you always see how much of your storage allowance you have used.</p>
    </div>
</section>

<footer class="border-t border-sand-200 py-6 text-center text-xs text-ink-soft">© {{ date('Y') }} AgriScan</footer>
@endsection
