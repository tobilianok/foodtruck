@extends('layouts.app')

@section('title', 'Ingrédients')

@section('content')
    <section class="hero hero-compact">
        <div class="hero-row">
            <div>
                <h1>Ingrédients</h1>
                <p class="lead">{{ $total }} ingrédients, avec leurs conditionnements et les prix connus par magasin.</p>
            </div>
            <div class="hero-actions">
                <a class="btn btn-ghost" href="{{ route('prices.index') }}">Mettre à jour les prix</a>
                <a class="btn" href="{{ route('ingredients.create') }}">+ Nouvel ingrédient</a>
            </div>
        </div>
    </section>

    <form method="get" action="{{ route('ingredients.index') }}" class="panel filters">
        <label class="field">
            <span>Rechercher</span>
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="carotte, lait, farine…">
        </label>
        <label class="field">
            <span>Rayon</span>
            <select name="rayon">
                <option value="">Tous les rayons</option>
                @foreach ($aisles as $aisle)
                    <option value="{{ $aisle->slug }}" @selected(($filters['rayon'] ?? '') === $aisle->slug)>{{ $aisle->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="check">
            <input type="checkbox" name="saison" value="1" @checked(! empty($filters['saison']))>
            <span>De saison en {{ \App\Models\Ingredient::MONTHS[$month] }}</span>
        </label>
        <div class="row-actions">
            <button type="submit" class="btn btn-small">Filtrer</button>
            @if (array_filter($filters))
                <a class="btn btn-small btn-ghost" href="{{ route('ingredients.index') }}">Tout afficher</a>
            @endif
        </div>
    </form>

    @forelse ($aisles->filter(fn ($a) => $groups->has($a->id)) as $aisle)
        <section class="panel">
            <h2>{{ $aisle->name }} <span class="count">{{ $groups[$aisle->id]->count() }}</span></h2>
            <ul class="ingredient-list">
                @foreach ($groups[$aisle->id] as $ingredient)
                    @php $best = $ingredient->bestOffer(); @endphp
                    <li>
                        <a href="{{ route('ingredients.show', $ingredient) }}" class="ingredient-link">
                            <span class="ingredient-name">
                                {{ $ingredient->name }}
                                @if ($ingredient->isInSeason($month) === true)
                                    <span class="badge-season" title="De saison : {{ $ingredient->seasonLabel() }}">de saison</span>
                                @elseif ($ingredient->isInSeason($month) === false)
                                    <span class="badge-off" title="Saison : {{ $ingredient->seasonLabel() }}">hors saison</span>
                                @endif
                                @if ($ingredient->is_staple)
                                    <span class="badge-staple" title="Produit de base, souvent déjà en stock">de base</span>
                                @endif
                            </span>
                            <span class="ingredient-price">
                                @if ($best)
                                    <strong>{{ \App\Models\Price::formatCents($best['per_reference_cents']) }}/{{ $ingredient->referenceUnit() }}</strong>
                                    <span class="muted">{{ $stores[$best['price']->store_id]->name ?? '' }}@if ($best['price']->source === 'estimation') · estimé @endif</span>
                                @else
                                    <span class="muted">pas de prix</span>
                                @endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <div class="panel"><p>Aucun ingrédient ne correspond. <a href="{{ route('ingredients.create') }}">L'ajouter ?</a></p></div>
    @endforelse
@endsection
