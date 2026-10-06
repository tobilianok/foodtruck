@extends('layouts.app')

@section('title', $ingredient->name)

@section('content')
    @php
        $month = (int) now('Europe/Paris')->month;
        $season = $ingredient->isInSeason($month);
    @endphp

    <section class="hero hero-compact">
        <p><a href="{{ route('ingredients.index') }}">← Ingrédients</a></p>
        <h1>{{ $ingredient->name }}</h1>
        <p><a class="btn btn-small btn-ghost" href="#caracteristiques">Modifier le nom, le rayon, la saison…</a></p>
        <p class="lead">
            {{ $ingredient->aisle->name }} · {{ Str::lower($ingredient->baseUnitLabel()) }} · saison : {{ $ingredient->seasonLabel() }}
            @if ($season === true) <span class="badge-season">de saison</span> @elseif ($season === false) <span class="badge-off">hors saison</span> @endif
        </p>
        @if ($best)
            <p class="best">
                Meilleur prix connu : <strong>{{ \App\Models\Price::formatCents($best['per_reference_cents']) }}/{{ $ingredient->referenceUnit() }}</strong>
                ({{ $best['pack']->label }} à {{ \App\Models\Price::formatCents($best['price']->price_cents) }}, {{ $best['price']->store->name }}@if ($best['price']->source === 'estimation'), prix estimé @endif)
            </p>
        @endif
    </section>

    {{-- Prix par conditionnement et par magasin --}}
    <section class="panel">
        <h2>Prix par magasin</h2>
        @if ($ingredient->packs->isEmpty())
            <p class="hint">Ajoute d'abord un conditionnement (plus bas) pour pouvoir saisir des prix.</p>
        @else
            <div class="table-wrap">
                <table class="price-table">
                    <thead>
                        <tr>
                            <th>Conditionnement</th>
                            @foreach ($stores as $store)
                                <th>{{ $store->name }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ingredient->packs as $pack)
                            @php $current = $pack->setRelation('ingredient', $ingredient)->currentPrices(); @endphp
                            <tr>
                                <th scope="row">{{ $pack->label }}@unless (str_contains($pack->label, $pack->quantityLabel())) <span class="muted">{{ $pack->quantityLabel() }}</span>@endunless @if ($pack->is_bulk)<span class="muted">vrac</span>@endif</th>
                                @foreach ($stores as $store)
                                    @php $price = $current->get($store->id); @endphp
                                    <td @class(['is-best' => $best && $price && $price->is($best['price'])])>
                                        @if ($price)
                                            <strong>{{ \App\Models\Price::formatCents($price->price_cents) }}</strong>
                                            @if ($price->is_promo) <span class="badge-promo">promo</span> @endif
                                            <span class="muted small">{{ \App\Models\Price::formatCents($pack->perReferenceCents($price)) }}/{{ $ingredient->referenceUnit() }}</span>
                                            <span @class(['muted', 'small', 'is-estimate' => $price->source === 'estimation'])>{{ $price->sourceLabel() }} {{ $price->observed_on->format('d/m/y') }}</span>
                                        @else
                                            <span class="muted">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <form method="post" action="{{ route('ingredients.prices.store', $ingredient) }}" class="price-form">
                @csrf
                <label class="field">
                    <span>Conditionnement</span>
                    <select name="ingredient_pack_id">
                        @foreach ($ingredient->packs as $pack)
                            <option value="{{ $pack->id }}">{{ $pack->label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">
                    <span>Magasin</span>
                    <select name="store_id">
                        @foreach ($stores as $store)
                            <option value="{{ $store->id }}" @selected(auth()->user()->household->main_store_id === $store->id)>{{ $store->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field field-small">
                    <span>Prix (€)</span>
                    <input type="text" name="price" inputmode="decimal" placeholder="1,05" required>
                </label>
                <label class="field field-medium">
                    <span>Date</span>
                    <input type="date" name="observed_on" value="{{ now('Europe/Paris')->toDateString() }}" max="{{ now('Europe/Paris')->toDateString() }}" required>
                </label>
                <label class="check">
                    <input type="checkbox" name="is_promo" value="1">
                    <span>Promo</span>
                </label>
                <button type="submit" class="btn btn-small">Enregistrer le prix</button>
            </form>
        @endif
    </section>

    {{-- Conditionnements --}}
    <section class="panel">
        <h2>Conditionnements</h2>
        <p class="hint">Quantité dans l'unité de base ({{ Str::lower($ingredient->baseUnitLabel()) }}). « Vrac » : on achète la quantité voulue au prix du kg ou du litre.</p>
        <div class="list">
            @foreach ($ingredient->packs as $pack)
                <form method="post" action="{{ route('ingredients.packs.update', [$ingredient, $pack]) }}" class="pack-row">
                    @csrf @method('put')
                    <label class="field">
                        <span>Libellé</span>
                        <input type="text" name="label" value="{{ $pack->label }}" maxlength="60" required>
                    </label>
                    <label class="field field-small">
                        <span>Quantité</span>
                        <input type="number" name="quantity" value="{{ $pack->quantity + 0 }}" min="0" step="any" required>
                    </label>
                    <label class="check">
                        <input type="checkbox" name="is_bulk" value="1" @checked($pack->is_bulk)>
                        <span>Vrac</span>
                    </label>
                    <div class="row-actions">
                        <button type="submit" class="btn btn-small">Enregistrer</button>
                        <button type="submit" class="btn btn-small btn-danger" form="delete-pack-{{ $pack->id }}"
                                data-confirm="Supprimer « {{ $pack->label }} » et ses prix ?">Supprimer</button>
                    </div>
                </form>
                <form method="post" action="{{ route('ingredients.packs.destroy', [$ingredient, $pack]) }}" id="delete-pack-{{ $pack->id }}" hidden>
                    @csrf @method('delete')
                </form>
            @endforeach
        </div>
        <details class="add-block" @if ($ingredient->packs->isEmpty()) open @endif>
            <summary>+ Ajouter un conditionnement</summary>
            <form method="post" action="{{ route('ingredients.packs.store', $ingredient) }}" class="pack-row">
                @csrf
                <label class="field">
                    <span>Libellé</span>
                    <input type="text" name="label" maxlength="60" placeholder="Bouteille 1 L" required>
                </label>
                <label class="field field-small">
                    <span>Quantité</span>
                    <input type="number" name="quantity" min="0" step="any" placeholder="1000" required>
                </label>
                <label class="check">
                    <input type="checkbox" name="is_bulk" value="1">
                    <span>Vrac</span>
                </label>
                <div class="row-actions"><button type="submit" class="btn btn-small">Ajouter</button></div>
            </form>
        </details>
    </section>

    {{-- Unités de mesure (v0.16.0) --}}
    @php $base = $ingredient->base_unit; $baseLabel = \App\Support\Units::label($base); @endphp
    <section class="panel" id="unites">
        <h2>Unités de mesure</h2>
        <p class="hint">
            Référence : <strong>{{ Str::lower($ingredient->baseUnitLabel()) }}</strong> (liste de courses, prix, stock).
            Les recettes peuvent aussi utiliser ces unités, converties automatiquement :
            @if ($ingredient->piece_weight_g) 1 pièce = {{ \App\Support\Units::number($ingredient->piece_weight_g) }} g. @endif
            @if ($ingredient->density) 1 c. à soupe ≈ {{ \App\Support\Units::number(15 * $ingredient->density, 1) }} g. @endif
        </p>
        <div class="list">
            @foreach ($ingredient->units as $unit)
                <form method="post" action="{{ route('ingredients.units.update', [$ingredient, $unit]) }}" class="unit-row">
                    @csrf @method('put')
                    <label class="field">
                        <span>Unité @if ($unit->is_estimate)<span class="badge-estimate" title="Valeur typique proposée par Foodtruck : corrige-la si besoin">valeur typique</span>@endif</span>
                        <input type="text" name="name" value="{{ $unit->name }}" maxlength="40" required>
                    </label>
                    <label class="field">
                        <span>Pluriel</span>
                        <input type="text" name="plural" value="{{ $unit->plural }}" maxlength="40">
                    </label>
                    <label class="field">
                        <span>1 {{ $unit->name }} =</span>
                        <span class="input-suffix">
                            <input type="text" name="quantity" value="{{ \App\Support\Units::number($unit->quantity, 3) }}" inputmode="decimal" maxlength="12" required>
                            <span>{{ $baseLabel }}</span>
                        </span>
                    </label>
                    <div class="row-actions">
                        <button type="submit" class="btn btn-small">{{ $unit->is_estimate ? 'Valider' : 'Enregistrer' }}</button>
                        <button type="submit" class="btn btn-small btn-danger" form="delete-unit-{{ $unit->id }}"
                                data-confirm="Supprimer l'unité « {{ $unit->name }} » ?">Supprimer</button>
                    </div>
                </form>
                <form method="post" action="{{ route('ingredients.units.destroy', [$ingredient, $unit]) }}" id="delete-unit-{{ $unit->id }}" hidden>
                    @csrf @method('delete')
                </form>
            @endforeach
        </div>
        <details class="add-block" @if ($ingredient->units->isEmpty()) open @endif>
            <summary>+ Ajouter une unité</summary>
            <form method="post" action="{{ route('ingredients.units.store', $ingredient) }}" class="unit-row">
                @csrf
                <label class="field">
                    <span>Unité</span>
                    <input type="text" name="name" maxlength="40" placeholder="sachet" required>
                </label>
                <label class="field">
                    <span>Pluriel</span>
                    <input type="text" name="plural" maxlength="40" placeholder="sachets">
                </label>
                <label class="field">
                    <span>Équivalence</span>
                    <span class="input-suffix">
                        <input type="text" name="quantity" inputmode="decimal" maxlength="12" placeholder="10" required>
                        <span>{{ $baseLabel }}</span>
                    </span>
                </label>
                <div class="row-actions"><button type="submit" class="btn btn-small">Ajouter</button></div>
            </form>
        </details>
    </section>

    {{-- Caractéristiques --}}
    <section class="panel" id="caracteristiques">
        <h2>Caractéristiques</h2>
        <form method="post" action="{{ route('ingredients.update', $ingredient) }}" class="stack">
            @csrf @method('put')
            @include('ingredients._fields')
            <div class="actions"><button type="submit" class="btn">Enregistrer</button></div>
        </form>
    </section>

    {{-- Historique --}}
    @if ($history->isNotEmpty())
        <section class="panel">
            <h2>Historique des prix</h2>
            <div class="table-wrap">
                <table class="history-table">
                    <thead><tr><th>Date</th><th>Magasin</th><th>Conditionnement</th><th>Prix</th><th>Origine</th></tr></thead>
                    <tbody>
                        @foreach ($history as $price)
                            <tr>
                                <td>{{ $price->observed_on->format('d/m/Y') }}</td>
                                <td>{{ $price->store->name }}</td>
                                <td>{{ $price->pack->label }}</td>
                                <td>{{ \App\Models\Price::formatCents($price->price_cents) }}@if ($price->is_promo) <span class="badge-promo">promo</span>@endif</td>
                                <td class="muted">{{ $price->sourceLabel() }}@if ($price->creator) · {{ $price->creator->name }}@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
