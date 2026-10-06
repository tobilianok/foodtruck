{{-- Une ligne d'ingrédient : titres des colonnes affichés une seule fois au-dessus de la liste (sur ordinateur) --}}
@php
    // v0.16.0 : unités propres de l'ingrédient de la ligne (« sachet », « gousse »…), en plus des unités générales
    $ownUnits = ($ingredientUnits ?? [])[$row['name'] ?? ''] ?? [];
    $currentUnit = $row['unit'] ?? '';
    if (\App\Support\Units::isCustom($currentUnit) && ! isset($ownUnits[$currentUnit])) {
        $ownUnits[$currentUnit] = str_replace('-', ' ', \App\Support\Units::customSlug($currentUnit));
    }
@endphp
<div class="row ingredient-row @if (! empty($row['problem'])) has-problem @endif" data-row>
    <input type="hidden" name="ingredients[{{ $key }}][label]" value="{{ $row['label'] ?? '' }}">
    <label class="field ing-name">
        <span class="row-label">Ingrédient</span>
        <input type="text" name="ingredients[{{ $key }}][name]" value="{{ $row['name'] ?? '' }}" list="ingredient-names" maxlength="80" autocomplete="off" placeholder="Commence à taper…" data-ingredient-name>
    </label>
    <label class="field ing-qty">
        <span class="row-label">Quantité</span>
        <input type="text" name="ingredients[{{ $key }}][quantity]" value="{{ $row['quantity'] ?? '' }}" inputmode="decimal" maxlength="12" placeholder="Qté">
    </label>
    <label class="field ing-unit">
        <span class="row-label">Unité</span>
        <select name="ingredients[{{ $key }}][unit]" data-unit-select>
            <option value="">selon goût</option>
            <optgroup label="Unités de l'ingrédient" data-own-units @if ($ownUnits === []) hidden @endif>
                @foreach ($ownUnits as $code => $label)
                    <option value="{{ $code }}" @selected($currentUnit === $code)>{{ $label }}</option>
                @endforeach
            </optgroup>
            <optgroup label="Unités générales">
                @foreach (\App\Support\Units::UNITS as $code => [$label])
                    <option value="{{ $code }}" @selected($currentUnit === $code)>{{ $label }}</option>
                @endforeach
            </optgroup>
        </select>
    </label>
    <label class="field ing-note">
        <span class="row-label">Précision</span>
        <input type="text" name="ingredients[{{ $key }}][note]" value="{{ $row['note'] ?? '' }}" maxlength="120" placeholder="émincé, bien mûr…">
    </label>
    <label class="field ing-group">
        <span class="row-label">Groupe</span>
        <input type="text" name="ingredients[{{ $key }}][group]" value="{{ $row['group'] ?? '' }}" maxlength="60" placeholder="Groupe">
    </label>
    <label class="check ing-optional" title="Ingrédient facultatif">
        <input type="checkbox" name="ingredients[{{ $key }}][optional]" value="1" @checked(! empty($row['optional']))>
        <span>fac.</span>
    </label>
    <button type="button" class="btn btn-icon ing-remove" data-remove-row aria-label="Retirer cet ingrédient" title="Retirer">✕</button>
    @if (! empty($row['problem']))
        {{-- v0.17.0 : chaque correction se fait dans une fenêtre, sans quitter la recette ; la ligne passe au vert --}}
        <div class="row-warning" data-row-warning>
            <span class="row-warning-text">
                <strong>À vérifier</strong>
                @if (($row['label'] ?? '') !== '' && ($row['label'] ?? '') !== ($row['name'] ?? '')) · lu « {{ $row['label'] }} » @endif
                · {{ $row['problem'] }}
            </span>
            <button type="button" class="btn btn-small fix-btn" data-fix
                    data-kind="{{ $row['kind'] ?? 'other' }}"
                    data-label="{{ $row['label'] ?? $row['name'] ?? '' }}"
                    data-raw="{{ $row['raw'] ?? '' }}"
                    data-problem="{{ $row['problem'] }}"
                    data-candidates='@json($row['candidates'] ?? [])'
                    data-ask='@json($row['ask'] ?? null)'>{{ match ($row['kind'] ?? 'other') {
                        'unknown' => 'Choisir ou créer l\'ingrédient',
                        'approx' => 'Confirmer l\'ingrédient',
                        'ask' => 'Indiquer l\'équivalence',
                        default => 'Corriger',
                    } }}</button>
        </div>
    @endif
    @if (! empty($row['info']) && empty($row['problem']))
        <p class="row-info" data-row-info>≈ <span data-info-text>{{ $row['info'] }}</span>@if (! empty($row['info_slug']) && Str::startsWith($row['unit'] ?? '', 'u:')) · <button type="button" class="link-btn" data-fix data-kind="typical" data-word="{{ Str::after($row['unit'], 'u:') }}">corriger</button>@endif</p>
    @endif
</div>
