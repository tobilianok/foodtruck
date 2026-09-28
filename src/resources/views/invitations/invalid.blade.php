@extends('layouts.guest')

@section('title', 'Invitation')

@section('content')
    <h1>Invitation</h1>
    <p>{{ $message }}</p>
    <a class="btn" href="{{ route('login') }}">Revenir à Foodtruck</a>
@endsection
