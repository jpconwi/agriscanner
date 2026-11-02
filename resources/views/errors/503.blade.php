@extends('layouts.guest')
@section('title', 'Temporarily unavailable')
@section('heading', 'Temporarily unavailable')
@section('subheading', $message ?? 'The service is temporarily unavailable. Please try again shortly.')
@section('content')
<div class="text-center"><a href="{{ url()->current() }}" class="btn btn-primary">Try again</a></div>
@endsection
