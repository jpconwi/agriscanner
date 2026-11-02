@extends('layouts.guest')
@section('title', 'Page not found')
@section('heading', 'Page not found')
@section('subheading', "The page you're looking for doesn't exist or isn't yours to view.")
@section('content')
<div class="text-center"><a href="{{ url('/') }}" class="btn btn-primary">Go to home</a></div>
@endsection
