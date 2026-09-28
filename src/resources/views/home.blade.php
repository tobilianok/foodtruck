@extends('layouts.app')

@section('title', 'Accueil')

@section('content')
    @php
        $household = auth()->user()->household->loadMissing('members', 'equipment', 'mainStore', 'produceStore');
    @endphp

    <section class="hero">
        <h1>Bonjour {{ auth()->user()->firstName() }}</h1>
        <p class="lead">Foyer « {{ $household->name }} » : les modules arrivent étape par étape, chacun validé avant le suivant.</p>
    </section>

    @php
        $modules = [
            ['Recette proratisée', 'Quantités ajustées au nombre de personnes', 'v0.5.0'],
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
        <a class="card card-link" href="{{ route('recipes.index') }}">
            <h2>Recettes</h2>
            <p>
                {{ \App\Models\Recipe::visibleTo(auth()->user())->count() }} recettes partagées<br>
                {{ auth()->user()->favoriteRecipes()->count() }} en favoris
            </p>
            <span class="tag tag-accent">Disponible</span>
        </a>
        <a class="card card-link" href="{{ route('ingredients.index') }}">
            <h2>Ingrédients et prix</h2>
            <p>
                {{ \App\Models\Ingredient::count() }} ingrédients, prix par magasin<br>
                @if ($household->mainStore) Magasin principal : {{ $household->mainStore->name }} @endif
                @if ($household->produceStore) · fruits et légumes : {{ $household->produceStore->name }} @endif
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
