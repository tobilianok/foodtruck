@extends('layouts.app')

@section('title', $recipe->title)

@section('content')
    @php
        $f = $serving->factor;
        $U = \App\Support\Units::class;
    @endphp
    <article class="recipe">
        <p class="back"><a href="{{ route('recipes.index') }}">← Recettes</a></p>

        <header class="recipe-head">
            <div class="recipe-head-media">
                @if ($recipe->photoUrl())
                    <img src="{{ $recipe->photoUrl() }}" alt="{{ $recipe->title }}">
                @else
                    <span class="recipe-card-icon big" aria-hidden="true">{{ $recipe->categoryIcon() }}</span>
                @endif
            </div>
            <div class="recipe-head-text">
                <p class="eyebrow">{{ $recipe->categoryLabel() }}@if ($recipe->protein) · {{ \App\Models\Recipe::PROTEINS[$recipe->protein] ?? '' }}@endif</p>
                <h1>{{ $recipe->title }}</h1>
                @if ($recipe->description)
                    <p class="lead">{{ $recipe->description }}</p>
                @endif

                <dl class="facts">
                    <div><dt>Pour</dt><dd><a href="#pour-combien">{{ $serving->totalLabel() }}</a></dd></div>
                    @if ($recipe->prep_minutes) <div><dt>Préparation</dt><dd>{{ $recipe->durationLabel($recipe->prep_minutes) }}</dd></div> @endif
                    @if ($recipe->cook_minutes) <div><dt>Cuisson</dt><dd>{{ $recipe->durationLabel($recipe->cook_minutes) }}</dd></div> @endif
                    @if ($recipe->rest_minutes) <div><dt>Repos</dt><dd>{{ $recipe->durationLabel($recipe->rest_minutes) }}</dd></div> @endif
                    <div><dt>Difficulté</dt><dd>{{ \App\Models\Recipe::DIFFICULTIES[$recipe->difficulty] ?? $recipe->difficulty }}</dd></div>
                    @if ($cost['total_cents'] > 0)
                        <div>
                            <dt>Coût estimé</dt>
                            <dd>
                                {{ \App\Models\Price::formatCents($cost['total_cents']) }}
                                @if ($perYield !== null) <span class="muted small">soit {{ \App\Models\Price::formatCents($perYield) }} / {{ $serving->isPortions() ? 'part' : $recipe->perYieldLabel() }}</span> @endif
                            </dd>
                        </div>
                    @endif
                </dl>

                <div class="badges">
                    @unless ($recipe->isPublished()) <span class="badge-off">brouillon (visible par toi seul)</span> @endunless
                    @if ($season === true) <span class="badge-season">de saison</span> @elseif ($season === false) <span class="badge-off">hors saison</span> @endif
                    @if ($cheap) <span class="badge-cheap">économique</span> @endif
                    @foreach ($recipe->tags as $tag)
                        <a class="tag" href="{{ route('recipes.index', ['etiquette' => $tag->slug]) }}">{{ $tag->name }}</a>
                    @endforeach
                </div>

                <div class="recipe-actions">
                    <form method="post" action="{{ route('recipes.favorite', $recipe) }}">
                        @csrf
                        <button type="submit" class="btn btn-small btn-ghost">{{ $isFavorite ? '★ Favori' : '☆ Ajouter aux favoris' }}</button>
                    </form>
                    @if ($canEdit)
                        <a class="btn btn-small" href="{{ route('recipes.edit', $recipe) }}">Modifier</a>
                    @endif
                    <form method="post" action="{{ route('recipes.duplicate', $recipe) }}">
                        @csrf
                        <button type="submit" class="btn btn-small btn-ghost">Dupliquer pour l'adapter</button>
                    </form>
                    @if ($canEdit)
                        <form method="post" action="{{ route('recipes.destroy', $recipe) }}">
                            @csrf @method('delete')
                            <button type="submit" class="btn btn-small btn-danger" data-confirm="Supprimer définitivement « {{ $recipe->title }} » ?">Supprimer</button>
                        </form>
                    @endif
                </div>
            </div>
        </header>

        @if ($missingEquipment->isNotEmpty())
            <div class="alert alert-error">Il manque à ton foyer : <strong>{{ $missingEquipment->pluck('name')->join(', ') }}</strong>.</div>
        @endif

        @if ($recipe->industrial_price_cents && $cost['complete'])
            @php
                $industrial = $recipe->industrial_price_cents * $f;
                $saving = $industrial - $cost['total_cents'];
            @endphp
            <div @class(['alert', 'alert-ok' => $saving > 0, 'alert-info' => $saving <= 0])>
                @if ($saving > 0)
                    <strong>Fait maison rentable :</strong> {{ \App\Models\Price::formatCents($cost['total_cents']) }} au lieu d'environ
                    {{ \App\Models\Price::formatCents($industrial) }} pour l'équivalent industriel,
                    soit {{ \App\Models\Price::formatCents($saving) }} d'économie ({{ round($saving / $industrial * 100) }} %).
                @else
                    <strong>Fait maison :</strong> {{ \App\Models\Price::formatCents($cost['total_cents']) }}, un peu plus que l'équivalent industriel
                    (≈ {{ \App\Models\Price::formatCents($industrial) }}), mais sans additifs et avec des ingrédients choisis.
                @endif
            </div>
        @endif

        {{-- Pour combien cuisiner --}}
        <section class="panel serving" id="pour-combien">
            <form method="get" action="{{ route('recipes.show', $recipe) }}#pour-combien" class="serving-form" data-autosubmit>
                <h2>Pour combien ?</h2>
                @if ($serving->isPortions())
                    <input type="hidden" name="ajuste" value="1">
                    @if ($serving->members->isNotEmpty())
                        <fieldset class="serving-row">
                            <legend>Qui mange</legend>
                            @foreach ($serving->members as $member)
                                <label class="chip-check">
                                    <input type="checkbox" name="qui[]" value="{{ $member->id }}" @checked(in_array($member->id, $serving->eaters, true))>
                                    <span>{{ $member->name }} <small>{{ $U::number($member->portion_coefficient) }}</small></span>
                                </label>
                            @endforeach
                        </fieldset>
                    @endif
                    <div class="serving-row">
                        <label class="field-inline">
                            <span>Invités adultes</span>
                            <input type="number" name="adultes" min="0" max="{{ \App\Support\RecipeServing::MAX_GUESTS }}" value="{{ $serving->adults }}" inputmode="numeric">
                        </label>
                        <label class="field-inline">
                            <span>enfants</span>
                            <input type="number" name="enfants" min="0" max="{{ \App\Support\RecipeServing::MAX_GUESTS }}" value="{{ $serving->children }}" inputmode="numeric">
                        </label>
                    </div>
                    <fieldset class="serving-row">
                        <legend>Nombre de repas</legend>
                        @foreach ([1 => '1 repas', 2 => '2 repas', 3 => '3 repas', 4 => '4 repas'] as $value => $label)
                            <label class="chip-check">
                                <input type="radio" name="repas" value="{{ $value }}" @checked($serving->meals === $value)>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                        <small class="muted">ce soir + demain midi, ou une part à congeler</small>
                    </fieldset>
                    <div class="serving-row">
                        <label class="field-inline">
                            <span>Parts par repas <small class="muted">(réglage libre)</small></span>
                            <input type="number" name="parts" min="0.5" max="50" step="0.5" value="{{ $serving->manual !== null ? $serving->manual : '' }}" placeholder="{{ $U::number($serving->perMeal) }}" inputmode="decimal">
                        </label>
                        <button type="submit" class="btn btn-small">Recalculer</button>
                        @if (request()->query())
                            <a class="btn btn-small btn-ghost" href="{{ route('recipes.show', $recipe) }}#pour-combien">Revenir au foyer</a>
                        @endif
                    </div>
                @else
                    <div class="serving-row">
                        <label class="field-inline">
                            <span>Quantité à préparer</span>
                            <input type="number" name="quantite" min="{{ $recipe->yield_unit === 'grammes' ? 50 : 1 }}" step="{{ $recipe->yield_unit === 'grammes' ? 50 : 1 }}"
                                   max="{{ $recipe->yield_quantity * 20 }}" value="{{ $U::number($serving->total) }}" inputmode="numeric">
                            <small class="muted">{{ \App\Models\Recipe::YIELD_UNITS[$recipe->yield_unit][1] ?? $recipe->yield_unit }}</small>
                        </label>
                        <span class="serving-batches">
                            @foreach ($serving->batchChoices() as $label => $quantity)
                                <a @class(['chip', 'is-active' => abs($quantity - $serving->total) < 0.001]) href="{{ route('recipes.show', [$recipe, 'quantite' => $U::number($quantity)]) }}#pour-combien">×{{ $label }}</a>
                            @endforeach
                        </span>
                        <button type="submit" class="btn btn-small">Recalculer</button>
                    </div>
                @endif

                <p class="serving-result">
                    <strong>Pour {{ $serving->totalLabel() }}</strong>
                    @if ($serving->detailLabel() !== '') <span class="muted">· {{ $serving->detailLabel() }}</span> @endif
                    @if ($serving->isScaled() && $serving->isPortions()) <span class="muted small">(recette d'origine : {{ $recipe->yieldLabel() }})</span> @endif
                </p>
                @foreach ($serving->warnings as $warning)
                    <p class="hint small">{{ $warning }}</p>
                @endforeach
            </form>

            {{-- Ajouter au planning avec ces réglages --}}
            <form method="post" action="{{ route('planning.store') }}" class="serving-row plan-add">
                @csrf
                <input type="hidden" name="kind" value="recette">
                <input type="hidden" name="recipe_id" value="{{ $recipe->id }}">
                @foreach ($serving->query() as $key => $value)
                    @if (is_array($value))
                        @foreach ($value as $item) <input type="hidden" name="{{ $key }}[]" value="{{ $item }}"> @endforeach
                    @else
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endforeach
                <span class="serving-title"><strong>Au planning</strong></span>
                <label class="field-inline">
                    <span class="sr-only">Jour</span>
                    <select name="date">
                        @for ($i = 0; $i < 14; $i++)
                            @php $day = now('Europe/Paris')->addDays($i); @endphp
                            <option value="{{ $day->toDateString() }}">{{ $i === 0 ? 'Aujourd\'hui' : ($i === 1 ? 'Demain' : ucfirst($day->locale('fr')->isoFormat('dddd D MMM'))) }}</option>
                        @endfor
                    </select>
                </label>
                <label class="field-inline">
                    <span class="sr-only">Repas</span>
                    <select name="slot">
                        @php
                            $planSlots = auth()->user()->household->mealSlots();
                            // Créneau proposé : goûter ou petit-déjeuner s'ils sont affichés, fournées à préparer, sinon dîner
                            $preferred = in_array($recipe->category, ['gouter', 'petit-dejeuner'], true) && in_array($recipe->category, $planSlots, true)
                                ? $recipe->category
                                : ($recipe->category === 'base' || ! $serving->isPortions() ? 'preparation' : 'diner');
                        @endphp
                        @foreach ($planSlots as $slot)
                            <option value="{{ $slot }}" @selected($slot === $preferred)>{{ \App\Models\MealPlanEntry::SLOTS[$slot][0] }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="btn btn-small">Ajouter au planning</button>
            </form>
        </section>

        <div class="recipe-body">
            <section class="panel recipe-ingredients">
                <h2>Ingrédients <span class="muted small">pour {{ $serving->totalLabel() }}</span></h2>
                @php $currentGroup = false; @endphp
                <ul>
                    @foreach ($recipe->ingredients as $line)
                        @if ($line->group_label !== $currentGroup)
                            @php $currentGroup = $line->group_label; @endphp
                            @if ($currentGroup)
                                </ul><h3>{{ $currentGroup }}</h3><ul>
                            @endif
                        @endif
                        <li @class(['is-optional' => $line->is_optional])>
                            <span class="qty" @if ($line->exactLabel($f)) title="{{ $line->exactLabel($f) }}" @endif>{{ $line->quantityLabel($f) }}</span>
                            <a href="{{ route('ingredients.show', $line->ingredient) }}">{{ $line->ingredient->name }}</a>
                            @if ($line->note) <span class="muted">{{ $line->note }}</span> @endif
                            @if ($line->equivalentLabel($f)) <span class="muted small">{{ $line->equivalentLabel($f) }}</span> @endif
                            @if ($line->is_optional) <span class="muted small">(facultatif)</span> @endif
                        </li>
                    @endforeach
                </ul>
                @unless ($cost['complete'])
                    <p class="hint small">Prix inconnu pour : {{ implode(', ', $cost['missing']) }}. Le coût affiché est incomplet.</p>
                @endunless
                @if ($recipe->equipment->isNotEmpty())
                    <h3>Appareils</h3>
                    <p>{{ $recipe->equipment->pluck('name')->join(', ') }}</p>
                @endif
            </section>

            <section class="panel recipe-steps">
                <h2>Étapes</h2>
                @if ($serving->isScaled())
                    <p class="hint small">Les quantités citées dans les étapes sont celles de la recette d'origine ({{ $recipe->yieldLabel() }}) : suis la liste d'ingrédients recalculée.</p>
                @endif
                <ol>
                    @foreach ($recipe->steps as $step)
                        <li>
                            <p>{!! nl2br(e($step->body)) !!}</p>
                            @if ($step->timer_minutes || $step->equipment)
                                <p class="step-meta">
                                    {{ collect([
                                        $step->timer_minutes ? '⏱ '.$recipe->durationLabel($step->timer_minutes) : null,
                                        $step->equipment?->name,
                                    ])->filter()->join(' · ') }}
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>

        <footer class="recipe-foot muted small">
            @if ($recipe->author) Saisie par {{ $recipe->author->name }} @else Recette du lot de départ Foodtruck @endif
            @if ($recipe->source) · Source : {{ $recipe->source }} @endif
            @if ($recipe->parent) · Variante de <a href="{{ route('recipes.show', $recipe->parent) }}">{{ $recipe->parent->title }}</a> @endif
            @if ($variants->isNotEmpty())
                · Variantes :
                @foreach ($variants as $variant)
                    <a href="{{ route('recipes.show', $variant) }}">{{ $variant->title }}</a>@if (! $loop->last), @endif
                @endforeach
            @endif
        </footer>
    </article>

    <script>
        // Recalcul immédiat quand on coche, choisit le nombre de repas ou valide un nombre
        document.querySelectorAll('form[data-autosubmit]').forEach(function (form) {
            form.addEventListener('change', function (event) {
                if (event.target.matches('input')) {
                    form.submit();
                }
            });
        });
    </script>
@endsection
