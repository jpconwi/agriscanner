{{-- Success / error banners and a generic validation summary. Field-level messages are shown next to each field. --}}
<div class="space-y-3" aria-live="polite">
    @if (session('status'))
        <div class="flex items-start gap-3 rounded-lg border border-leaf-400 bg-leaf-50 px-4 py-3 text-sm text-forest-900" role="status">
            <span aria-hidden="true">✓</span><p>{{ session('status') }}</p>
        </div>
    @endif
    @if (session('error'))
        <div class="flex items-start gap-3 rounded-lg border border-clay-600/40 bg-red-50 px-4 py-3 text-sm text-clay-600" role="alert">
            <span aria-hidden="true">!</span><p>{{ session('error') }}</p>
        </div>
    @endif
    @if ($errors->any() && ! session('error'))
        <div class="rounded-lg border border-clay-600/40 bg-red-50 px-4 py-3 text-sm text-clay-600" role="alert">
            <p class="font-medium">Please check the form:</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach (collect($errors->getBags())->flatMap(fn ($bag) => $bag->all())->unique()->take(5) as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
