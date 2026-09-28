@extends('layouts.app')

@section('title', $recipe->title)

@section('content')
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
                    <div><dt>Pour</dt><dd>{{ $recipe->yieldLabel() }}</dd></div>
                    @if ($recipe->prep_minutes) <div><dt>Préparation</dt><dd>{{ $recipe->durationLabel($recipe->prep_minutes) }}</dd></div> @endif
                    @if ($recipe->cook_minutes) <div><dt>Cuisson</dt><dd>{{ $recipe->durationLabel($recipe->cook_minutes) }}</dd></div> @endif
                    @if ($recipe->rest_minutes) <div><dt>Repos</dt><dd>{{ $recipe->durationLabel($recipe->rest_minutes) }}</dd></div> @endif
                    <div><dt>Difficulté</dt><dd>{{ \App\Models\Recipe::DIFFICULTIES[$recipe->difficulty] ?? $recipe->difficulty }}</dd></div>
                    @if ($cost['total_cents'] > 0)
                        <div>
                            <dt>Coût estimé</dt>
                            <dd>
                                {{ \App\Models\Price::formatCents($cost['total_cents']) }}
                                @if ($perYield !== null) <span class="muted small">soit {{ \App\Models\Price::formatCents($perYield) }} / {{ $recipe->perYieldLabel() }}</span> @endif
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
            @php $saving = $recipe->industrial_price_cents - $cost['total_cents']; @endphp
            <div @class(['alert', 'alert-ok' => $saving > 0, 'alert-info' => $saving <= 0])>
                @if ($saving > 0)
                    <strong>Fait maison rentable :</strong> {{ \App\Models\Price::formatCents($cost['total_cents']) }} au lieu d'environ
                    {{ \App\Models\Price::formatCents($recipe->industrial_price_cents) }} pour l'équivalent industriel,
                    soit {{ \App\Models\Price::formatCents($saving) }} d'économie ({{ round($saving / $recipe->industrial_price_cents * 100) }} %).
                @else
                    <strong>Fait maison :</strong> {{ \App\Models\Price::formatCents($cost['total_cents']) }}, un peu plus que l'équivalent industriel
                    (≈ {{ \App\Models\Price::formatCents($recipe->industrial_price_cents) }}), mais sans additifs et avec des ingrédients choisis.
                @endif
            </div>
        @endif

        <div class="recipe-body">
            <section class="panel recipe-ingredients">
                <h2>Ingrédients <span class="muted small">pour {{ $recipe->yieldLabel() }}</span></h2>
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
                            <span class="qty">{{ $line->quantityLabel() }}</span>
                            <a href="{{ route('ingredients.show', $line->ingredient) }}">{{ $line->ingredient->name }}</a>
                            @if ($line->note) <span class="muted">{{ $line->note }}</span> @endif
                            @if ($line->equivalentLabel()) <span class="muted small">{{ $line->equivalentLabel() }}</span> @endif
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
@endsection
