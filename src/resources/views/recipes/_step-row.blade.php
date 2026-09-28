<div class="row step-row" data-row>
    <span class="step-number" aria-hidden="true"></span>
    <label class="field step-body">
        <span class="sr-only">Étape</span>
        <textarea name="steps[{{ $key }}][body]" rows="3" maxlength="2000" placeholder="Décris l'étape…">{{ $row['body'] ?? '' }}</textarea>
    </label>
    <div class="step-options">
        <label class="field">
            <span>Minuteur (min)</span>
            <input type="number" name="steps[{{ $key }}][timer]" value="{{ $row['timer'] ?? '' }}" min="1">
        </label>
        <label class="field">
            <span>Appareil</span>
            <select name="steps[{{ $key }}][equipment_id]">
                <option value="">—</option>
                @foreach ($equipment as $item)
                    <option value="{{ $item->id }}" @selected((int) ($row['equipment_id'] ?? 0) === $item->id)>{{ $item->name }}</option>
                @endforeach
            </select>
        </label>
        <button type="button" class="btn btn-icon" data-remove-row aria-label="Retirer cette étape">✕</button>
    </div>
</div>
