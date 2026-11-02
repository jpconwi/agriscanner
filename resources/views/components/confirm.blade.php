{{-- Confirmation dialog for destructive actions. Posts a real form (CSRF + method spoofing), so it works for any route. --}}
@props(['action', 'title' => 'Are you sure?', 'message' => '', 'confirm' => 'Delete', 'method' => 'DELETE', 'class' => 'btn btn-ghost btn-sm text-clay-600', 'disabled' => false])
<div x-data="{ open: false }" class="inline-block" @keydown.escape.window="open = false">
    <button type="button" @click="open = true" class="{{ $class }}" @disabled($disabled)>{{ $slot }}</button>
    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" role="alertdialog" aria-modal="true" aria-label="{{ $title }}">
            <div class="absolute inset-0 bg-forest-950/50" @click="open = false"></div>
            <div class="relative w-full max-w-md rounded-2xl bg-white p-6 text-left shadow-xl" @click.stop>
                <h2 class="text-xl font-semibold text-forest-950">{{ $title }}</h2>
                <p class="mt-2 text-sm text-ink-soft">{{ $message }}</p>
                <form method="POST" action="{{ $action }}" class="mt-6 flex justify-end gap-2" x-data="{ busy: false }" @submit="busy = true">
                    @csrf
                    @method($method)
                    <button type="button" class="btn btn-secondary" @click="open = false">Cancel</button>
                    <button type="submit" class="btn btn-danger" :disabled="busy">{{ $confirm }}</button>
                </form>
            </div>
        </div>
    </template>
</div>
