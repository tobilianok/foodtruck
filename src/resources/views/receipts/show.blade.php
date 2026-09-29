@extends('layouts.app')

@section('title', 'Ticket · '.($receipt->store?->name ?? 'à préciser'))

@section('content')
    @php
        $products = $receipt->lines->where('kind', 'produit');
        $gap = $receipt->total_cents !== null ? $receipt->total_cents - $linesTotal : null;
    @endphp

    <section class="hero hero-compact">
        <p><a href="{{ route('receipts.index') }}">← Tickets</a></p>
        <h1>{{ $receipt->store?->name ?? $receipt->correspondent ?? 'Ticket' }} <span class="muted">{{ $receipt->purchased_on?->format('d/m/Y') }}</span></h1>
        <p class="lead">
            {{ $products->count() }} article{{ $products->count() > 1 ? 's' : '' }} lu{{ $products->count() > 1 ? 's' : '' }}
            · {{ \App\Models\Price::formatCents($linesTotal) }}
            @if ($receipt->total_cents !== null) sur un total de {{ \App\Models\Price::formatCents($receipt->total_cents) }} @endif
            · <span @class(['badge-warn' => $receipt->status === 'a_valider', 'badge-season' => $receipt->status === 'traite', 'badge-off' => $receipt->status === 'ignore'])>{{ $receipt->statusLabel() }}</span>
        </p>
        @if ($gap !== null && abs($gap) > 5)
            <div class="alert alert-info">
                Écart de {{ \App\Models\Price::formatCents(abs($gap)) }} entre les lignes lues et le total du ticket :
                {{ $gap > 0 ? 'certaines lignes n\'ont pas été lues' : 'une ligne a peut-être été lue en trop' }}.
                Vérifie le texte du ticket en bas de page.
            </div>
        @endif
        @if ($receipt->paperlessUrl())
            <p class="small"><a href="{{ $receipt->paperlessUrl() }}" target="_blank" rel="noopener">Voir le document dans Paperless ↗</a></p>
        @endif
    </section>

    <form method="post" action="{{ route('receipts.update', $receipt) }}" class="stack">
        @csrf @method('put')

        <section class="panel">
            <div class="grid-2">
                <label class="field">
                    <span>Magasin</span>
                    <select name="store_id" required>
                        <option value="">— à préciser —</option>
                        @foreach ($stores as $item)
                            <option value="{{ $item->id }}" @selected($receipt->store_id === $item->id)>{{ $item->name }}</option>
                        @endforeach
                    </select>
                    @if ($receipt->correspondent) <small>Correspondant Paperless : {{ $receipt->correspondent }}</small> @endif
                </label>
                <label class="field">
                    <span>Date d'achat</span>
                    <input type="date" name="purchased_on" value="{{ $receipt->purchased_on?->toDateString() }}" max="{{ now('Europe/Paris')->toDateString() }}" required>
                </label>
            </div>
        </section>

        <section class="panel">
            <h2>Articles</h2>
            <p class="hint">
                <span class="badge-season">reconnu</span> libellé déjà validé ·
                <span class="badge-cheap">proposé</span> à confirmer ·
                <span class="badge-warn">à associer</span> choisis l'ingrédient (tape son nom : « Ingrédient » seul suffit, le conditionnement est déduit du ticket).
            </p>
            <datalist id="pack-choices">
                @foreach ($choices as $choice)
                    <option value="{{ $choice }}"></option>
                @endforeach
            </datalist>

            <div class="receipt-lines">
                @foreach ($receipt->lines as $line)
                    @if ($line->kind !== 'produit')
                        <div class="receipt-line is-muted">
                            <div class="rl-label"><span class="mono">{{ $line->raw_label }}</span></div>
                            <div class="rl-price">{{ \App\Models\Price::formatCents($line->total_cents) }}</div>
                            <div class="rl-assoc muted small">remise globale, non rattachée</div>
                        </div>
                        @continue
                    @endif
                    @php
                        $current = $line->pack ? \App\Http\Controllers\ReceiptController::packChoiceLabel($line->pack) : '';
                        $action = $line->status === 'ignore' ? 'ignorer' : 'associer';
                    @endphp
                    <div @class(['receipt-line', 'is-muted' => $line->status === 'ignore'])>
                        <div class="rl-label">
                            <span class="mono">{{ $line->raw_label }}</span>
                            @if ($line->isWeighted())
                                <span class="muted small">{{ $line->quantityLabel() }} × {{ \App\Models\Price::formatCents($line->unit_price_cents) }}/kg</span>
                            @elseif ($line->quantity != 1)
                                <span class="muted small">{{ $line->quantityLabel() }} × {{ \App\Models\Price::formatCents($line->unit_price_cents) }}</span>
                            @endif
                        </div>
                        <div class="rl-price">
                            {{ \App\Models\Price::formatCents($line->total_cents) }}
                            @if ($line->discount_cents) <span class="badge-promo">−{{ \App\Models\Price::formatCents($line->discount_cents) }}</span> @endif
                        </div>
                        <div class="rl-assoc">
                            <input type="text" name="lines[{{ $line->id }}][choice]" value="{{ $current }}" list="pack-choices"
                                   placeholder="Ingrédient ou Ingrédient — conditionnement" autocomplete="off" aria-label="Association pour {{ $line->raw_label }}">
                            <div class="rl-meta">
                                @switch($line->status)
                                    @case('reconnu') <span class="badge-season">reconnu</span> @break
                                    @case('propose') <span class="badge-cheap">proposé</span> @break
                                    @case('a_associer') <span class="badge-warn">à associer</span> @break
                                    @case('applique') <span class="badge-season">prix enregistré</span> @break
                                    @case('ignore') <span class="badge-off">ignoré</span> @break
                                @endswitch
                                @if ($line->pack_price_cents && $line->pack)
                                    <span class="muted small">→ {{ \App\Models\Price::formatCents($line->pack_price_cents) }} le conditionnement « {{ $line->pack->label }} »</span>
                                @endif
                            </div>
                        </div>
                        <label class="rl-action">
                            <span class="sr-only">Action</span>
                            <select name="lines[{{ $line->id }}][action]">
                                <option value="associer" @selected($action === 'associer')>Associer</option>
                                <option value="ignorer" @selected($action === 'ignorer')>Ignorer cette fois</option>
                                <option value="ignorer_toujours">Toujours ignorer (non alimentaire)</option>
                            </select>
                        </label>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="panel sticky-bar">
            <label class="check">
                <input type="hidden" name="remember" value="0">
                <input type="checkbox" name="remember" value="1" checked>
                <span>Mémoriser les libellés pour les prochains tickets</span>
            </label>
            <button type="submit" class="btn">Enregistrer les prix</button>
        </div>
    </form>

    <div class="row-actions ticket-actions">
        @if ($receipt->status !== 'traite')
            <form method="post" action="{{ route('receipts.reparse', $receipt) }}">
                @csrf
                <button type="submit" class="btn btn-small btn-ghost">Relire le ticket</button>
            </form>
        @endif
        <form method="post" action="{{ route('receipts.ignore', $receipt) }}">
            @csrf
            <button type="submit" class="btn btn-small btn-ghost">{{ $receipt->status === 'ignore' ? 'Remettre à valider' : 'Ignorer ce ticket' }}</button>
        </form>
    </div>

    <details class="panel">
        <summary>Texte du ticket</summary>
        <pre class="mono raw-text">{{ $receipt->raw_text }}</pre>
    </details>
@endsection
