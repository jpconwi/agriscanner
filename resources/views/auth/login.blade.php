@extends('layouts.guest')
@section('title', 'Sign in')
@section('heading', 'Sign in')
@section('subheading', 'Access your scans and crop health dashboard.')
@section('footer')New to AgriScan? <a class="font-medium text-forest-800 underline" href="{{ route('register') }}">Create an account</a>@endsection

@section('content')
<form method="POST" action="{{ route('login') }}" class="space-y-4" x-data="{ busy: false }" @submit="busy = true">
    @csrf
    <x-field name="email" label="Email"><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus class="input"></x-field>
    <x-field name="password" label="Password"><input id="password" name="password" type="password" autocomplete="current-password" required class="input"></x-field>
    <div class="text-right"><a class="text-sm text-forest-800 underline" href="{{ route('password.request') }}">Forgot password?</a></div>
    <button type="submit" class="btn btn-primary w-full" :disabled="busy">Sign in</button>
</form>
@include('auth._google')
@endsection
