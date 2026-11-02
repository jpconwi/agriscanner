@props(['name', 'label' => null, 'hint' => null, 'bag' => 'default', 'for' => null])
@php $error = $errors->getBag($bag)->first($name); @endphp
<div {{ $attributes->only('class') }}>
    @if ($label)<label for="{{ $for ?? $name }}" class="label">{{ $label }}</label>@endif
    {{ $slot }}
    @if ($hint && ! $error)<p class="hint">{{ $hint }}</p>@endif
    @if ($error)<p class="field-error" role="alert">{{ $error }}</p>@endif
</div>
