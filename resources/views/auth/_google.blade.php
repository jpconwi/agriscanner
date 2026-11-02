@if (filled(config('services.google.client_id')))
    <div class="my-5 flex items-center gap-3 text-xs text-ink-soft" aria-hidden="true"><span class="h-px flex-1 bg-sand-200"></span>or<span class="h-px flex-1 bg-sand-200"></span></div>
    <a href="{{ route('auth.google') }}" class="btn btn-secondary w-full">Continue with Google</a>
@endif
