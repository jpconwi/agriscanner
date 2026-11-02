@extends('layouts.app')
@section('title', 'Profile')

@php $p = $user->profile; @endphp

@section('content')
<div class="mb-6"><h1 class="text-2xl font-semibold text-forest-950 sm:text-3xl">Profile</h1><p class="mt-1 text-sm text-ink-soft">Your farm details and account security.</p></div>

<div class="grid gap-6 lg:grid-cols-2">
    <form method="POST" action="{{ route('profile.update') }}" class="card space-y-4" x-data="{ busy: false }" @submit="busy = true">
        @csrf @method('PUT')
        <h2 class="text-lg font-semibold text-forest-950">Farm profile</h2>
        <x-field name="name" label="Full name"><input id="name" name="name" value="{{ old('name', $user->name) }}" required class="input"></x-field>
        <x-field name="email" label="Email" hint="Email cannot be changed here."><input id="email" value="{{ $user->email }}" disabled class="input opacity-70"></x-field>
        <x-field name="phone" label="Phone"><input id="phone" name="phone" value="{{ old('phone', $p?->phone) }}" class="input"></x-field>
        <x-field name="farm_name" label="Farm name"><input id="farm_name" name="farm_name" value="{{ old('farm_name', $p?->farm_name) }}" class="input"></x-field>
        <x-field name="location" label="Location"><input id="location" name="location" value="{{ old('location', $p?->location) }}" placeholder="Municipality, Province" class="input"></x-field>
        <div class="grid grid-cols-2 gap-4">
            <x-field name="farm_size_ha" label="Farm size (ha)"><input id="farm_size_ha" name="farm_size_ha" type="number" min="0" step="0.01" value="{{ old('farm_size_ha', $p?->farm_size_ha) }}" class="input"></x-field>
            <x-field name="main_crops" label="Main crops"><input id="main_crops" name="main_crops" value="{{ old('main_crops', $p?->main_crops) }}" placeholder="Rice, corn" class="input"></x-field>
        </div>
        <button type="submit" class="btn btn-primary" :disabled="busy">Save changes</button>
    </form>

    <div class="space-y-6">
        <form method="POST" action="{{ route('profile.password') }}" class="card space-y-4">
            @csrf @method('PUT')
            <h2 class="text-lg font-semibold text-forest-950">Change password</h2>
            <x-field name="current_password" bag="password" label="Current password"><input id="current_password" name="current_password" type="password" autocomplete="current-password" class="input"></x-field>
            <x-field name="password" bag="password" label="New password" hint="At least 10 characters, with letters and numbers."><input id="password" name="password" type="password" autocomplete="new-password" class="input"></x-field>
            <x-field name="password_confirmation" bag="password" label="Confirm new password"><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="input"></x-field>
            <button type="submit" class="btn btn-secondary">Update password</button>
            <p class="hint">Changing your password signs you out of your other browsers.</p>
        </form>

        <div class="card border-clay-600/30">
            <h2 class="text-lg font-semibold text-clay-600">Delete account</h2>
            <p class="mb-4 mt-1 text-sm text-ink-soft">Permanently removes your account, scans and stored photos. This cannot be undone.</p>
            <x-modal title="Confirm account deletion">
                <x-slot:trigger><button type="button" class="btn btn-danger">Delete my account</button></x-slot:trigger>
                <form method="POST" action="{{ route('profile.destroy') }}" class="space-y-4" x-data="{ busy: false }" @submit="busy = true">
                    @csrf @method('DELETE')
                    <p class="text-sm text-ink-soft">Enter your password to confirm. All photos will be removed from storage first.</p>
                    <x-field name="password" bag="delete" label="Password" for="delete_password"><input id="delete_password" name="password" type="password" autocomplete="current-password" required class="input"></x-field>
                    <div class="flex justify-end"><button type="submit" class="btn btn-danger" :disabled="busy">Delete permanently</button></div>
                </form>
            </x-modal>
        </div>
    </div>
</div>
@endsection
