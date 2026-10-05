<div class="member-row" data-member-row>
    @include('household._member-fields', ['prefix' => 'members['.$key.']', 'member' => $member])
    <label class="check">
        <input type="radio" name="me" value="{{ $key }}" @checked((string) $me === (string) $key)>
        <span>C'est moi</span>
    </label>
    <button type="button" class="btn btn-icon" data-remove-member aria-label="Retirer cette personne">✕</button>
</div>
