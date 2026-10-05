@extends('layouts.app')

@section('title', 'Stock')

@section('content')
    @php
        $locations = \App\Models\PantryItem::LOCATIONS;
    @endphp

    <section class="hero hero-compact planning-head">
        <div>
            <p class="eyebrow">Stock</p>
            <h1>Ce qu'il y a déjà à la maison</h1>
        </div>
        <div class="week-nav">
            <a class="btn btn-small" href="{{ route('stock.recipes') }}">Que cuisiner ?</a>
        </div>
    </section>

    @if ($soon->isNotEmpty())
        <section class="panel stock-soon">
            <h2>À consommer vite</h2>
            <ul class="stock-list">
                @foreach ($soon as $lot)
                    <li>
                        <span><strong>{{ $lot->ingredient->name }}</strong> <span class="muted">{{ $lot->quantityLabel() }}</span></span>
                        <span @class(['stock-expiry', 'is-expired' => $lot->isExpired(), 'is-soon' => $lot->isSoon()])>{{ $lot->expiryLabel() }}</span>
                    </li>
                @endforeach
            </ul>
            <p class="hint small"><a href="{{ route('stock.recipes') }}">Voir les recettes qui les utilisent →</a></p>
        </section>
    @endif

    <section class="panel">
        <h2>Ajouter au stock</h2>
        <form method="post" action="{{ route('stock.store') }}" class="stock-add">
            @csrf
            <label class="field">Ingrédient
                <input type="text" name="ingredient" list="stock-ingredients" required maxlength="120" value="{{ old('ingredient') }}" placeholder="Lait entier, farine…">
            </label>
            <label class="field field-small">Quantité
                <input type="text" name="quantite" inputmode="decimal" required value="{{ old('quantite') }}" placeholder="500">
            </label>
            <label class="field field-small">Unité
                <select name="unite">@foreach ($units as $code => $label)<option value="{{ $code }}" @selected(old('unite', 'g') === $code)>{{ $label }}</option>@endforeach</select>
            </label>
            <label class="field">Date limite
                <input type="date" name="expires_on" value="{{ old('expires_on') }}">
            </label>
            <label class="field">Où ?
                <select name="location"><option value="">(selon l'ingrédient)</option>@foreach ($locations as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
            </label>
            <button type="submit" class="btn">Ajouter</button>
        </form>
        @foreach (['ingredient', 'quantite', 'expires_on'] as $field)
            @error($field) <p class="alert alert-error">{{ $message }}</p> @enderror
        @endforeach
        <datalist id="stock-ingredients">@foreach ($ingredients as $name)<option value="{{ $name }}">@endforeach</datalist>
        <p class="hint small">Le stock se remplit aussi tout seul : en terminant les courses, les restes d'emballages (40 cl de lait sur une bouteille de 1 L…) y entrent, et ce qui a servi est retiré. Les listes de courses déduisent ce que tu as déjà.</p>
    </section>

    @forelse ($locations as $code => $label)
        @php $lots = $groups->get($code, collect()); @endphp
        @continue($lots->isEmpty())
        <section class="panel">
            <h2>{{ $label }} <span class="muted small">{{ $lots->count() }} ligne{{ $lots->count() > 1 ? 's' : '' }}</span></h2>
            <ul class="stock-list">
                @foreach ($lots as $lot)
                    @php [$value, $unit] = $lot->inputValue(); @endphp
                    <li id="lot-{{ $lot->id }}">
                        <span class="stock-main">
                            <strong>{{ $lot->ingredient->name }}</strong>
                            <span class="muted">{{ $lot->quantityLabel() }}</span>
                            @if ($lot->note) <span class="muted small">· {{ $lot->note }}</span> @endif
                            @if ($lot->source === 'courses') <span class="muted small">· reste des courses</span> @endif
                        </span>
                        <span class="stock-side">
                            @if ($lot->expires_on)
                                <span @class(['stock-expiry', 'is-expired' => $lot->isExpired(), 'is-soon' => $lot->isSoon()])
                                      title="{{ $lot->expires_on->locale('fr')->isoFormat('D MMMM YYYY') }}">{{ $lot->expiryLabel() }}</span>
                            @endif
                            <details class="sl-more">
                                <summary aria-label="Modifier {{ $lot->ingredient->name }}">⋯</summary>
                                <div class="sl-menu">
                                    <form method="post" action="{{ route('stock.update', $lot) }}" class="stock-edit">
                                        @csrf @method('put')
                                        <label class="field-inline">Quantité
                                            <input type="text" name="quantite" inputmode="decimal" value="{{ str_replace('.', ',', (string) $value) }}" required>
                                        </label>
                                        <select name="unite" aria-label="Unité">@foreach ($units as $c => $l)<option value="{{ $c }}" @selected($c === $unit)>{{ $l }}</option>@endforeach</select>
                                        <label class="field-inline">Date limite <input type="date" name="expires_on" value="{{ $lot->expires_on?->toDateString() }}"></label>
                                        <select name="location" aria-label="Emplacement">@foreach ($locations as $c => $l)<option value="{{ $c }}" @selected($c === $lot->location)>{{ $l }}</option>@endforeach</select>
                                        <input type="text" name="note" maxlength="80" value="{{ $lot->note }}" placeholder="Note (ouvert le…)">
                                        <button type="submit" class="btn btn-small">Enregistrer</button>
                                    </form>
                                    <form method="post" action="{{ route('stock.destroy', $lot) }}">
                                        @csrf @method('delete')
                                        <button type="submit" class="btn btn-small btn-ghost">Retirer du stock (fini, jeté)</button>
                                    </form>
                                </div>
                            </details>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
    @endforelse

    @if ($count === 0)
        <section class="panel">
            <p>Le stock est vide. Ajoute ce que tu as déjà ci-dessus, ou termine une liste de courses : les restes d'emballages y seront rangés automatiquement.</p>
        </section>
    @endif
@endsection
