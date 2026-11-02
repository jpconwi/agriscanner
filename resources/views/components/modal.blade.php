{{-- Alpine modal. Usage: <x-modal title="Edit"> <x-slot:trigger> button markup </x-slot:trigger> body </x-modal>
     The trigger slot receives `open = true`; the dialog teleports to <body> so it is never clipped by tables or cards. --}}
@props(['title', 'size' => 'max-w-lg'])
<div x-data="{ open: false }" class="inline-block" @keydown.escape.window="open = false">
    <span @click="open = true">{{ $trigger }}</span>
    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-label="{{ $title }}">
            <div class="absolute inset-0 bg-forest-950/50" @click="open = false"></div>
            <div class="relative max-h-[90vh] w-full {{ $size }} overflow-y-auto rounded-2xl bg-white p-6 text-left shadow-xl" @click.stop>
                <div class="mb-4 flex items-start justify-between gap-4">
                    <h2 class="text-xl font-semibold text-forest-950">{{ $title }}</h2>
                    <button type="button" @click="open = false" aria-label="Close" class="text-ink-soft hover:text-ink">✕</button>
                </div>
                {{ $slot }}
            </div>
        </div>
    </template>
</div>
