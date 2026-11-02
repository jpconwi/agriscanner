@extends('layouts.guest')
@section('title', 'Not allowed')
@section('heading', 'Not allowed')
@section('subheading', "You don't have permission to view this page.")
@section('content')
<div class="text-center"><a href="{{ url('/') }}" class="btn btn-primary">Go to home</a></div>
@endsection
