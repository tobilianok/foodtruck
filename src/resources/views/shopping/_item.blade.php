@php
    $Price = \App\Models\Price::class;
    $buy = $item->purchaseLabel();
    $need = $item->neededLabel();
    $leftover = $item->surplusLabel();
    $inSeason = $item->ingredient?->isInSeason($season);
    $saving = $item->possibleSaving();
    $by = $item->is_checked ? $item->checker?->firstName() : null;
@endphp
<li @class(['sl-item', 'is-checked' => $item->is_checked]) id="article-{{ $item->id }}" data-item="{{ $item->id }}" data-store="{{ $item->store_id }}">
    <form method="post" action="{{ route('shopping.items.check', $item) }}" class="sl-check" data-check-form>
        @csrf
        <input type="hidden" name="checked" value="{{ $item->is_checked ? 0 : 1 }}">
        <button type="submit" class="sl-box" aria-pressed="{{ $item->is_checked ? 'true' : 'false' }}" aria-label="Cocher : {{ $item->label }}">
            <span aria-hidden="true">✓</span>
        </button>
    </form>

    <div class="sl-main">
        <span class="sl-label">{{ $item->label }}@if ($item->quantity_text) <span class="sl-qty">{{ $item->quantity_text }}</span>@endif</span>
        @if ($buy)
            <span class="sl-buy">{{ $buy }}</span>
        @endif
        <span class="sl-meta">
            @if ($need) besoin : {{ $need }}@if ($leftover) · il en restera {{ $leftover }}@endif @endif
            @if ($item->uses)
                {{ $need ? '·' : '' }} pour {{ collect($item->uses)->pluck('title')->implode(', ') }}
            @endif
            @if ($item->note) <span class="sl-warn">{{ $item->note }}</span> @endif
            @if ($inSeason === false) <span class="badge-off">hors saison</span> @endif
            <span class="sl-by" data-by>{{ $by ? '✓ '.$by : '' }}</span>
        </span>
    </div>

    <div class="sl-side">
        @if ($item->isToCheck())
            <span class="sl-price muted">≈ {{ $item->estimated_cents !== null ? $Price::formatCents($item->estimated_cents) : '' }}</span>
        @elseif ($item->hasPrice())
            <span class="sl-price">{{ $Price::formatCents($item->estimated_cents) }}</span>
        @else
            <span class="sl-price muted" title="Aucun prix connu dans ce magasin : renseigne-le dans Ingrédients ou ajoute un ticket">prix inconnu</span>
        @endif
        @if ($saving >= 20 && ! $item->isToCheck())
            <span class="sl-saving" title="Prix le moins cher connu">−{{ $Price::formatCents($saving) }} chez {{ $item->bestStore?->name }}</span>
        @endif

        <details class="sl-more">
            <summary aria-label="Options pour {{ $item->label }}">⋯</summary>
            <div class="sl-menu">
                @if ($item->ingredient_id || $item->isManual())
                    <form method="post" action="{{ route('shopping.items.update', $item) }}" class="sl-move">
                        @csrf @method('put')
                        <input type="hidden" name="action" value="store">
                        <label>
                            <span class="small muted">Acheter chez</span>
                            <select name="store_id">
                                @foreach ($stores as $store)
                                    <option value="{{ $store->id }}" @selected($store->id === $item->store_id)>
                                        {{ $store->name }}@if ($store->id === $item->best_store_id && $item->best_cents !== null) · {{ $Price::formatCents($item->best_cents) }} (le moins cher)@endif
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <button type="submit" class="btn btn-small">Déplacer</button>
                    </form>
                @endif
                @if ($item->source === 'recette')
                    <form method="post" action="{{ route('shopping.items.update', $item) }}">
                        @csrf @method('put')
                        <input type="hidden" name="action" value="section">
                        <button type="submit" class="btn btn-small btn-ghost">
                            {{ $item->isToCheck() ? 'Il m\'en manque : l\'ajouter aux courses' : 'Déjà à la maison' }}
                        </button>
                    </form>
                @endif
                @if ($item->isManual())
                    <form method="post" action="{{ route('shopping.items.destroy', $item) }}">
                        @csrf @method('delete')
                        <button type="submit" class="btn btn-small btn-ghost">Retirer de la liste</button>
                    </form>
                @endif
                @if ($item->uses)
                    <ul class="sl-uses small muted">
                        @foreach ($item->uses as $use)
                            <li>{{ $use['title'] }} : {{ $use['quantity'] }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </details>
    </div>
</li>
