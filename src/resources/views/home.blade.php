@extends('layouts.app')

@section('title', 'Accueil')

@section('content')
    @php
        $household = auth()->user()->household->loadMissing('members', 'equipment');
    @endphp

    <section class="hero">
        <h1>Bonjour {{ auth()->user()->firstName() }}</h1>
        <p class="lead">Foyer « {{ $household->name }} » : les modules arrivent étape par étape, chacun validé avant le suivant.</p>
    </section>

    @php
        $modules = [
            ['Ingrédients et prix', 'Unités, rayons, magasins, conditionnements', 'v0.3.0'],
            ['Recettes', 'Saisie, étapes, photos, étiquettes, recettes de saison', 'v0.4.0'],
            ['Planning', 'Repas choisis librement, cumulables', 'v0.6.0'],
            ['Liste de courses', 'Mutualisée, par magasin et par rayon', 'v0.7.0'],
            ['Économies', 'Budget, tickets de caisse, anti-gaspi', 'v0.8.0'],
        ];
        $portions = rtrim(rtrim(number_format($household->totalPortions(), 2, ',', ' '), '0'), ',');
    @endphp

    <section class="grid">
        <a class="card card-link" href="{{ route('household.show') }}">
            <h2>Mon foyer</h2>
            <p>
                {{ $household->members->count() }} personne{{ $household->members->count() > 1 ? 's' : '' }} ·
                {{ $portions }} parts par repas<br>
                Budget {{ number_format($household->budgetEuros(), 0, ',', ' ') }} € / semaine ·
                {{ $household->equipment->count() }} appareil{{ $household->equipment->count() > 1 ? 's' : '' }}
            </p>
            <span class="tag tag-accent">Disponible</span>
        </a>
        @foreach ($modules as [$name, $desc, $version])
            <article class="card is-soon">
                <h2>{{ $name }}</h2>
                <p>{{ $desc }}</p>
                <span class="tag">Prévu en {{ $version }}</span>
            </article>
        @endforeach
    </section>
@endsection
