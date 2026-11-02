@extends('layouts.guest')
@section('title', 'Something went wrong')
@section('heading', 'Something went wrong')
@section('subheading', "An unexpected error occurred on our side. Nothing was lost that we know of; please try again.")
@section('content')
<div class="text-center"><a href="{{ url('/') }}" class="btn btn-primary">Go to home</a></div>
@endsection
