@extends('layouts.app')
@section('title', 'Manage advisories')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div><h1 class="text-2xl font-semibold text-forest-950 sm:text-3xl">Advisories</h1><p class="mt-1 text-sm text-ink-soft">Write and publish farm advisory articles.</p></div>
    <x-modal title="New advisory" size="max-w-2xl">
        <x-slot:trigger><button type="button" class="btn btn-primary">+ New advisory</button></x-slot:trigger>
        @include('admin._advisory-form', ['action' => route('admin.advisories.store'), 'advisory' => null])
    </x-modal>
</div>

@if ($advisories->isEmpty())
    <div class="rounded-xl border border-dashed border-sand-200 bg-white/60 px-6 py-14 text-center text-sm text-ink-soft">No advisories yet.</div>
@else
    <div class="space-y-3">
        @foreach ($advisories as $a)
            <div class="card flex items-center justify-between gap-3 p-4">
                <div class="min-w-0">
                    <div class="mb-1 flex flex-wrap items-center gap-2"><span class="badge {{ $a->is_published ? 'badge-success' : 'badge-neutral' }}">{{ $a->is_published ? 'Published' : 'Draft' }}</span>@if ($a->category)<span class="text-xs text-ink-soft">{{ $a->category }}</span>@endif</div>
                    <p class="truncate font-medium text-ink">{{ $a->title }}</p>
                    <p class="text-xs text-ink-soft">Updated @date($a->updated_at)</p>
                </div>
                <div class="flex shrink-0">
                    <x-modal title="Edit advisory" size="max-w-2xl">
                        <x-slot:trigger><button type="button" class="btn btn-ghost btn-sm" aria-label="Edit {{ $a->title }}">Edit</button></x-slot:trigger>
                        @include('admin._advisory-form', ['action' => route('admin.advisories.update', $a), 'advisory' => $a])
                    </x-modal>
                    <x-confirm :action="route('admin.advisories.destroy', $a)" title="Delete advisory?" :message="'“'.$a->title.'” will be permanently removed.'">Delete</x-confirm>
                </div>
            </div>
        @endforeach
    </div>
    <x-pager :paginator="$advisories" />
@endif
@endsection
