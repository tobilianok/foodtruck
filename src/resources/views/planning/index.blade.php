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

    <section class="hero hero-compact planning-head">
        <div>
            <p class="eyebrow">Planning</p>
            <h1>Semaine du {{ $start->locale('fr')->isoFormat('D MMMM') }} au {{ $end->locale('fr')->isoFormat('D MMMM YYYY') }}</h1>
        </div>
        <nav class="week-nav" aria-label="Semaines">
            <a class="btn btn-small btn-ghost" href="{{ route('planning.week', $start->copy()->subWeek()->toDateString()) }}">← Semaine précédente</a>
            @unless ($today >= $start->toDateString() && $today <= $end->toDateString())
                <a class="btn btn-small btn-ghost" href="{{ route('planning.index') }}">Cette semaine</a>
            @endunless
            <a class="btn btn-small btn-ghost" href="{{ route('planning.week', $start->copy()->addWeek()->toDateString()) }}">Semaine suivante →</a>
        </nav>
    </section>

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

    <p class="hint small">Clique sur « + » pour ajouter un plat, un repas hors maison ou une note. Un plat prévu pour plusieurs repas place ses restes sur les déjeuners et dîners libres suivants où quelqu'un mange à la maison. Les présences habituelles se règlent dans <a href="{{ route('household.show') }}#semaine-type">Mon foyer → Semaine type</a>.</p>
@endsection
