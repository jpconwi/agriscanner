@php
    $k = $disease?->id ?? 'new';
    $lines = fn ($rows, $kind) => $rows->where('kind', $kind)->pluck('text')->implode("\n");
    $v = [
        'symptoms' => $disease ? $lines($disease->symptomRows, 'symptom') : '',
        'contributing_factors' => $disease ? $lines($disease->symptomRows, 'factor') : '',
        'treatments' => $disease ? $lines($disease->treatmentRows, 'treatment') : '',
        'prevention' => $disease ? $lines($disease->treatmentRows, 'prevention') : '',
    ];
@endphp
<form method="POST" action="{{ $action }}" class="space-y-4">
    @csrf
    @if ($disease) @method('PUT') @endif
    <x-field name="name" label="Name" for="d-name-{{ $k }}"><input id="d-name-{{ $k }}" name="name" value="{{ $disease?->name }}" required class="input"></x-field>
    <div class="grid grid-cols-2 gap-3">
        <x-field name="crop_id" label="Crop" for="d-crop-{{ $k }}">
            <select id="d-crop-{{ $k }}" name="crop_id" class="input">
                <option value="">Any crop</option>
                @foreach ($crops as $c)<option value="{{ $c->id }}" @selected($disease?->crop_id === $c->id)>{{ $c->name }}</option>@endforeach
            </select>
        </x-field>
        <x-field name="severity" label="Severity" for="d-sev-{{ $k }}">
            <select id="d-sev-{{ $k }}" name="severity" class="input">
                @foreach (['low' => 'Low', 'moderate' => 'Moderate', 'high' => 'High'] as $val => $label)<option value="{{ $val }}" @selected(($disease?->severity ?? 'moderate') === $val)>{{ $label }}</option>@endforeach
            </select>
        </x-field>
    </div>
    <x-field name="description" label="Description" for="d-desc-{{ $k }}"><textarea id="d-desc-{{ $k }}" name="description" rows="3" class="input">{{ $disease?->description }}</textarea></x-field>
    <x-field name="symptoms" label="Symptoms (one per line)" for="d-sym-{{ $k }}"><textarea id="d-sym-{{ $k }}" name="symptoms" rows="3" class="input">{{ $v['symptoms'] }}</textarea></x-field>
    <x-field name="contributing_factors" label="Contributing factors (one per line)" for="d-fac-{{ $k }}"><textarea id="d-fac-{{ $k }}" name="contributing_factors" rows="3" class="input">{{ $v['contributing_factors'] }}</textarea></x-field>
    <x-field name="treatments" label="Treatments (one per line)" for="d-tre-{{ $k }}"><textarea id="d-tre-{{ $k }}" name="treatments" rows="3" class="input">{{ $v['treatments'] }}</textarea></x-field>
    <x-field name="prevention" label="Prevention (one per line)" for="d-pre-{{ $k }}"><textarea id="d-pre-{{ $k }}" name="prevention" rows="3" class="input">{{ $v['prevention'] }}</textarea></x-field>
    <x-field name="follow_up" label="Follow-up monitoring" for="d-fol-{{ $k }}"><textarea id="d-fol-{{ $k }}" name="follow_up" rows="2" class="input">{{ $disease?->follow_up }}</textarea></x-field>
    <div class="flex justify-end"><button type="submit" class="btn btn-primary">Save</button></div>
</form>
