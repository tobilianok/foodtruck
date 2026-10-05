<div class="row ingredient-row @if (! empty($row['problem'])) has-problem @endif" data-row>
    <input type="hidden" name="ingredients[{{ $key }}][label]" value="{{ $row['label'] ?? '' }}">
    <label class="field ing-group">
        <span>Groupe</span>
        <input type="text" name="ingredients[{{ $key }}][group]" value="{{ $row['group'] ?? '' }}" maxlength="60" placeholder="—">
    </label>
    <label class="field ing-name">
        <span>Ingrédient</span>
        <input type="text" name="ingredients[{{ $key }}][name]" value="{{ $row['name'] ?? '' }}" list="ingredient-names" maxlength="80" autocomplete="off" placeholder="Commence à taper…">
    </label>
    <label class="field ing-qty">
        <span>Quantité</span>
        <input type="text" name="ingredients[{{ $key }}][quantity]" value="{{ $row['quantity'] ?? '' }}" inputmode="decimal" maxlength="12">
    </label>
    <label class="field ing-unit">
        <span>Unité</span>
        <select name="ingredients[{{ $key }}][unit]">
            <option value="">selon goût</option>
            @foreach (\App\Support\Units::UNITS as $code => [$label])
                <option value="{{ $code }}" @selected(($row['unit'] ?? '') === $code)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="field ing-note">
        <span>Précision</span>
        <input type="text" name="ingredients[{{ $key }}][note]" value="{{ $row['note'] ?? '' }}" maxlength="120" placeholder="émincé, bien mûr…">
    </label>
    <label class="check ing-optional">
        <input type="checkbox" name="ingredients[{{ $key }}][optional]" value="1" @checked(! empty($row['optional']))>
        <span>facultatif</span>
    </label>
    <button type="button" class="btn btn-icon" data-remove-row aria-label="Retirer cet ingrédient">✕</button>
    @if (! empty($row['problem']))
        <p class="row-warning">
            « {{ $row['label'] ?? $row['name'] }} » : {{ $row['problem'] }}
            @if (! empty($row['candidates']))
                Proches : {{ implode(', ', $row['candidates']) }}.
            @endif
            <a href="{{ route('ingredients.create', ['nom' => $row['label'] ?? $row['name']]) }}" target="_blank" rel="noopener">Créer cet ingrédient ↗</a>
        </p>
    @endif
</div>
