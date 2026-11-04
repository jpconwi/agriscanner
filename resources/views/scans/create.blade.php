@extends('layouts.app')
@section('title', 'Scan a crop')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-semibold text-forest-950 sm:text-3xl">Scan a crop</h1>
    <p class="mt-1 text-sm text-ink-soft">Upload a clear, well-lit photo of the affected leaf, fruit or stem.</p>
</div>

<div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
    <form method="POST" action="{{ route('scans.store') }}" enctype="multipart/form-data" class="card"
          x-data="scanner({{ (int) ($maxMb * 1024 * 1024) }})" @submit="onSubmit($event)">
        @csrf
        {{-- One key per selected photo: retrying the same upload never creates a duplicate scan or charges quota twice. --}}
        <input type="hidden" name="idempotency_key" x-ref="key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
        {{-- Fingerprint (name, size, date) of the photo the key belongs to. A different photo always gets a fresh key. --}}
        <input type="hidden" name="idem_fp" x-ref="fp" value="{{ old('idem_fp') }}">

        <div class="flex min-h-72 flex-col items-center justify-center rounded-xl border-2 border-dashed border-sand-200 bg-paper p-4 text-center"
             @dragover.prevent @drop.prevent="drop($event)">
            <template x-if="preview"><img :src="preview" alt="Selected crop preview" class="max-h-80 rounded-lg object-contain"></template>
            <template x-if="!preview">
                <div>
                    <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-leaf-100 text-2xl text-forest-800" aria-hidden="true">↑</div>
                    <p class="font-medium text-ink">Drag a photo here or choose a file</p>
                    <p class="mt-1 text-xs text-ink-soft">JPG, PNG or WEBP · up to {{ $maxMb }} MB</p>
                </div>
            </template>
            <input x-ref="file" type="file" name="image" accept="image/jpeg,image/png,image/webp" class="sr-only" aria-label="Choose crop photo" @change="pick($event.target.files[0])" required>
            <button type="button" class="btn btn-secondary mt-4" @click="$refs.file.click()" x-text="preview ? 'Choose a different photo' : 'Choose photo'"></button>
        </div>
        <p x-show="clientError" x-cloak class="field-error" role="alert" x-text="clientError"></p>
        @error('image')<p class="field-error" role="alert">{{ $message }}</p>@enderror

        <div class="mt-5 space-y-4">
            <x-field name="crop_id" label="Crop (optional)" hint="Choosing the crop helps match the right reference information.">
                <select id="crop_id" name="crop_id" class="input">
                    <option value="">Let the model decide</option>
                    @foreach ($crops as $crop)<option value="{{ $crop->id }}" @selected((string) old('crop_id') === (string) $crop->id)>{{ $crop->name }}</option>@endforeach
                </select>
            </x-field>

            <div x-show="busy" x-cloak aria-live="polite" class="rounded-lg bg-leaf-50 px-4 py-3 text-sm text-forest-900">
                Uploading and analysing your photo. This can take up to a minute, so please keep this page open…
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-full" :disabled="busy || !hasFile || {{ $usage['warning_level'] === 'full' ? 'true' : 'false' }}">
                <span x-show="!busy">{{ session('error') ? 'Retry analysis' : 'Analyse photo' }}</span><span x-show="busy" x-cloak>Analysing…</span>
            </button>
            @if ($usage['warning_level'] === 'full')<p class="text-xs text-clay-600">Your storage is full. Delete old scans to upload more.</p>@endif
        </div>
    </form>

    <div class="space-y-4">
        <div class="card"><h2 class="mb-3 text-base font-semibold text-forest-950">Your storage</h2><x-storage-meter :usage="$usage" /></div>
        <div class="card">
            <h2 class="mb-2 text-base font-semibold text-forest-950">Photo tips</h2>
            <ul class="list-disc space-y-1.5 pl-5 text-sm text-ink-soft">
                <li>Shoot in natural daylight and avoid harsh shadows.</li>
                <li>Fill the frame with the affected area.</li>
                <li>Keep the image in focus, one plant part per photo.</li>
            </ul>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('alpine:init', function () {
        Alpine.data('scanner', function (maxBytes) {
            return {
                preview: null, hasFile: false, busy: false, clientError: '',
                pick: function (f) {
                    this.clientError = '';
                    if (!f) { return; }
                    if (['image/jpeg', 'image/png', 'image/webp'].indexOf(f.type) === -1) { this.reject('Please choose a JPG, PNG or WEBP image.'); return; }
                    if (f.size > maxBytes) { this.reject('That image is too large. The maximum size is ' + Math.round(maxBytes / 1048576) + ' MB.'); return; }
                    if (this.preview) { URL.revokeObjectURL(this.preview); }
                    this.preview = URL.createObjectURL(f); this.hasFile = true;
                    var fp = f.name + '|' + f.size + '|' + f.lastModified;
                    if (this.$refs.fp.value !== fp) {
                        // New or different photo: new retry key. The same photo keeps its key, so a retry is never double-counted.
                        this.$refs.key.value = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (Date.now() + '-' + Math.random().toString(16).slice(2));
                        this.$refs.fp.value = fp;
                    }
                },
                reject: function (msg) { this.clientError = msg; this.$refs.file.value = ''; this.hasFile = false; this.preview = null; },
                drop: function (e) {
                    var f = e.dataTransfer.files && e.dataTransfer.files[0];
                    if (!f) { return; }
                    var dt = new DataTransfer(); dt.items.add(f); this.$refs.file.files = dt.files; this.pick(f);
                },
                onSubmit: function (e) { if (!this.hasFile) { e.preventDefault(); return; } this.busy = true; }
            };
        });
    });
</script>
@endpush
