@extends('layouts.guest')

@section('title', 'Connexion impossible')

@section('content')
    <h1>Connexion impossible</h1>
    <p>{{ $message }}</p>
    <a class="btn" href="{{ route('login') }}">Réessayer</a>
@endsection
