@extends('layouts.guest')
@section('title', 'Too many requests')
@section('heading', 'Too many requests')
@section('subheading', "Please slow down and try again in a minute.")
@section('content')
<div class="text-center"><a href="{{ url('/') }}" class="btn btn-primary">Go to home</a></div>
@endsection
