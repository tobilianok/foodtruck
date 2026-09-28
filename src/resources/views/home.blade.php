@extends('layouts.app')

@section('title', 'Accueil')

@section('content')
    <section class="hero">
        <h1>Bonjour {{ auth()->user()->firstName() }}</h1>
        <p class="lead">Le socle de Foodtruck est en place. Les modules arrivent étape par étape, chacun validé avant le suivant.</p>
        @if (auth()->user()->isAdmin())
            <p class="note">Tu es administrateur : le premier compte connecté reçoit ce rôle automatiquement.</p>
        @endif
    </section>

    @php
        $modules = [
            ['Mon foyer', 'Membres, portions, appareils de cuisine', 'v0.2.0'],
            ['Ingrédients et prix', 'Unités, rayons, magasins, conditionnements', 'v0.3.0'],
            ['Recettes', 'Saisie, étapes, photos, étiquettes, recettes de saison', 'v0.4.0'],
            ['Planning', 'Repas choisis librement, cumulables', 'v0.6.0'],
            ['Liste de courses', 'Mutualisée, par magasin et par rayon', 'v0.7.0'],
            ['Économies', 'Budget 100 €, tickets de caisse, anti-gaspi', 'v0.8.0'],
        ];
    @endphp

    <section class="grid">
        @foreach ($modules as [$name, $desc, $version])
            <article class="card is-soon">
                <h2>{{ $name }}</h2>
                <p>{{ $desc }}</p>
                <span class="tag">Prévu en {{ $version }}</span>
            </article>
        @endforeach
    </section>
@endsection
