@extends('layouts.app')

@section('title', 'Courses')

@section('content')
    <x-page-header title="Ta liste de courses"
                   lead="Foodtruck additionne les ingrédients des repas prévus, les traduit en paquets entiers (60 cl de lait : 1 brique), les range par magasin et par rayon, et estime le coût." />

    <section class="panel">
        <h2>Quels jours veux-tu couvrir ?</h2>
        @include('shopping._period', ['from' => $from, 'to' => $to, 'action' => route('shopping.store'), 'method' => null, 'button' => 'Calculer la liste'])
        <p class="hint small">Par exemple du jour de tes prochaines courses jusqu'aux suivantes. Tu pourras écarter des repas (invités ailleurs, restaurant) ensuite.</p>
        <p class="hint small">Pas encore de repas ? <a href="{{ route('planning.index') }}">Commence par le planning</a>.</p>
    </section>

    @include('shopping._previous', ['previous' => $previous])
@endsection
