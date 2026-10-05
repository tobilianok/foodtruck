@php
    /*
     * Champs d'une personne du foyer (Mon foyer et assistant).
     * $prefix : '' ou « members[3] » ; $member : tableau ou modèle (name, birth_date, portion_coefficient / coefficient, coefficient_manual).
     */
    $field = fn (string $name) => $prefix === '' ? $name : $prefix.'['.$name.']';
    $get = fn (string $key, $default = null) => is_array($member) ? ($member[$key] ?? $default) : ($member->{$key} ?? $default);
    $birth = $get('birth_date');
    $birth = $birth instanceof \Carbon\CarbonInterface ? $birth->toDateString() : $birth;
    $coefficient = $get('coefficient', $get('portion_coefficient', 1));
    $manual = (bool) $get('coefficient_manual', false);
@endphp
<label class="field">
    <span>Prénom</span>
    <input type="text" name="{{ $field('name') }}" value="{{ $get('name', '') }}" maxlength="60" required>
</label>
<label class="field">
    <span>Date de naissance</span>
    <input type="date" name="{{ $field('birth_date') }}" value="{{ $birth }}" min="1900-01-01" max="{{ now('Europe/Paris')->toDateString() }}" data-birth>
</label>
<label class="field field-small">
    <span>Coefficient</span>
    <input type="number" name="{{ $field('coefficient') }}" value="{{ $coefficient }}" min="0" max="2" step="0.05" data-coefficient @if ($birth && ! $manual) readonly @endif>
</label>
<label class="check check-manual" @unless ($birth) hidden @endunless data-manual-wrap>
    <input type="checkbox" name="{{ $field('coefficient_manual') }}" value="1" data-manual @checked($manual)>
    <span>Régler à la main</span>
</label>
@if (! is_array($member) && $member->birth_date)
    <small class="muted member-age">{{ $member->ageLabel() }} aujourd'hui
        · {{ $member->isAutomatic() ? 'coefficient automatique' : 'coefficient réglé à la main' }}{{ $member->ageNote() ? ' · '.$member->ageNote() : '' }}</small>
@endif
