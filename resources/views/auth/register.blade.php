@extends('layouts.guest')
@section('title', 'Create account')
@section('heading', 'Create your account')
@section('subheading', 'Free to start. Your photos stay private.')
@section('footer')Already registered? <a class="font-medium text-forest-800 underline" href="{{ route('login') }}">Sign in</a>@endsection

@section('content')
<form method="POST" action="{{ route('register') }}" class="space-y-4" x-data="{ busy: false }" @submit="busy = true">
    @csrf
    <x-field name="name" label="Full name"><input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required class="input"></x-field>
    <x-field name="email" label="Email"><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required class="input"></x-field>
    <x-field name="password" label="Password" hint="At least 10 characters, with letters and numbers."><input id="password" name="password" type="password" autocomplete="new-password" required class="input"></x-field>
    <x-field name="password_confirmation" label="Confirm password"><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="input"></x-field>
    <button type="submit" class="btn btn-primary w-full" :disabled="busy">Create account</button>
</form>
@endsection
