@extends('layouts.guest')
@section('title', 'Reset password')
@section('heading', 'Reset your password')
@section('subheading', 'Enter your email and we will send a reset link if an account exists.')
@section('footer')<a class="font-medium text-forest-800 underline" href="{{ route('login') }}">Back to sign in</a>@endsection

@section('content')
<form method="POST" action="{{ route('password.email') }}" class="space-y-4" x-data="{ busy: false }" @submit="busy = true">
    @csrf
    <x-field name="email" label="Email"><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required class="input"></x-field>
    <button type="submit" class="btn btn-primary w-full" :disabled="busy">Send reset link</button>
</form>
@endsection
