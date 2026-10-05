@extends('layouts.app')

@section('title', $entry->exists ? 'Modifier un repas' : 'Ajouter un repas')

@section('content')
    @php
        $U = \App\Support\Units::class;
        $isLeftover = $entry->isLeftover();
        $source = $isLeftover ? $entry->source : null;
        $date = $entry->date instanceof \Carbon\CarbonInterface ? $entry->date->toDateString() : $entry->date;
    @endphp

    <section class="hero hero-compact">
        <p><a href="{{ route('planning.week', \App\Support\MealPlanner::weekStart($date)->toDateString()) }}">← Planning</a></p>
        <h1>
            @if ($isLeftover) Planifier les restes
            @elseif ($entry->exists) Modifier le repas
            @else Ajouter un repas @endif
        </h1>
        @if ($isLeftover && $source?->recipe)
            <p class="lead">↩ {{ $source->recipe->title }} · {{ $entry->partsLabel($household) }}, cuisiné le {{ $source->date->locale('fr')->isoFormat('dddd D MMMM') }} ({{ mb_strtolower($source->slotLabel()) }}).</p>
        @endif
    </section>

    <form method="post" action="{{ $entry->exists ? route('planning.update', $entry) : route('planning.store') }}" class="stack planning-form"
          data-usual='@json($usualMap)' data-all='@json($allMembers)' data-follow-usual="{{ $followsUsual || ! $entry->exists ? 1 : 0 }}">
        @csrf
        @if ($entry->exists) @method('put') @endif

        <section class="panel">
            <div class="grid-2">
                <label class="field">
                    <span>Jour</span>
                    <input type="date" name="date" value="{{ old('date', $date) }}" required>
                </label>
                <label class="field">
                    <span>Repas</span>
                    <select name="slot" required>
                        @foreach ($slots as $code => [$label])
                            <option value="{{ $code }}" @selected(old('slot', $entry->slot) === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </section>

        @unless ($isLeftover)
            @php
                $kind = old('kind', $entry->kind ?? 'recette');
                $recipeId = (int) old('recipe_id', $entry->recipe_id);
            @endphp
            <section class="panel">
                <fieldset class="serving-row">
                    <legend>Quoi</legend>
                    @foreach (['recette' => 'Une recette', 'hors_maison' => 'Hors maison (cantine, restaurant…)', 'note' => 'Autre (note libre)'] as $value => $label)
                        <label class="chip-check">
                            <input type="radio" name="kind" value="{{ $value }}" @checked($kind === $value) data-kind>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </fieldset>

                <div class="kind-block" data-for-kind="recette">
                    <label class="field">
                        <span>Recette</span>
                        <input type="search" class="recipe-filter" placeholder="Filtrer les recettes (ex. soupe, curry…)" aria-label="Filtrer les recettes">
                        <select name="recipe_id" class="recipe-select" size="8">
                            @foreach ($recipes as $category => $items)
                                <optgroup label="{{ \App\Models\Recipe::CATEGORIES[$category][0] ?? $category }}">
                                    @foreach ($items as $recipe)
                                        <option value="{{ $recipe->id }}" data-mode="{{ $recipe->yield_unit === 'personnes' ? 'parts' : 'fournee' }}"
                                                data-yield="{{ $U::number($recipe->yield_quantity) }}" data-unit="{{ \App\Models\Recipe::YIELD_UNITS[$recipe->yield_unit][1] ?? '' }}"
                                                @selected($recipeId === $recipe->id)>{{ $recipe->title }}@unless ($recipe->isPublished()) (brouillon)@endunless · {{ $recipe->yieldLabel() }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </label>

                    <div class="serving-form" data-mode-block="parts">
                        <input type="hidden" name="ajuste" value="1">
                        @if ($household->members->isNotEmpty())
                            <fieldset class="serving-row eaters">
                                <legend>Qui mange</legend>
                                @foreach ($household->members as $member)
                                    <label class="chip-check">
                                        <input type="checkbox" name="qui[]" value="{{ $member->id }}" @checked(in_array($member->id, old('qui', $eaters)))>
                                        <span>{{ $member->name }} <small>{{ $U::number($member->portion_coefficient) }}</small></span>
                                    </label>
                                @endforeach
                                <small class="muted usual-hint" @unless ($followsUsual) hidden @endunless>d'après la semaine type</small>
                            </fieldset>
                        @endif
                        <div class="serving-row">
                            <label class="field-inline"><span>Invités adultes</span>
                                <input type="number" name="adultes" min="0" max="{{ \App\Support\RecipeServing::MAX_GUESTS }}" value="{{ old('adultes', $entry->guest_adults ?? 0) }}" inputmode="numeric"></label>
                            <label class="field-inline"><span>enfants</span>
                                <input type="number" name="enfants" min="0" max="{{ \App\Support\RecipeServing::MAX_GUESTS }}" value="{{ old('enfants', $entry->guest_children ?? 0) }}" inputmode="numeric"></label>
                        </div>
                        <fieldset class="serving-row">
                            <legend>Nombre de repas</legend>
                            @foreach ([1, 2, 3, 4] as $value)
                                <label class="chip-check">
                                    <input type="radio" name="repas" value="{{ $value }}" @checked((int) old('repas', $entry->meals ?? 1) === $value)>
                                    <span>{{ $value }} repas</span>
                                </label>
                            @endforeach
                            <small class="muted">les restes vont sur les déjeuners et dîners libres suivants où quelqu'un mange à la maison</small>
                        </fieldset>
                        <label class="field-inline"><span>Parts par repas <small class="muted">(réglage libre)</small></span>
                            <input type="number" name="parts" min="0.5" max="50" step="0.5" value="{{ old('parts', $entry->parts_manual) }}" placeholder="{{ $U::number($household->members->whereIn('id', $eaters)->sum('portion_coefficient')) }}" inputmode="decimal"></label>
                    </div>

                    <div class="serving-form" data-mode-block="fournee">
                        <label class="field-inline"><span>Quantité à préparer</span>
                            <input type="number" name="quantite" min="1" step="1" value="{{ old('quantite', $entry->batch_quantity !== null ? $U::number($entry->batch_quantity) : '') }}" placeholder="fournée de la recette" inputmode="numeric">
                            <small class="muted batch-unit"></small></label>
                    </div>
                </div>

                <div class="kind-block" data-for-kind="hors_maison note">
                    <label class="field">
                        <span>Note <small class="muted">(facultative pour « hors maison »)</small></span>
                        <input type="text" name="note" value="{{ old('note', $entry->note) }}" maxlength="120" placeholder="cantine, restaurant, pizza surgelée…">
                    </label>
                </div>
            </section>
        @endunless

        <div class="actions">
            @if ($entry->exists)
                <a class="btn btn-ghost" href="{{ route('planning.week', \App\Support\MealPlanner::weekStart($date)->toDateString()) }}">Annuler</a>
            @endif
            <button type="submit" class="btn">{{ $isLeftover ? 'Placer les restes' : ($entry->exists ? 'Enregistrer' : 'Ajouter au planning') }}</button>
        </div>
    </form>

    @if ($entry->exists)
        <div class="row-actions ticket-actions">
            @if ($isLeftover && ! $entry->is_frozen)
                <form method="post" action="{{ route('planning.freeze', $entry) }}">
                    @csrf
                    <button type="submit" class="btn btn-small btn-ghost">🧊 Mettre au congélateur</button>
                </form>
            @endif
            <form method="post" action="{{ route('planning.destroy', $entry) }}">
                @csrf @method('delete')
                <button type="submit" class="btn btn-small btn-danger" @unless ($isLeftover) data-confirm="Retirer ce repas{{ $entry->leftovers()->exists() ? ' et ses restes' : '' }} du planning ?" @endunless>{{ $isLeftover ? 'Retirer ces restes (mangés, jetés)' : 'Retirer ce repas du planning' }}</button>
            </form>
        </div>
    @endif

    <script>
        (function () {
            var form = document.querySelector('.planning-form');
            if (!form) return;
            var select = form.querySelector('.recipe-select');
            var filter = form.querySelector('.recipe-filter');
            function sync() {
                var kind = (form.querySelector('[data-kind]:checked') || {}).value || 'recette';
                form.querySelectorAll('.kind-block').forEach(function (block) {
                    block.hidden = block.dataset.forKind.split(' ').indexOf(kind) === -1;
                });
                if (!select) return;
                var option = select.options[select.selectedIndex];
                var mode = option ? option.dataset.mode : null;
                form.querySelectorAll('[data-mode-block]').forEach(function (block) {
                    block.hidden = !mode || block.dataset.modeBlock !== mode;
                });
                var unit = form.querySelector('.batch-unit');
                if (unit && option) unit.textContent = option.dataset.unit + ' (recette : ' + option.dataset.yield + ')';
            }
            // Convives pré-cochés d'après la semaine type tant qu'on ne les a pas modifiés à la main
            var usual = JSON.parse(form.dataset.usual || '{}');
            var all = JSON.parse(form.dataset.all || '[]');
            var follow = form.dataset.followUsual === '1';
            var hint = form.querySelector('.usual-hint');
            function applyUsual() {
                if (!follow) return;
                var date = form.querySelector('[name=date]').value;
                var slot = form.querySelector('[name=slot]').value;
                if (!date) return;
                var weekday = ((new Date(date + 'T12:00:00').getDay() + 6) % 7) + 1;
                var present = (usual[slot] && usual[slot][weekday]) ? usual[slot][weekday] : all;
                form.querySelectorAll('[name="qui[]"]').forEach(function (box) {
                    box.checked = present.indexOf(parseInt(box.value, 10)) !== -1;
                });
                if (hint) hint.hidden = present.length === all.length;
            }
            form.addEventListener('change', function (event) {
                if (event.target.name === 'qui[]') {
                    follow = false;
                    if (hint) hint.hidden = true;
                }
                if (event.target.name === 'date' || event.target.name === 'slot') applyUsual();
                sync();
            });
            if (filter && select) {
                filter.addEventListener('input', function () {
                    var q = filter.value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
                    select.querySelectorAll('option').forEach(function (o) {
                        var t = o.textContent.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
                        o.hidden = q !== '' && t.indexOf(q) === -1;
                    });
                });
            }
            sync();
        })();
    </script>
@endsection
