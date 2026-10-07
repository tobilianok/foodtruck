@extends('layouts.app')

@section('title', 'Recettes')

@section('content')
    <x-page-header title="Recettes" :lead="$total.' recettes partagées par tous les comptes. Touche-en une pour voir les étapes et l\'ajouter à ton planning.'">
        @if ($household->hasPaperless() || $importCount > 0)
            <a class="btn btn-ghost" href="{{ route('recipes.imports.index') }}">Fiches Paperless{{ $importCount > 0 ? ' ('.$importCount.')' : '' }}</a>
        @endif
        <a class="btn" href="{{ route('recipes.create') }}">Nouvelle recette</a>
    </x-page-header>

    @if ($importCount > 0)
        <div class="alert alert-info">
            {{ $importCount }} fiche{{ $importCount > 1 ? 's' : '' }} venue{{ $importCount > 1 ? 's' : '' }} de Paperless {{ $importCount > 1 ? 'attendent' : 'attend' }} ta relecture.
            <a href="{{ route('recipes.imports.index') }}">Les relire</a>
        </div>
    @endif

    <form method="get" action="{{ route('recipes.index') }}" class="panel filters-recipes" data-autofilter>
        <div class="search-row">
            <label class="field">
                <span class="sr-only">Rechercher</span>
                <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Rechercher : potimarron, lentilles, cookies…">
            </label>
            <button type="submit" class="btn">Chercher</button>
        </div>
        <div class="chips quick-filters">
            <label class="chip"><input type="checkbox" name="saison" value="1" @checked(! empty($filters['saison']))><span>De saison</span></label>
            <label class="chip"><input type="checkbox" name="rapide" value="1" @checked(! empty($filters['rapide']))><span>Rapide (30 min max)</span></label>
            <label class="chip"><input type="checkbox" name="favoris" value="1" @checked(! empty($filters['favoris']))><span>Mes favoris</span></label>
        </div>
        <details class="more" @if (! empty($filters['categorie']) || ! empty($filters['etiquette']) || ! empty($filters['appareils']) || ! empty($filters['brouillons'])) open @endif>
            <summary>Plus de filtres</summary>
            <div class="grid-2">
                <label class="field">
                    <span>Type de plat</span>
                    <select name="categorie">
                        <option value="">Tous</option>
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
                <p class="small"><a href="{{ route('recipes.tags') }}">Gérer les étiquettes</a></p>
            </div>
            <div class="checks">
                <label class="check"><input type="checkbox" name="appareils" value="1" @checked(! empty($filters['appareils']))><span>Faisable avec mes appareils</span></label>
                <label class="check"><input type="checkbox" name="brouillons" value="1" @checked(! empty($filters['brouillons']))><span>Mes brouillons</span></label>
            </div>
        </details>
        @if (array_filter($filters))
            <p><a href="{{ route('recipes.index') }}">Tout afficher</a></p>
        @endif
    </form>

    @if ($recipes->isEmpty())
        <div class="empty">
            <span class="empty-icon">@include('partials.icon', ['name' => 'book'])</span>
            <h2>Aucune recette ne correspond</h2>
            <p>Essaie avec moins de filtres, ou ajoute la tienne avec toutes ses étapes.</p>
            <a class="btn" href="{{ route('recipes.create') }}">Saisir une recette</a>
        </div>
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
