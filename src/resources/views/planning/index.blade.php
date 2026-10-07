@extends('layouts.app')

@section('title', 'Planning')
@section('main-class', 'wrap-wide')

@section('content')
    @php
        $Price = \App\Models\Price::class;
        $end = $start->copy()->addDays(6);
        $percent = $budget > 0 ? (int) round($cost['total_cents'] / $budget * 100) : 0;
        $level = $percent > 100 ? 'over' : ($percent >= 80 ? 'warn' : 'ok');
        $slotIndex = array_flip($slots);
    @endphp

    <x-page-header :title="'Semaine du '.$start->locale('fr')->isoFormat('D MMMM').' au '.$end->locale('fr')->isoFormat('D MMMM YYYY')"
                   lead="Touche un + pour ajouter un plat, un repas hors maison ou une note.">
        <nav class="week-nav" aria-label="Semaines">
            <a class="btn btn-small btn-ghost" href="{{ route('planning.week', $start->copy()->subWeek()->toDateString()) }}">← Semaine précédente</a>
            @unless ($today >= $start->toDateString() && $today <= $end->toDateString())
                <a class="btn btn-small btn-ghost" href="{{ route('planning.index') }}">Cette semaine</a>
            @endunless
            <a class="btn btn-small btn-ghost" href="{{ route('planning.week', $start->copy()->addWeek()->toDateString()) }}">Semaine suivante →</a>
            <a class="btn btn-small btn-ghost" href="{{ route('planning.menus') }}">Mes menus</a>
        </nav>
    </x-page-header>

    @php
        $proposalCount = $entries->filter(fn ($e) => $e->isProposal() && $e->isRecipe())->count();
    @endphp
    <section class="panel menu-panel" id="menu-auto">
        @if ($proposalCount > 0)
            <h2>Menu proposé pour la semaine</h2>
            <p class="hint">{{ $proposalCount }} plat{{ $proposalCount > 1 ? 's' : '' }} proposé{{ $proposalCount > 1 ? 's' : '' }} : garde ceux qui te plaisent, change les autres (« Autre idée »), puis valide. Tant que le menu n'est pas validé, il n'entre pas dans la liste de courses.</p>
            <div class="row-actions">
                <form method="post" action="{{ route('menu.accept') }}">@csrf <input type="hidden" name="semaine" value="{{ $start->toDateString() }}"><button type="submit" class="btn">Valider le menu</button></form>
                <form method="post" action="{{ route('menu.generate') }}">@csrf <input type="hidden" name="semaine" value="{{ $start->toDateString() }}"><button type="submit" class="btn btn-ghost" data-confirm="Tout reproposer ? Les plats proposés sont remplacés (ceux déjà gardés ne bougent pas).">Tout reproposer</button></form>
                <form method="post" action="{{ route('menu.clear') }}">@csrf <input type="hidden" name="semaine" value="{{ $start->toDateString() }}"><button type="submit" class="btn btn-ghost btn-danger" data-confirm="Effacer toutes les propositions de la semaine ?">Effacer</button></form>
            </div>
        @else
            <h2>Menu automatique</h2>
            <p class="hint">Foodtruck propose les déjeuners et dîners libres de la semaine : budget respecté, produits à finir du stock, recettes de saison, plats rapides en semaine, protéines variées. Les repas déjà prévus sont conservés.</p>
            <form method="post" action="{{ route('menu.generate') }}" class="menu-form">
                @csrf
                <input type="hidden" name="semaine" value="{{ $start->toDateString() }}">
                <label>Repas végétariens au moins
                    <select name="vegetarien">
                        @foreach (range(0, 5) as $n)
                            <option value="{{ $n }}" @selected((int) $household->menu_veggy_min === $n)>{{ $n }} par semaine</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="btn">Proposer la semaine</button>
            </form>
        @endif
    </section>

    @if ($entries->where('kind', 'recette')->isEmpty())
        <section class="empty">
            <span class="empty-icon">@include('partials.icon', ['name' => 'plate'])</span>
            <h2>Cette semaine est encore vide</h2>
            <p>Ajoute un premier repas : Foodtruck calcule les quantités pour ton foyer, puis prépare la liste de courses.</p>
            <a class="btn" href="{{ route('planning.create') }}">Ajouter un repas</a>
        </section>
    @endif

    <section class="panel budget-panel">
        <div class="budget-line">
            <strong>Coût estimé des repas : {{ $Price::formatCents($cost['total_cents']) }}</strong>
            <span class="muted">sur un budget de {{ $Price::formatCents($budget) }} ({{ $percent }} %)</span>
        </div>
        <div class="gauge gauge-{{ $level }}" role="img" aria-label="{{ $percent }} % du budget">
            <span style="width: {{ min(100, $percent) }}%"></span>
        </div>
        <p class="hint small">
            @if ($level === 'over') Budget dépassé : remplace un plat par une recette économique ou de saison.
            @elseif ($level === 'warn') Plus de 80 % du budget : les derniers repas sont à choisir parmi les recettes économiques.
            @else Estimation au prorata des quantités utilisées ; le coût réel des courses (conditionnements entiers) viendra avec la liste de courses.
            @endif
            @if ($cost['incomplete']) Prix inconnus pour : {{ implode(', ', $cost['incomplete']) }}. @endif
        </p>
    </section>

    @if ($swaps)
        <section class="panel swaps-panel">
            <h2>Économiser sur la semaine</h2>
            <p class="hint small">Le budget est entamé à plus de 80 % : voici des recettes moins chères, pour le même nombre de convives, à la place des plats les plus coûteux encore à cuisiner.</p>
            @foreach ($swaps as $swap)
                <div class="swap">
                    <p class="swap-dish"><strong>{{ $swap['entry']->recipe->title }}</strong>
                        <span class="muted small">· {{ $swap['entry']->date->locale('fr')->isoFormat('dddd D') }}, {{ mb_strtolower($swap['entry']->slotLabel()) }} · {{ $Price::formatCents($swap['cost_cents']) }}</span></p>
                    <ul class="frozen-list">
                        @foreach ($swap['options'] as $option)
                            <li>
                                <span><a href="{{ route('recipes.show', $option['recipe']) }}">{{ $option['recipe']->title }}</a>
                                    <span class="muted small">· {{ $Price::formatCents($option['cost_cents']) }}</span>
                                    <span class="badge-cheap">−{{ $Price::formatCents($option['saving_cents']) }}</span></span>
                                <form method="post" action="{{ route('planning.replace', $swap['entry']) }}">
                                    @csrf
                                    <input type="hidden" name="recipe_id" value="{{ $option['recipe']->id }}">
                                    <button type="submit" class="btn btn-small btn-ghost" data-confirm="Remplacer « {{ $swap['entry']->recipe->title }} » par « {{ $option['recipe']->title }} » ?">Remplacer</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </section>
    @endif

    @if ($soonLots->isNotEmpty())
        <section class="panel stock-soon">
            <strong>À consommer vite :</strong>
            @foreach ($soonLots->take(6) as $lot)
                {{ $lot->ingredient->name }} <span @class(['stock-expiry', 'is-expired' => $lot->isExpired(), 'is-soon' => $lot->isSoon()])>{{ $lot->expiryLabel() }}</span>{{ $loop->last ? '' : ' ·' }}
            @endforeach
            <a href="{{ route('stock.recipes') }}">Que cuisiner ? →</a>
        </section>
    @endif

    <section class="week-grid">
        <div class="wg-corner" aria-hidden="true"></div>
        @foreach ($days as $d => $day)
            @php $isToday = $day->toDateString() === $today; @endphp
            <div @class(['wg-day', 'is-today' => $isToday]) style="--c: {{ $d + 2 }}; --r: 1; --o: {{ $d * 10 }}">
                <span class="wg-dayname">{{ ucfirst($day->locale('fr')->isoFormat('dddd')) }}</span>
                <span class="wg-date">{{ $day->locale('fr')->isoFormat('D MMM') }}</span>
                @if ($isToday) <span class="badge-season small">aujourd'hui</span> @endif
            </div>
        @endforeach

        @foreach ($slots as $s => $slot)
            <div class="wg-slot" style="--r: {{ $s + 2 }}">{{ \App\Models\MealPlanEntry::SLOTS[$slot][0] }}</div>
            @foreach ($days as $d => $day)
                @php $cell = $grid->get($day->toDateString().'|'.$slot, collect()); @endphp
                <div @class(['wg-cell', 'is-today' => $day->toDateString() === $today, 'is-empty' => $cell->isEmpty()]) style="--c: {{ $d + 2 }}; --r: {{ $s + 2 }}; --o: {{ $d * 10 + $s + 1 }}">
                    <span class="wg-cell-slot">{{ \App\Models\MealPlanEntry::SLOTS[$slot][0] }}</span>
                    @php $present = $usual[$day->toDateString().'|'.$slot] ?? null; @endphp
                    @if ($present !== null)
                        <span @class(['wg-usual', 'is-nobody' => $present === []]) title="Semaine type (Mon foyer)">{{ $present === [] ? 'personne à la maison' : '👤 '.implode(', ', $present) }}</span>
                    @endif
                    @foreach ($cell as $entry)
                        @include('planning._entry', ['entry' => $entry, 'entryCost' => $cost['per_entry'][$entry->id] ?? null])
                    @endforeach
                    @php
                        // v0.22.0 : repas composé (au moins deux recettes cuisinées) : coût du repas, menu d'origine ou « Enregistrer comme menu »
                        $dishes = $cell->filter(fn ($e) => $e->isMealDish());
                        $menuIds = $dishes->pluck('saved_menu_id')->unique();
                    @endphp
                    @if ($dishes->pluck('recipe_id')->unique()->count() >= 2)
                        <div class="meal-sum">
                            <span>Repas : {{ $Price::formatCents($dishes->sum(fn ($e) => $cost['per_entry'][$e->id] ?? 0)) }}</span>
                            @if ($menuIds->count() === 1 && $dishes->first()->savedMenu)
                                <span class="meal-menu" title="Menu enregistré">☰ {{ $dishes->first()->savedMenu->name }}</span>
                            @else
                                <a class="meal-save" href="{{ route('planning.menus.create', ['date' => $day->toDateString(), 'creneau' => $slot]) }}">Enregistrer comme menu</a>
                            @endif
                        </div>
                    @endif
                    <a class="wg-add" href="{{ route('planning.create', ['date' => $day->toDateString(), 'creneau' => $slot]) }}"
                       aria-label="Ajouter : {{ \App\Models\MealPlanEntry::SLOTS[$slot][0] }} du {{ $day->locale('fr')->isoFormat('dddd D') }}">+</a>
                </div>
            @endforeach
        @endforeach
    </section>

    @if ($frozen->isNotEmpty())
        <section class="panel" id="congelateur">
            <h2>Restes mis de côté <span class="muted small">(congélateur ou à placer)</span></h2>
            <ul class="frozen-list">
                @foreach ($frozen as $leftover)
                    @php $title = $leftover->source?->recipe?->title ?? $leftover->recipe?->title ?? 'Restes'; @endphp
                    <li>
                        <span>🧊 <strong>{{ $title }}</strong> <span class="muted small">{{ $leftover->partsLabel($household) }} · cuisiné le {{ $leftover->source?->date?->locale('fr')->isoFormat('dddd D MMMM') }}</span></span>
                        <span class="row-actions">
                            <a class="btn btn-small" href="{{ route('planning.edit', $leftover) }}">Planifier</a>
                            <form method="post" action="{{ route('planning.destroy', $leftover) }}">
                                @csrf @method('delete')
                                <button type="submit" class="btn btn-small btn-ghost">Mangé / jeté</button>
                            </form>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <details class="more">
        <summary>Bon à savoir</summary>
        <p class="hint">Un repas peut réunir plusieurs recettes (plat, accompagnement, entrée, dessert) : ajoute-les ensemble, ou avec le + de la case ; elles sont calculées pour les mêmes convives. Un repas composé s'enregistre comme menu, à replanifier en un clic (<a href="{{ route('planning.menus') }}">Mes menus</a>).</p>
        <p class="hint">Un plat prévu pour plusieurs repas place ses restes sur les déjeuners et dîners libres suivants où quelqu'un mange à la maison. Les présences habituelles se règlent dans <a href="{{ route('household.show') }}#semaine-type">Mon foyer → Semaine type</a>.</p>
    </details>
@endsection
