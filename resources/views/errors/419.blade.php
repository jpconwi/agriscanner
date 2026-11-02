@extends('layouts.guest')
@section('title', 'Session expired')
@section('heading', 'Session expired')
@section('subheading', "Your session timed out. Go back, refresh the page and try again.")
@section('content')
<div class="text-center"><a href="{{ url('/') }}" class="btn btn-primary">Go to home</a></div>
@endsection
