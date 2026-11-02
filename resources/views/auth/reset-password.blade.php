@extends('layouts.guest')
@section('title', 'Choose a new password')
@section('heading', 'Choose a new password')
@section('subheading', 'Use at least 10 characters, with letters and numbers.')
@section('footer')<a class="font-medium text-forest-800 underline" href="{{ route('login') }}">Back to sign in</a>@endsection

@section('content')
<form method="POST" action="{{ route('password.update') }}" class="space-y-4" x-data="{ busy: false }" @submit="busy = true">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <x-field name="email" label="Email"><input id="email" name="email" type="email" value="{{ old('email', $email) }}" required class="input"></x-field>
    <x-field name="password" label="New password"><input id="password" name="password" type="password" autocomplete="new-password" required class="input"></x-field>
    <x-field name="password_confirmation" label="Confirm password"><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="input"></x-field>
    <button type="submit" class="btn btn-primary w-full" :disabled="busy">Update password</button>
</form>
@endsection
