<div class="member-row" data-member-row>
    <label class="field">
        <span>Prénom</span>
        <input type="text" name="members[{{ $key }}][name]" value="{{ $member['name'] ?? '' }}" maxlength="60" required>
    </label>
    <label class="field">
        <span>Catégorie</span>
        <select name="members[{{ $key }}][category]" data-category>
            @foreach (\App\Models\HouseholdMember::CATEGORIES as $value => $category)
                <option value="{{ $value }}" @selected(($member['category'] ?? 'adulte') === $value)>{{ $category['label'] }}</option>
            @endforeach
        </select>
    </label>
    <label class="field field-small">
        <span>Coefficient</span>
        <input type="number" name="members[{{ $key }}][coefficient]" value="{{ $member['coefficient'] ?? 1 }}" min="0" max="2" step="0.05" required data-coefficient>
    </label>
    <label class="check">
        <input type="radio" name="me" value="{{ $key }}" @checked((string) $me === (string) $key)>
        <span>C'est moi</span>
    </label>
    <button type="button" class="btn btn-icon" data-remove-member aria-label="Retirer cette personne">✕</button>
</div>
