@extends('layouts.app')

@section('title', 'Nouvel ingrédient')

@section('content')
    <section class="hero hero-compact">
        <p><a href="{{ route('ingredients.index') }}">← Ingrédients</a></p>
        <h1>Nouvel ingrédient</h1>
        <p class="lead">Vérifie d'abord qu'il n'existe pas déjà : la liste commune sert à toutes les recettes.</p>
    </section>

    <form method="post" action="{{ route('ingredients.store') }}" class="stack">
        @csrf
        <section class="panel">
            <h2>Caractéristiques</h2>
            @include('ingredients._fields')
        </section>

        <section class="panel">
            <h2>Premier conditionnement <small class="muted">(facultatif)</small></h2>
            <p class="hint">Ce qu'on achète réellement : « Bouteille 1 L », « Vrac au kg », « Boîte de 6 »… La quantité s'exprime dans l'unité de base (g, ml ou pièces).</p>
            <div class="grid-2">
                <label class="field">
                    <span>Libellé</span>
                    <input type="text" name="pack_label" value="{{ old('pack_label') }}" maxlength="60" placeholder="Bouteille 1 L">
                </label>
                <label class="field">
                    <span>Quantité (g, ml ou pièces)</span>
                    <input type="number" name="pack_quantity" value="{{ old('pack_quantity') }}" min="0" step="any" placeholder="1000">
                </label>
                <label class="field">
                    <span>Prix</span>
                    <span class="input-suffix">
                        <input type="text" name="price" value="{{ old('price') }}" inputmode="decimal" placeholder="1,05">
                        <span>€</span>
                    </span>
                </label>
                <label class="field">
                    <span>Magasin</span>
                    <select name="price_store_id">
                        <option value="">—</option>
                        @foreach ($stores as $store)
                            <option value="{{ $store->id }}" @selected((int) old('price_store_id', auth()->user()->household->main_store_id) === $store->id)>{{ $store->name }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <label class="check">
                <input type="checkbox" name="pack_bulk" value="1" @checked(old('pack_bulk'))>
                <span>Vendu en vrac (on achète la quantité voulue, prix au kg ou au litre)</span>
            </label>
        </section>

        <div class="actions">
            <a class="btn btn-ghost" href="{{ route('ingredients.index') }}">Annuler</a>
            <button type="submit" class="btn">Ajouter l'ingrédient</button>
        </div>
    </form>
@endsection
