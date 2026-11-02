@extends('layouts.app')
@section('title', 'Analysis results')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <h1 class="text-2xl font-semibold text-forest-950 sm:text-3xl">Analysis results</h1>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('scans.create') }}" class="btn btn-secondary">Scan another</a>
        <a href="{{ route('scans.index') }}" class="btn btn-secondary">History</a>
        <x-confirm :action="route('scans.destroy', $scan['id'])" title="Delete this scan?" message="The photo and its analysis will be permanently removed from every storage location and the space returned to your quota. This cannot be undone." class="btn btn-danger">Delete</x-confirm>
    </div>
</div>
@include('scans._result', ['scan' => $scan])
@endsection
