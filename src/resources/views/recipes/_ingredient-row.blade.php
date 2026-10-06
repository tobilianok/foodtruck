{{-- Une ligne d'ingrédient : titres des colonnes affichés une seule fois au-dessus de la liste (sur ordinateur) --}}
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
        <select name="ingredients[{{ $key }}][unit]">
            <option value="">selon goût</option>
            @foreach (\App\Support\Units::UNITS as $code => [$label])
                <option value="{{ $code }}" @selected(($row['unit'] ?? '') === $code)>{{ $label }}</option>
            @endforeach
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
        <div class="row-warning" data-row-warning>
            <span class="row-warning-text">
                <strong>À vérifier</strong>
                @if (($row['label'] ?? '') !== '' && ($row['label'] ?? '') !== ($row['name'] ?? '')) · lu « {{ $row['label'] }} » @endif
                · {{ $row['problem'] }}
            </span>
            @if (! empty($row['candidates']))
                <span class="pick-list">
                    <span class="muted">C'est :</span>
                    @foreach ($row['candidates'] as $candidate)
                        <button type="button" class="pick" data-pick="{{ $candidate }}">{{ $candidate }}</button>
                    @endforeach
                </span>
            @endif
            @if (empty($row['known']))
                <a class="small" href="{{ route('ingredients.create', ['nom' => $row['label'] ?? $row['name']]) }}" target="_blank" rel="noopener">Créer cet ingrédient ↗</a>
            @endif
        </div>
    @endif
</div>
