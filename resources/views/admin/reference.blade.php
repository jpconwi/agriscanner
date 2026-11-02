@extends('layouts.app')
@section('title', 'Crops & diseases')

@section('content')
<div class="mb-6"><h1 class="text-2xl font-semibold text-forest-950 sm:text-3xl">Crops &amp; diseases</h1><p class="mt-1 text-sm text-ink-soft">The curated reference data that grounds every recommendation.</p></div>

{{-- Crops --}}
<section class="mb-10" aria-labelledby="crops-h">
    <div class="mb-3 flex items-center justify-between">
        <h2 id="crops-h" class="text-xl font-semibold text-forest-950">Crops</h2>
        <x-modal title="Add crop">
            <x-slot:trigger><button type="button" class="btn btn-primary btn-sm">+ Add crop</button></x-slot:trigger>
            @include('admin._crop-form', ['action' => route('admin.crops.store'), 'crop' => null])
        </x-modal>
    </div>
    @if ($crops->isEmpty())
        <div class="rounded-xl border border-dashed border-sand-200 bg-white/60 px-6 py-10 text-center text-sm text-ink-soft">No crops yet. Add crops so diseases can be linked to them.</div>
    @else
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($crops as $c)
                <div class="card flex items-start justify-between gap-2 p-4">
                    <div class="min-w-0"><p class="font-medium text-ink">{{ $c->name }}</p>@if ($c->scientific_name)<p class="text-xs italic text-ink-soft">{{ $c->scientific_name }}</p>@endif<p class="mt-1 text-xs text-ink-soft">{{ $c->diseases_count }} diseases</p></div>
                    <div class="flex shrink-0">
                        <x-modal title="Edit crop">
                            <x-slot:trigger><button type="button" class="btn btn-ghost btn-sm" aria-label="Edit {{ $c->name }}">Edit</button></x-slot:trigger>
                            @include('admin._crop-form', ['action' => route('admin.crops.update', $c), 'crop' => $c])
                        </x-modal>
                        <x-confirm :action="route('admin.crops.destroy', $c)" title="Delete crop?" :message="'Deleting '.$c->name.' is blocked while diseases still reference it.'">Delete</x-confirm>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</section>

{{-- Diseases --}}
<section aria-labelledby="dis-h">
    <div class="mb-3 flex items-center justify-between">
        <h2 id="dis-h" class="text-xl font-semibold text-forest-950">Diseases</h2>
        <x-modal title="Add disease" size="max-w-2xl">
            <x-slot:trigger><button type="button" class="btn btn-primary btn-sm">+ Add disease</button></x-slot:trigger>
            @include('admin._disease-form', ['action' => route('admin.diseases.store'), 'disease' => null, 'crops' => $crops])
        </x-modal>
    </div>
    <p class="mb-4 text-sm text-ink-soft">Recommendations shown to farmers come only from this library. Add product names or application rates only when they come from a verified label or your local extension office.</p>

    @if ($diseases->isEmpty())
        <div class="rounded-xl border border-dashed border-sand-200 bg-white/60 px-6 py-10 text-center text-sm text-ink-soft">No diseases yet.</div>
    @else
        <div class="card overflow-x-auto p-0">
            <table class="w-full min-w-[34rem] text-left text-sm">
                <thead class="border-b border-sand-200 bg-paper"><tr><th class="th">Disease</th><th class="th">Crop</th><th class="th">Severity</th><th class="th"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody class="divide-y divide-sand-200">
                    @foreach ($diseases as $d)
                        <tr>
                            <td class="td font-medium text-ink">{{ $d->name }}</td>
                            <td class="td text-ink-soft">{{ $d->crop?->name ?? 'Any crop' }}</td>
                            <td class="td"><span class="badge {{ $d->severity === 'high' ? 'badge-danger' : ($d->severity === 'low' ? 'badge-success' : 'badge-warning') }}">{{ $d->severity }}</span></td>
                            <td class="td"><div class="flex justify-end">
                                <x-modal title="Edit disease" size="max-w-2xl">
                                    <x-slot:trigger><button type="button" class="btn btn-ghost btn-sm" aria-label="Edit {{ $d->name }}">Edit</button></x-slot:trigger>
                                    @include('admin._disease-form', ['action' => route('admin.diseases.update', $d), 'disease' => $d, 'crops' => $crops])
                                </x-modal>
                                <x-confirm :action="route('admin.diseases.destroy', $d)" title="Delete disease?" :message="'Past scans keep their saved results, but '.$d->name.' will no longer be used for new recommendations.'">Delete</x-confirm>
                            </div></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <x-pager :paginator="$diseases" />
    @endif
</section>
@endsection
