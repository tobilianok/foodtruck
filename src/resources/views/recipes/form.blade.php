@extends('layouts.app')

@section('title', $recipe->exists ? 'Modifier · '.$recipe->title : (isset($import) ? 'Relire une fiche Paperless' : 'Nouvelle recette'))

@section('content')
    <section class="hero hero-compact">
        @isset($import)
            <p><a href="{{ route('recipes.imports.index') }}">← Fiches Paperless</a></p>
            <h1>Relire la fiche Paperless n° {{ $import->paperless_document_id }}</h1>
            <p class="lead">Voici ce que Foodtruck a compris de la fiche. Corrige ce qui doit l'être (les lignes à vérifier sont marquées), puis crée la recette.</p>
        @else
            <p><a href="{{ $recipe->exists ? route('recipes.show', $recipe) : route('recipes.index') }}">← {{ $recipe->exists ? $recipe->title : 'Recettes' }}</a></p>
            <h1>{{ $recipe->exists ? 'Modifier la recette' : 'Nouvelle recette' }}</h1>
            <p class="lead">Les ingrédients se choisissent dans le référentiel : c'est ce qui permet ensuite de calculer les portions, le coût et la liste de courses.</p>
        @endisset
    </section>

    @isset($import)
        @if (! empty($importIssues))
            <div class="alert alert-error">
                <p>À vérifier :</p>
                <ul>
                    @foreach ($importIssues as $issue)
                        <li>{{ $issue }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <section class="panel">
            <details>
                <summary class="more-summary">Texte lu dans Paperless</summary>
                <pre class="raw-text">{{ $import->raw_text }}</pre>
            </details>
            <div class="row-actions">
                <form method="post" action="{{ route('recipes.imports.reanalyse', $import) }}">
                    @csrf
                    <button type="submit" class="btn btn-ghost btn-small">Relire la fiche</button>
                </form>
                <form method="post" action="{{ route('recipes.imports.ignore', $import) }}">
                    @csrf
                    <button type="submit" class="btn btn-ghost btn-small" data-confirm="Supprimer cette fiche ? « Chercher dans Paperless » la relira depuis le début tant que le document porte l'étiquette.">Supprimer cette fiche</button>
                </form>
            </div>
            <p class="hint small">« Relire la fiche » reprend le texte avec les règles à jour : utile après avoir ajouté un ingrédient manquant au référentiel.</p>
        </section>
    @endisset

    <form method="post" enctype="multipart/form-data" class="stack"
          action="{{ $recipe->exists ? route('recipes.update', $recipe) : route('recipes.store') }}">
        @csrf
        @if ($recipe->exists) @method('put') @endif
        @isset($import)
            <input type="hidden" name="import_id" value="{{ $import->id }}">
        @endisset

        <section class="panel">
            <h2>L'essentiel</h2>
            <div class="grid-2">
                <label class="field span-2">
                    <span>Titre</span>
                    <input type="text" name="title" value="{{ old('title', $recipe->title) }}" maxlength="120" required>
                </label>
                <label class="field span-2">
                    <span>Présentation <small>(facultatif)</small></span>
                    <textarea name="description" rows="2" maxlength="2000">{{ old('description', $recipe->description) }}</textarea>
                </label>
                <label class="field">
                    <span>Catégorie</span>
                    <select name="category">
                        @foreach (\App\Models\Recipe::CATEGORIES as $value => [$label, $icon])
                            <option value="{{ $value }}" @selected(old('category', $recipe->category) === $value)>{{ $icon }} {{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="field">
                    <span>Recette prévue pour</span>
                    <div class="input-pair">
                        <input type="text" name="yield_quantity" value="{{ old('yield_quantity', $recipe->yield_quantity ? \App\Support\Units::number($recipe->yield_quantity) : '') }}" inputmode="decimal" required aria-label="Quantité">
                        <select name="yield_unit" aria-label="Unité">
                            @foreach (\App\Models\Recipe::YIELD_UNITS as $value => [$singular, $plural])
                                <option value="{{ $value }}" @selected(old('yield_unit', $recipe->yield_unit) === $value)>{{ $plural }}</option>
                            @endforeach
                        </select>
                    </div>
                    <small>Sera proratisé selon le nombre de personnes choisi.</small>
                </div>
                <label class="field">
                    <span>Préparation (min)</span>
                    <input type="number" name="prep_minutes" value="{{ old('prep_minutes', $recipe->prep_minutes) }}" min="0">
                </label>
                <label class="field">
                    <span>Cuisson (min)</span>
                    <input type="number" name="cook_minutes" value="{{ old('cook_minutes', $recipe->cook_minutes) }}" min="0">
                </label>
                <label class="field">
                    <span>Repos (min)</span>
                    <input type="number" name="rest_minutes" value="{{ old('rest_minutes', $recipe->rest_minutes) }}" min="0">
                </label>
                <label class="field">
                    <span>Difficulté</span>
                    <select name="difficulty">
                        @foreach (\App\Models\Recipe::DIFFICULTIES as $value => $label)
                            <option value="{{ $value }}" @selected(old('difficulty', $recipe->difficulty) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">
                    <span>Protéine principale</span>
                    <select name="protein">
                        <option value="">—</option>
                        @foreach (\App\Models\Recipe::PROTEINS as $value => $label)
                            <option value="{{ $value }}" @selected(old('protein', $recipe->protein) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <small>Servira à équilibrer les menus.</small>
                </label>
                <label class="field">
                    <span>Prix de l'équivalent industriel <small>(facultatif)</small></span>
                    <span class="input-suffix">
                        <input type="text" name="industrial_price" inputmode="decimal" placeholder="2,49"
                               value="{{ old('industrial_price', $recipe->industrial_price_cents ? number_format($recipe->industrial_price_cents / 100, 2, ',', '') : '') }}">
                        <span>€</span>
                    </span>
                    <small>Pour le fait maison : affiche l'économie réalisée.</small>
                </label>
                <label class="field span-2">
                    <span>Source <small>(livre, site, famille…)</small></span>
                    <input type="text" name="source" value="{{ old('source', $recipe->source) }}" maxlength="255">
                </label>
            </div>
        </section>

        <section class="panel">
            <h2>Ingrédients</h2>
            <p class="hint">Commence à taper le nom et choisis dans la liste. Quantité et unité vides = « selon goût ». Le groupe sert à séparer « Pour la pâte », « Pour la sauce »…</p>
            <datalist id="ingredient-names">
                @foreach ($ingredientNames as $name)
                    <option value="{{ $name }}"></option>
                @endforeach
            </datalist>
            <div class="rows ingredient-rows" data-rows="ingredients">
                @foreach ($ingredientRows as $key => $row)
                    @include('recipes._ingredient-row', ['key' => $key, 'row' => $row])
                @endforeach
            </div>
            <template data-row-template="ingredients">
                @include('recipes._ingredient-row', ['key' => '__KEY__', 'row' => []])
            </template>
            <div class="row-actions">
                <button type="button" class="btn btn-ghost btn-small" data-add-row="ingredients">+ Ajouter un ingrédient</button>
                <a class="small" href="{{ route('ingredients.create') }}" target="_blank" rel="noopener">Un ingrédient manque ? L'ajouter au référentiel ↗</a>
            </div>
        </section>

        <section class="panel">
            <h2>Étapes</h2>
            <div class="rows" data-rows="steps">
                @foreach ($stepRows as $key => $row)
                    @include('recipes._step-row', ['key' => $key, 'row' => $row])
                @endforeach
            </div>
            <template data-row-template="steps">
                @include('recipes._step-row', ['key' => '__KEY__', 'row' => []])
            </template>
            <button type="button" class="btn btn-ghost btn-small" data-add-row="steps">+ Ajouter une étape</button>
        </section>

        <section class="panel">
            <h2>Étiquettes et appareils</h2>
            <fieldset class="field">
                <legend>Étiquettes</legend>
                <div class="chips">
                    @foreach ($tags as $tag)
                        <label class="chip">
                            <input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked(in_array($tag->id, $selectedTags, true))>
                            <span>{{ $tag->name }}</span>
                        </label>
                    @endforeach
                </div>
                <small>« De saison », « économique » et « maison rentable » sont calculés automatiquement.</small>
            </fieldset>
            <fieldset class="field">
                <legend>Appareils nécessaires</legend>
                <div class="chips">
                    @foreach ($equipment as $item)
                        <label class="chip">
                            <input type="checkbox" name="equipment[]" value="{{ $item->id }}" @checked(in_array($item->id, $selectedEquipment, true))>
                            <span>{{ $item->name }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        </section>

        <section class="panel">
            <h2>Photo</h2>
            @if ($recipe->photoUrl())
                <div class="photo-current">
                    <img src="{{ $recipe->thumbUrl() }}" alt="">
                    <label class="check"><input type="checkbox" name="remove_photo" value="1"><span>Supprimer la photo</span></label>
                </div>
            @endif
            <label class="field">
                <span>{{ $recipe->photoUrl() ? 'Remplacer par' : 'Ajouter une photo' }} <small>(JPEG, PNG ou WebP, 15 Mo max, redimensionnée automatiquement)</small></span>
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
            </label>
        </section>

        <div class="panel sticky-bar">
            <label class="check">
                <input type="checkbox" name="draft" value="1" @checked(old('draft', $recipe->exists && ! $recipe->isPublished()))>
                <span>Garder en brouillon (visible par moi seul)</span>
            </label>
            <div class="row-actions">
                <a class="btn btn-ghost" href="{{ $recipe->exists ? route('recipes.show', $recipe) : route('recipes.index') }}">Annuler</a>
                <button type="submit" class="btn">{{ $recipe->exists ? 'Enregistrer' : 'Créer la recette' }}</button>
            </div>
        </div>
    </form>
@endsection
