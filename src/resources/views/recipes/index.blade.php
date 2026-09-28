@extends('layouts.app')

@section('title', 'Recettes')

@section('content')
    <section class="hero hero-compact">
        <div class="hero-row">
            <div>
                <h1>Recettes</h1>
                <p class="lead">{{ $total }} recettes partagées par tous les comptes. Le coût est estimé au meilleur prix connu.</p>
            </div>
            <div class="hero-actions">
                <a class="btn" href="{{ route('recipes.create') }}">+ Nouvelle recette</a>
            </div>
        </div>
    </section>

    <form method="get" action="{{ route('recipes.index') }}" class="panel filters filters-recipes">
        <label class="field">
            <span>Rechercher</span>
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="potimarron, lentilles, cookies…">
        </label>
        <label class="field">
            <span>Catégorie</span>
            <select name="categorie">
                <option value="">Toutes</option>
                @foreach (\App\Models\Recipe::CATEGORIES as $value => [$label, $icon])
                    <option value="{{ $value }}" @selected(($filters['categorie'] ?? '') === $value)>{{ $icon }} {{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="field">
            <span>Étiquette</span>
            <select name="etiquette">
                <option value="">Toutes</option>
                @foreach ($tags as $tag)
                    <option value="{{ $tag->slug }}" @selected(($filters['etiquette'] ?? '') === $tag->slug)>{{ $tag->name }}</option>
                @endforeach
            </select>
        </label>
        <div class="filter-checks">
            <label class="check"><input type="checkbox" name="saison" value="1" @checked(! empty($filters['saison']))><span>De saison</span></label>
            <label class="check"><input type="checkbox" name="rapide" value="1" @checked(! empty($filters['rapide']))><span>30 min max</span></label>
            <label class="check"><input type="checkbox" name="appareils" value="1" @checked(! empty($filters['appareils']))><span>Faisable avec mes appareils</span></label>
            <label class="check"><input type="checkbox" name="favoris" value="1" @checked(! empty($filters['favoris']))><span>Mes favoris</span></label>
            <label class="check"><input type="checkbox" name="brouillons" value="1" @checked(! empty($filters['brouillons']))><span>Mes brouillons</span></label>
        </div>
        <div class="row-actions">
            <button type="submit" class="btn btn-small">Filtrer</button>
            @if (array_filter($filters))
                <a class="btn btn-small btn-ghost" href="{{ route('recipes.index') }}">Tout afficher</a>
            @endif
        </div>
    </form>

    @if ($recipes->isEmpty())
        <div class="panel"><p>Aucune recette ne correspond. <a href="{{ route('recipes.create') }}">En saisir une ?</a></p></div>
    @else
        <p class="muted">{{ $recipes->count() }} recette{{ $recipes->count() > 1 ? 's' : '' }}</p>
        <div class="recipe-grid">
            @foreach ($recipes as $recipe)
                @php
                    $cost = $costs[$recipe->id];
                    $per = \App\Support\RecipeCost::perYield($recipe, $cost);
                    $missing = $recipe->missingEquipment($household);
                @endphp
                <a class="recipe-card" href="{{ route('recipes.show', $recipe) }}">
                    <div class="recipe-card-media">
                        @if ($recipe->thumbUrl())
                            <img src="{{ $recipe->thumbUrl() }}" alt="" loading="lazy">
                        @else
                            <span class="recipe-card-icon" aria-hidden="true">{{ $recipe->categoryIcon() }}</span>
                        @endif
                        @if (in_array($recipe->id, $favorites, true))
                            <span class="fav-mark" title="Favori">★</span>
                        @endif
                    </div>
                    <div class="recipe-card-body">
                        <h2>{{ $recipe->title }}</h2>
                        <p class="recipe-meta">
                            {{ $recipe->categoryLabel() }}
                            @if ($recipe->totalMinutes()) · {{ $recipe->durationLabel($recipe->totalMinutes()) }} @endif
                            · {{ $recipe->yieldLabel() }}
                        </p>
                        <div class="badges">
                            @unless ($recipe->isPublished()) <span class="badge-off">brouillon</span> @endunless
                            @if ($recipe->isInSeason($month) === true) <span class="badge-season">de saison</span> @endif
                            @if (\App\Support\RecipeCost::isCheap($recipe, $cost)) <span class="badge-cheap">économique</span> @endif
                            @if ($missing->isNotEmpty()) <span class="badge-warn" title="Il vous manque : {{ $missing->pluck('name')->join(', ') }}">appareil manquant</span> @endif
                            @foreach ($recipe->tags->take(3) as $tag) <span class="tag">{{ $tag->name }}</span> @endforeach
                        </div>
                        @if ($per !== null && $cost['total_cents'] > 0)
                            <p class="recipe-cost">
                                ≈ {{ \App\Models\Price::formatCents($per) }}
                                / {{ $recipe->perYieldLabel() }}
                                @unless ($cost['complete']) <span class="muted small">(prix incomplets)</span> @endunless
                            </p>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @endif
@endsection
