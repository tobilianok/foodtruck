@extends('layouts.guest')

@section('title', 'Déconnecté')

@section('content')
    <h1>À bientôt</h1>
    <p>Tu es déconnecté de Foodtruck.</p>
    <a class="btn" href="{{ route('login') }}">Se reconnecter</a>
@endsection
