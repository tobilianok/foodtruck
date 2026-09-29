@extends('layouts.app')

@section('title', 'Tickets de caisse')

@section('content')
    <section class="hero hero-compact">
        <div class="hero-row">
            <div>
                <h1>Tickets de caisse</h1>
                <p class="lead">Les prix réellement payés, lus sur vos tickets. Chaque libellé validé est mémorisé : les tickets suivants sont traités tout seuls.</p>
            </div>
            <div class="hero-actions">
                @if ($household->hasPaperless())
                    <form method="post" action="{{ route('receipts.sync') }}">
                        @csrf
                        <button type="submit" class="btn">Synchroniser Paperless</button>
                    </form>
                @endif
            </div>
        </div>
        @if ($household->hasPaperless())
            <p class="hint small">
                Paperless : étiquette « {{ $household->paperlessTag() }} »,
                @if ($household->paperless_synced_at)
                    dernière synchronisation le {{ $household->paperless_synced_at->timezone('Europe/Paris')->format('d/m à H:i') }} (automatique toutes les heures).
                @else
                    jamais synchronisé.
                @endif
            </p>
            @if ($household->paperless_last_error)
                <div class="alert alert-error">Dernière synchronisation en échec : {{ $household->paperless_last_error }}</div>
            @endif
        @else
            <div class="alert alert-info">
                Paperless n'est pas encore relié.
                @if ($canManage)
                    <a href="{{ route('household.show') }}#paperless">Le configurer dans « Mon foyer »</a>.
                @else
                    Un administrateur du foyer peut le configurer dans « Mon foyer ».
                @endif
                En attendant, tu peux coller le texte d'un ticket ci-dessous.
            </div>
        @endif
    </section>

    <nav class="tabs" aria-label="Filtrer les tickets">
        <a href="{{ route('receipts.index') }}" @class(['is-active' => ! $status])>Tous</a>
        @foreach (\App\Models\Receipt::STATUSES as $value => $label)
            <a href="{{ route('receipts.index', ['statut' => $value]) }}" @class(['is-active' => $status === $value])>
                {{ ucfirst($label) }} ({{ $counts[$value] ?? 0 }})
            </a>
        @endforeach
    </nav>

    @if ($receipts->isEmpty())
        <div class="panel"><p class="hint">Aucun ticket pour l'instant.</p></div>
    @else
        <div class="panel">
            <ul class="receipt-list">
                @foreach ($receipts as $receipt)
                    @php
                        $products = $receipt->lines->where('kind', 'produit');
                        $todo = $products->whereIn('status', ['a_associer', 'propose'])->count();
                    @endphp
                    <li>
                        <a href="{{ route('receipts.show', $receipt) }}" class="receipt-link">
                            <span class="receipt-main">
                                <strong>{{ $receipt->store?->name ?? $receipt->correspondent ?? 'Magasin à préciser' }}</strong>
                                <span class="muted">{{ $receipt->purchased_on?->format('d/m/Y') ?? 'date inconnue' }} · {{ $products->count() }} article{{ $products->count() > 1 ? 's' : '' }}</span>
                            </span>
                            <span class="receipt-side">
                                @if ($receipt->total_cents) <strong>{{ \App\Models\Price::formatCents($receipt->total_cents) }}</strong> @endif
                                <span @class([
                                    'badge-warn' => $receipt->status === 'a_valider',
                                    'badge-season' => $receipt->status === 'traite',
                                    'badge-off' => $receipt->status === 'ignore',
                                ])>
                                    {{ $receipt->statusLabel() }}@if ($receipt->status === 'a_valider' && $todo) · {{ $todo }} ligne{{ $todo > 1 ? 's' : '' }}@endif
                                </span>
                                @if ($receipt->auto_applied) <span class="tag" title="Toutes les lignes étaient déjà connues">auto</span> @endif
                                <span class="muted small">{{ \App\Models\Receipt::SOURCES[$receipt->source] ?? $receipt->source }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
            {{ $receipts->links('partials.pagination') }}
        </div>
    @endif

    <details class="panel add-block" @if ($errors->has('raw_text') || $errors->has('store_id') || $errors->has('purchased_on')) open @endif>
        <summary>+ Coller un ticket (hors Paperless)</summary>
        <p class="hint">Pour un ticket dématérialisé : ouvre-le, sélectionne tout le texte (Ctrl+A), copie (Ctrl+C) et colle-le ici.</p>
        <form method="post" action="{{ route('receipts.store') }}" class="stack">
            @csrf
            <div class="grid-2">
                <label class="field">
                    <span>Magasin</span>
                    <select name="store_id" required>
                        @foreach ($stores as $item)
                            <option value="{{ $item->id }}" @selected((int) old('store_id', $household->main_store_id) === $item->id)>{{ $item->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">
                    <span>Date d'achat</span>
                    <input type="date" name="purchased_on" value="{{ old('purchased_on', now('Europe/Paris')->toDateString()) }}" max="{{ now('Europe/Paris')->toDateString() }}" required>
                </label>
            </div>
            <label class="field">
                <span>Texte du ticket</span>
                <textarea name="raw_text" rows="10" class="mono" required>{{ old('raw_text') }}</textarea>
            </label>
            <div class="actions"><button type="submit" class="btn">Lire le ticket</button></div>
        </form>
    </details>
@endsection
