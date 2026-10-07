@extends('layouts.app')

@section('title', $entry->exists ? 'Modifier un repas' : 'Ajouter un repas')

@section('content')
    @php
        $U = \App\Support\Units::class;
        $isLeftover = $entry->isLeftover();
        $source = $isLeftover ? $entry->source : null;
        $date = $entry->date instanceof \Carbon\CarbonInterface ? $entry->date->toDateString() : $entry->date;
        $on = \Illuminate\Support\Carbon::parse($date ?: now('Europe/Paris'));
    @endphp

    <p class="back"><a href="{{ route('planning.week', \App\Support\MealPlanner::weekStart($date)->toDateString()) }}">← Retour au planning</a></p>
    <x-page-header :title="$isLeftover ? 'Planifier les restes' : ($entry->exists ? 'Modifier le repas' : 'Ajouter un repas')"
                   :lead="$isLeftover && $source?->recipe ? '↩ '.$source->recipe->title.' · '.$entry->partsLabel($household).', cuisiné le '.$source->date->locale('fr')->isoFormat('dddd D MMMM') : ($entry->exists ? null : 'Choisis le moment, puis ce que tu manges. Foodtruck adapte les quantités à ton foyer.')" />

    <form method="post" action="{{ $entry->exists ? route('planning.update', $entry) : route('planning.store') }}" class="stack planning-form"
          data-usual='@json($usualMap)' data-all='@json($allMembers)' data-follow-usual="{{ $followsUsual || ! $entry->exists ? 1 : 0 }}">
        @csrf
        @if ($entry->exists) @method('put') @endif

        <section class="panel">
            <h2>Quand ?</h2>
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
            @php
                $custom = $entry->exists && ($entry->eaters !== null || $entry->guest_adults || $entry->guest_children || $entry->meals > 1 || $entry->parts_manual !== null);
            @endphp
            @php
                $otherDishes = $siblings->filter(fn ($e) => $e->recipe)->map(fn ($e) => $e->recipe->title);
                $extraIds = array_map('intval', (array) (session()->hasOldInput() ? old('avec', []) : $extras));
                $companionTitles = $companions->flatten()->pluck('title', 'id');
            @endphp
            <section class="panel">
                <h2>Quoi ?</h2>
                @if (! $entry->exists && $otherDishes->isNotEmpty())
                    {{-- v0.22.0 : ajout à un repas déjà prévu --}}
                    <p class="alert alert-info meal-already">Ce repas compte déjà : <strong>{{ $otherDishes->join(', ', ' et ') }}</strong>. La recette ajoutée est calculée pour les mêmes convives.</p>
                @endif
                @if (! $entry->exists && $menus->isNotEmpty())
                    <label class="field menu-pick-field">
                        <span>Un menu enregistré <small class="muted">(facultatif)</small></span>
                        <select name="menu_id" class="menu-pick">
                            <option value="">— choisir les recettes une par une —</option>
                            @foreach ($menus as $saved)
                                <option value="{{ $saved->id }}" data-recipes='@json($saved->recipes->pluck('id'))' @selected((int) old('menu_id', $menu?->id) === $saved->id)>{{ $saved->name }} · {{ $saved->recipes->pluck('title')->join(' + ') }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <fieldset class="serving-row">
                    <legend class="sr-only">Type de repas</legend>
                    @foreach (['recette' => 'Une recette', 'hors_maison' => 'Hors maison', 'note' => 'Note libre'] as $value => $label)
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

                    @unless ($entry->exists)
                        {{-- v0.22.0 : repas composé : d'autres recettes servies avec le plat, pour les mêmes convives --}}
                        <div class="meal-extras" data-mode-block="parts">
                            <h3>Avec <small class="muted">(accompagnement, entrée, dessert… facultatif)</small></h3>
                            <ul class="extra-list" data-titles='@json($companionTitles)'>
                                @foreach ($extraIds as $extraId)
                                    @if ($companionTitles->has($extraId))
                                        <li><span>{{ $companionTitles[$extraId] }}</span><input type="hidden" name="avec[]" value="{{ $extraId }}"><button type="button" class="btn btn-small btn-ghost" data-extra-remove aria-label="Retirer {{ $companionTitles[$extraId] }}">Retirer</button></li>
                                    @endif
                                @endforeach
                            </ul>
                            <select class="extra-add" aria-label="Ajouter une recette au repas">
                                <option value="">+ Ajouter une recette au repas…</option>
                                @foreach ($companions as $category => $items)
                                    <optgroup label="{{ \App\Models\Recipe::CATEGORIES[$category][0] ?? $category }}">
                                        @foreach ($items as $recipe)
                                            <option value="{{ $recipe->id }}">{{ $recipe->title }}@unless ($recipe->isPublished()) (brouillon)@endunless</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @error('avec') <p class="alert alert-error">{{ $message }}</p> @enderror
                            <p class="hint small">Chaque recette est calculée pour les mêmes convives et le même nombre de repas ; la liste de courses additionne tout.</p>
                        </div>
                    @endunless

                    <div class="serving-form" data-mode-block="parts">
                        <input type="hidden" name="ajuste" value="1">
                        <details class="more" @if ($custom || old('qui') !== null || old('adultes') || old('repas')) open @endif>
                        <summary>Pour qui et combien de repas ?</summary>
                        <p class="hint">Par défaut : les personnes présentes d'après ta semaine type, pour un seul repas.</p>
                        @if ($household->members->isNotEmpty())
                            <fieldset class="serving-row eaters">
                                <legend>Qui mange ?</legend>
                                @foreach ($household->members as $member)
                                    <label class="chip-check">
                                        <input type="checkbox" name="qui[]" value="{{ $member->id }}" @checked(in_array($member->id, old('qui', $eaters)))>
                                        <span>{{ $member->name }} <small>{{ $U::number($member->coefficientOn($on)) }}</small></span>
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
                            <small class="muted">Les restes vont sur les prochains déjeuners et dîners libres.</small>
                        </fieldset>
                        <label class="field-inline"><span>Parts par repas <small class="muted">(réglage libre)</small></span>
                            <input type="number" name="parts" min="0.5" max="50" step="0.5" value="{{ old('parts', $entry->parts_manual) }}" placeholder="{{ $U::number($household->members->whereIn('id', $eaters)->sum(fn ($m) => $m->coefficientOn($on))) }}" inputmode="decimal"></label>
                        </details>
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

        @if ($entry->exists && $entry->isMealDish() && $siblings->isNotEmpty())
            {{-- v0.22.0 : repas composé : les autres plats suivent le jour, le repas et les convives --}}
            <section class="panel">
                <label class="check">
                    <input type="checkbox" name="tout_le_repas" value="1" @checked(session()->hasOldInput() ? old('tout_le_repas') === '1' : true)>
                    <span>Appliquer le jour, le repas et les convives à tout le repas : {{ $siblings->filter(fn ($e) => $e->recipe)->map(fn ($e) => $e->recipe->title)->join(', ', ' et ') }}</span>
                </label>
                <p class="hint small">Le nombre de repas et les parts restent propres à chaque recette (une purée doublée pour le lendemain, par exemple).</p>
            </section>
        @endif

        <div class="actions">
            @if ($entry->exists)
                <a class="btn btn-ghost" href="{{ route('planning.week', \App\Support\MealPlanner::weekStart($date)->toDateString()) }}">Annuler</a>
            @endif
            <button type="submit" class="btn">{{ $isLeftover ? 'Placer les restes' : ($entry->exists ? 'Enregistrer' : 'Ajouter au planning') }}</button>
        </div>
    </form>

    @if ($entry->exists)
        <div class="row-actions ticket-actions">
            @if ($entry->isMealDish())
                <a class="btn btn-small btn-ghost" href="{{ route('planning.create', ['date' => $date, 'creneau' => $entry->slot]) }}">+ Ajouter une recette à ce repas</a>
            @endif
            @if ($isLeftover && ! $entry->is_frozen)
                <form method="post" action="{{ route('planning.freeze', $entry) }}">
                    @csrf
                    <button type="submit" class="btn btn-small btn-ghost">🧊 Mettre au congélateur</button>
                </form>
            @endif
            <form method="post" action="{{ route('planning.destroy', $entry) }}">
                @csrf @method('delete')
                <button type="submit" class="btn btn-small btn-danger" @php $partOfMeal = ! $isLeftover && $entry->isMealDish() && $siblings->isNotEmpty() && $entry->recipe; @endphp
                <button type="submit" class="btn btn-small btn-danger" @unless ($isLeftover) data-confirm="{{ $partOfMeal ? 'Retirer « '.$entry->recipe->title.' »'.($entry->leftovers()->exists() ? ' et ses restes' : '').' de ce repas ? Le reste du repas est conservé.' : 'Retirer ce repas'.($entry->leftovers()->exists() ? ' et ses restes' : '').' du planning ?' }}" @endunless>{{ $isLeftover ? 'Retirer ces restes (mangés, jetés)' : ($partOfMeal ? 'Retirer « '.$entry->recipe->title.' » de ce repas' : 'Retirer ce repas du planning') }}</button>
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
            // v0.22.0 : recettes « avec » le plat, et menu enregistré qui remplit le plat et ses recettes
            var list = form.querySelector('.extra-list');
            var adder = form.querySelector('.extra-add');
            var titles = list ? JSON.parse(list.dataset.titles || '{}') : {};
            function extraIds() {
                return Array.prototype.map.call(form.querySelectorAll('[name="avec[]"]'), function (i) { return i.value; });
            }
            function addExtra(id) {
                id = String(id);
                if (!list || !titles[id] || extraIds().indexOf(id) !== -1 || (select && select.value === id)) return;
                var li = document.createElement('li');
                var label = document.createElement('span');
                label.textContent = titles[id];
                var input = document.createElement('input');
                input.type = 'hidden'; input.name = 'avec[]'; input.value = id;
                var remove = document.createElement('button');
                remove.type = 'button'; remove.className = 'btn btn-small btn-ghost'; remove.textContent = 'Retirer';
                remove.setAttribute('data-extra-remove', '');
                remove.setAttribute('aria-label', 'Retirer ' + titles[id]);
                li.append(label, input, remove);
                list.appendChild(li);
            }
            if (list) {
                list.addEventListener('click', function (event) {
                    var button = event.target.closest('[data-extra-remove]');
                    if (button) button.closest('li').remove();
                });
            }
            if (adder) {
                adder.addEventListener('change', function (event) {
                    event.stopPropagation();
                    if (adder.value) addExtra(adder.value);
                    adder.value = '';
                });
            }
            var menuPick = form.querySelector('.menu-pick');
            if (menuPick) {
                menuPick.addEventListener('change', function () {
                    var option = menuPick.options[menuPick.selectedIndex];
                    var ids = option && option.dataset.recipes ? JSON.parse(option.dataset.recipes) : [];
                    if (!ids.length) return;
                    var radio = form.querySelector('[data-kind][value=recette]');
                    if (radio) radio.checked = true;
                    if (select) {
                        select.value = String(ids[0]);
                        if (filter) { filter.value = ''; filter.dispatchEvent(new Event('input')); }
                    }
                    if (list) list.innerHTML = '';
                    ids.slice(1).forEach(addExtra);
                    sync();
                });
            }
            sync();
        })();
    </script>
@endsection
