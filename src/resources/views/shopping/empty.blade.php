@extends('layouts.app')

@section('title', 'Courses')

@section('content')
    <section class="hero hero-compact">
        <p class="eyebrow">Courses</p>
        <h1>Une liste de courses d'après ton planning</h1>
        <p class="lead">Foodtruck additionne les ingrédients des repas prévus, les traduit en paquets entiers (60 cl de lait → 1 brique), les range par magasin et par rayon, et estime le coût.</p>
    </section>

    <section class="panel">
        <h2>Créer la liste</h2>
        @include('shopping._period', ['from' => $from, 'to' => $to, 'action' => route('shopping.store'), 'method' => null, 'button' => 'Calculer la liste'])
        <p class="hint small">Choisis les jours que tu veux couvrir : par exemple du jour de tes prochaines courses jusqu'aux suivantes. Tu pourras écarter des repas (invités ailleurs, restaurant) ensuite.</p>
    </section>

    @include('shopping._previous', ['previous' => $previous])
@endsection
