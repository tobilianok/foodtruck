{{-- Champs communs création / modification d'un ingrédient --}}
@php
    $months = old('season_months', $ingredient->season_months ?? []);
    $locked = $ingredient->exists && $ingredient->packs->isNotEmpty();
@endphp
<div class="grid-2">
    <label class="field">
        <span>Nom</span>
        <input type="text" name="name" value="{{ old('name', $ingredient->name) }}" maxlength="80" required>
    </label>
    <label class="field">
        <span>Rayon</span>
        <select name="aisle_id" required>
            @foreach ($aisles as $aisle)
                <option value="{{ $aisle->id }}" @selected((int) old('aisle_id', $ingredient->aisle_id) === $aisle->id)>{{ $aisle->name }}</option>
            @endforeach
        </select>
    </label>
    <label class="field">
        <span>Unité de base</span>
        <select name="base_unit" @disabled($locked)>
            @foreach (\App\Support\Units::BASE_CHOICES as $value => $label)
                <option value="{{ $value }}" @selected(old('base_unit', $ingredient->base_unit) === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @if ($locked)
            <input type="hidden" name="base_unit" value="{{ $ingredient->base_unit }}">
            <small>Verrouillée : les conditionnements l'utilisent.</small>
        @else
            <small>Comment on compte cet ingrédient et ses conditionnements.</small>
        @endif
    </label>
    <label class="field">
        <span>Poids d'une pièce (g)</span>
        <input type="number" name="piece_weight_g" value="{{ old('piece_weight_g', $ingredient->piece_weight_g) }}" min="0" step="0.5" placeholder="ex. 125 pour une carotte">
        <small>Permet d'écrire « 2 carottes » dans une recette.</small>
    </label>
    {{-- v0.20.1 : boisson, bouillon… comptés à la pièce : « 15 ml » dans une recette devient une part de bouteille --}}
    <label class="field">
        <span>Ou contenance d'une pièce (ml)</span>
        <input type="number" name="piece_volume_ml" value="{{ old('piece_volume_ml') }}" min="0" step="1" placeholder="ex. 2000 pour une bouteille de 2 L">
        <small>Permet d'écrire « 15 ml » d'un produit qui s'achète à la pièce.@if ($ingredient->piece_weight_g && $ingredient->density) Actuellement : 1 pièce ≈ {{ \App\Support\Units::number($ingredient->piece_weight_g / $ingredient->density, 0) }} ml.@endif</small>
    </label>
</div>

<details class="add-block" @if (old('density', $ingredient->density)) open @endif>
    <summary>Réglage avancé : densité</summary>
    <label class="field field-medium">
        <span>Densité (g par ml)</span>
        <input type="number" name="density" value="{{ old('density', $ingredient->density) }}" min="0.05" max="5" step="0.01" placeholder="ex. 1,03 pour le lait">
        <small>Pour passer du poids au volume (ex. « 100 g de lait » ↔ ml).</small>
    </label>
</details>

<fieldset class="field">
    <legend>Mois de saison <small>(aucun coché = toute l'année)</small></legend>
    <div class="chips">
        @foreach (\App\Models\Ingredient::MONTHS as $number => $label)
            <label class="chip">
                <input type="checkbox" name="season_months[]" value="{{ $number }}" @checked(in_array($number, array_map('intval', (array) $months), true))>
                <span>{{ $label }}</span>
            </label>
        @endforeach
    </div>
</fieldset>

<div class="checks">
    <label class="check">
        <input type="hidden" name="is_fresh" value="0">
        <input type="checkbox" name="is_fresh" value="1" @checked(old('is_fresh', $ingredient->is_fresh))>
        <span>Produit frais</span>
    </label>
    <label class="check">
        <input type="hidden" name="is_staple" value="0">
        <input type="checkbox" name="is_staple" value="1" @checked(old('is_staple', $ingredient->is_staple))>
        <span>Produit de base, souvent déjà en stock (sel, huile…) : listé « à vérifier » dans les courses</span>
    </label>
</div>
