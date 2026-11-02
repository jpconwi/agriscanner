@php $k = $crop?->id ?? 'new'; @endphp
<form method="POST" action="{{ $action }}" class="space-y-4">
    @csrf
    @if ($crop) @method('PUT') @endif
    <x-field name="name" label="Name" for="crop-name-{{ $k }}"><input id="crop-name-{{ $k }}" name="name" value="{{ $crop?->name }}" required class="input"></x-field>
    <x-field name="scientific_name" label="Scientific name" for="crop-sci-{{ $k }}"><input id="crop-sci-{{ $k }}" name="scientific_name" value="{{ $crop?->scientific_name }}" class="input"></x-field>
    <x-field name="description" label="Description" for="crop-desc-{{ $k }}"><textarea id="crop-desc-{{ $k }}" name="description" rows="3" class="input">{{ $crop?->description }}</textarea></x-field>
    <div class="flex justify-end"><button type="submit" class="btn btn-primary">Save</button></div>
</form>
