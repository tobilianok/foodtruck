<label class="field">
    <span>Magasin principal</span>
    <select name="main_store_id">
        <option value="">Non défini</option>
        @foreach ($stores as $store)
            <option value="{{ $store->id }}" @selected((int) $main === $store->id)>{{ $store->name }}</option>
        @endforeach
    </select>
    <small>Là où vous faites le gros des courses.</small>
</label>
<label class="field">
    <span>Fruits et légumes</span>
    <select name="produce_store_id">
        <option value="">Comme le magasin principal</option>
        @foreach ($stores as $store)
            <option value="{{ $store->id }}" @selected((int) $produce === $store->id)>{{ $store->name }}</option>
        @endforeach
    </select>
    <small>Primeur ou marché habituel.</small>
</label>
