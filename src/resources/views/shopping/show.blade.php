@extends('layouts.app')

@section('title', 'Courses')

@section('content')
    @php
        $Price = \App\Models\Price::class;
        $percent = $budget > 0 ? (int) round($total / $budget * 100) : 0;
        $level = $percent > 100 ? 'over' : ($percent >= 80 ? 'warn' : 'ok');
        $archived = $list->isArchived();
    @endphp

    <div data-shopping data-state-url="{{ route('shopping.state', $list) }}" data-revision="{{ $list->revision }}" data-me="{{ auth()->user()->firstName() }}" @class(['is-archived' => $archived])>

        <section class="hero hero-compact planning-head">
            <div>
                <p class="eyebrow">Courses{{ $archived ? ' · terminées' : '' }}</p>
                <h1>Courses du {{ $list->periodLabel() }}</h1>
            </div>
            <div class="week-nav">
                @if ($archived)
                    <form method="post" action="{{ route('shopping.reopen', $list) }}">@csrf <button type="submit" class="btn btn-small">Rouvrir cette liste</button></form>
                @else
                    <form method="post" action="{{ route('shopping.refresh', $list) }}">@csrf <button type="submit" class="btn btn-small btn-ghost">Recalculer d'après le planning</button></form>
                    <form method="post" action="{{ route('shopping.archive', $list) }}">@csrf <button type="submit" class="btn btn-small btn-ghost" data-confirm="Les courses sont faites ? La liste sera classée dans l'historique.">Courses terminées</button></form>
                @endif
            </div>
        </section>

        <div class="alert alert-info" data-stale-note @if (! $stale) hidden @endif>
            Le planning a changé depuis le calcul de cette liste.
            <form method="post" action="{{ route('shopping.refresh', $list) }}" class="inline-form">@csrf <button type="submit" class="btn btn-small">Mettre à jour la liste</button></form>
        </div>
        <div class="alert alert-info" data-changed hidden>
            La liste vient d'être modifiée par quelqu'un d'autre. <a href="{{ route('shopping.show', $list) }}">Actualiser</a>
        </div>

        <section class="panel budget-panel">
            <div class="budget-line">
                <strong>Estimation en caisse : {{ $Price::formatCents($total) }}</strong>
                <span class="muted">pour un budget de {{ $Price::formatCents($budget) }} ({{ $percent }} %), {{ $list->days() }} jour{{ $list->days() > 1 ? 's' : '' }}</span>
            </div>
            <div class="gauge gauge-{{ $level }}" role="img" aria-label="{{ $percent }} % du budget"><span style="width: {{ min(100, $percent) }}%"></span></div>
            <p class="hint small">
                <strong data-done>{{ $done }}</strong> / <span data-count>{{ $count }}</span> articles cochés ·
                @if ($level === 'over') budget dépassé : pense à remplacer un plat par une recette plus économique.
                @elseif ($level === 'warn') plus de 80 % du budget.
                @else dans le budget. @endif
                @if ($unknown) · {{ $unknown }} article{{ $unknown > 1 ? 's' : '' }} sans prix connu (non compté{{ $unknown > 1 ? 's' : '' }}). @endif
                @if ($surplus >= 50) <br>Dont ≈ {{ $Price::formatCents($surplus) }} d'emballages entamés : ce qui reste ressert les semaines suivantes. @endif
                @if ($saving >= 20) <br>Économie possible : ≈ {{ $Price::formatCents($saving) }} en achetant certains articles ailleurs (« ⋯ » sur la ligne). @endif
            </p>
            <label class="check small"><input type="checkbox" data-hide-checked> Masquer les articles cochés</label>
        </section>

        @forelse ($byStore as $block)
            <section class="panel sl-store" data-store-block>
                <header class="sl-store-head">
                    <h2>{{ $block['store']?->name ?? 'Sans magasin' }}</h2>
                    <span class="muted small">
                        <span data-store-done>{{ $block['done'] }}</span> / {{ $block['count'] }} ·
                        {{ $Price::formatCents($block['total']) }}@if ($block['unknown']) + {{ $block['unknown'] }} prix inconnu{{ $block['unknown'] > 1 ? 's' : '' }}@endif
                    </span>
                </header>
                @foreach ($block['aisles'] as $group)
                    <h3 class="sl-aisle">{{ $group['aisle']?->name ?? 'Divers' }}</h3>
                    <ul class="sl-items">
                        @foreach ($group['items'] as $item)
                            @include('shopping._item', ['item' => $item])
                        @endforeach
                    </ul>
                @endforeach
            </section>
        @empty
            <section class="panel">
                <p>Aucun article à acheter pour cette période. Ajoute des plats dans le <a href="{{ route('planning.index') }}">planning</a> puis recalcule la liste, ou ajoute des articles à la main ci-dessous.</p>
            </section>
        @endforelse

        @if ($check->isNotEmpty())
            <section class="panel sl-store sl-check-block">
                <header class="sl-store-head">
                    <h2>À vérifier chez vous</h2>
                    <span class="muted small">produits de base, hors budget</span>
                </header>
                <p class="hint small">Sel, huile, farine, épices… utilisés par tes recettes. Regarde dans tes placards : s'il en manque, « ⋯ » → « Il m'en manque » les ajoute aux courses.</p>
                <ul class="sl-items">
                    @foreach ($check as $item)
                        @include('shopping._item', ['item' => $item])
                    @endforeach
                </ul>
            </section>
        @endif

        @unless ($archived)
            <section class="panel">
                <h2>Ajouter un article</h2>
                <form method="post" action="{{ route('shopping.items.store', $list) }}" class="sl-add">
                    @csrf
                    <label class="field">Article
                        <input type="text" name="label" list="sl-ingredients" maxlength="120" required placeholder="Lessive, croquettes, yaourts…" value="{{ old('label') }}">
                    </label>
                    <label class="field field-small">Quantité
                        <input type="text" name="quantity_text" maxlength="60" placeholder="2" value="{{ old('quantity_text') }}">
                    </label>
                    <label class="field">Rayon
                        <select name="aisle_id"><option value="">(selon l'article)</option>@foreach ($aisles as $aisle)<option value="{{ $aisle->id }}">{{ $aisle->name }}</option>@endforeach</select>
                    </label>
                    <label class="field">Magasin
                        <select name="store_id"><option value="">(habituel)</option>@foreach ($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</select>
                    </label>
                    <button type="submit" class="btn">Ajouter</button>
                </form>
                <datalist id="sl-ingredients">
                    @foreach (\App\Models\Ingredient::orderBy('name')->pluck('name') as $name)<option value="{{ $name }}">@endforeach
                </datalist>
                <p class="hint small">Un article déjà connu de Foodtruck (lait, beurre…) reprend son rayon et son prix ; sinon c'est une ligne libre.</p>
            </section>

            <details class="panel" @if ($errors->has('date_from') || $errors->has('date_to')) open @endif>
                <summary><strong>Période et repas pris en compte</strong> <span class="muted small">({{ $entries->count() - count($excluded) }} plat{{ $entries->count() - count($excluded) > 1 ? 's' : '' }})</span></summary>
                <form method="post" action="{{ route('shopping.update', $list) }}" class="sl-period-form">
                    @csrf @method('put')
                    <div class="sl-period">
                        <label class="field-inline">Du <input type="date" name="date_from" value="{{ old('date_from', $list->date_from->toDateString()) }}" required></label>
                        <label class="field-inline">au <input type="date" name="date_to" value="{{ old('date_to', $list->date_to->toDateString()) }}" required></label>
                    </div>
                    @error('date_from') <p class="alert alert-error">{{ $message }}</p> @enderror
                    @error('date_to') <p class="alert alert-error">{{ $message }}</p> @enderror
                    @if ($entries->isEmpty())
                        <p class="muted">Aucun plat prévu sur cette période.</p>
                    @else
                        <ul class="sl-meals">
                            @foreach ($entries as $entry)
                                <li>
                                    <input type="hidden" name="vus[]" value="{{ $entry->id }}">
                                    <label class="check">
                                        <input type="checkbox" name="inclus[]" value="{{ $entry->id }}" @checked(! in_array($entry->id, $excluded, true))>
                                        <span>{{ ucfirst($entry->date->locale('fr')->isoFormat('ddd D MMM')) }} · {{ $entry->slotLabel() }} : <strong>{{ $entry->recipe->title }}</strong>
                                            <span class="muted small">{{ $entry->partsLabel($household) }}@if ($entry->meals > 1) · {{ $entry->meals }} repas @endif</span></span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                        <p class="hint small">Décoche un repas pour l'écarter des courses (invité ailleurs, restaurant, déjà en stock). Les restes ne sont jamais recomptés.</p>
                    @endif
                    <button type="submit" class="btn">Enregistrer et recalculer</button>
                </form>
            </details>
        @endunless
    </div>

    @include('shopping._previous', ['previous' => $previous])
@endsection
