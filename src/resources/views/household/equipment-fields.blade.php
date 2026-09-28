<div class="chips">
    @foreach ($equipment as $item)
        <label class="chip">
            <input type="checkbox" name="equipment[]" value="{{ $item->id }}" @checked(in_array($item->id, array_map('intval', $owned), true))>
            <span>{{ $item->name }}</span>
        </label>
    @endforeach
</div>
<label class="field">
    <span>Autres appareils</span>
    <input type="text" name="other_equipment" value="{{ old('other_equipment') }}" maxlength="300" placeholder="Ex. : Thermomix, déshydrateur (séparés par des virgules)">
    <small>Ils seront ajoutés à la liste commune.</small>
</label>
