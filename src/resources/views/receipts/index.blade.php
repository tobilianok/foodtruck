@extends('layouts.app')

@section('title', 'Tickets de caisse')

@section('content')
    @if ($reading)
        <script id="read-progress-url" type="application/json">@json(route('receipts.progress'))</script>
    @endif
    <x-page-header title="Tickets de caisse" lead="Les prix réellement payés : chaque ticket est lu par l'IA de ton PC, puis validé par toi. Les libellés validés sont mémorisés et reconnus sur les tickets suivants.">
        @if ($household->hasPaperless())
            <form method="post" action="{{ route('receipts.sync') }}">
                @csrf
                <button type="submit" class="btn">Synchroniser Paperless</button>
            </form>
        @endif
    </x-page-header>
    <section class="hero hero-compact" style="padding-top:0">
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
                    <a href="{{ route('household.show') }}#avance">Le configurer dans « Mon foyer »</a>.
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
                        $todo = $products->filter(fn ($l) => \App\Http\Controllers\ReceiptController::problem($l) !== null)->count();
                        // v0.19.0 : envoi à l'IA décidé ticket par ticket
                        $isReading = $receipt->isReading();
                        $toSend = ! $isReading && $receipt->awaitsVision();
                        $failed = ! $isReading && $receipt->vision_status === 'echec' && $receipt->canBeSent();
                    @endphp
                    <li class="import-item">
                        <a href="{{ route('receipts.show', $receipt) }}" class="receipt-link">
                            <span class="receipt-main">
                                <strong>{{ $receipt->store?->name ?? $receipt->correspondent ?? ($receipt->title ?: 'Magasin à préciser') }}</strong>
                                <span class="muted">
                                    {{ $receipt->purchased_on?->format('d/m/Y') ?? 'date inconnue' }}
                                    @if ($receipt->paperless_document_id) · Paperless n° {{ $receipt->paperless_document_id }} @endif
                                    @if ($isReading)
                                        · {{ $receipt->vision_progress !== null ? 'analyse en cours' : 'envoyé, en file' }}
                                    @elseif ($toSend)
                                        · pas encore envoyé à l'IA
                                    @elseif ($failed && $products->isEmpty())
                                        · analyse par l'IA impossible
                                    @else
                                        · {{ $products->count() }} article{{ $products->count() > 1 ? 's' : '' }}
                                    @endif
                                </span>
                                @if ($isReading)
                                    <span class="read-progress" data-read-progress="{{ $receipt->id }}">
                                        <span class="read-progress-bar"><span class="read-progress-fill" style="width: {{ (int) ($receipt->vision_progress ?? 0) }}%"></span></span>
                                        <span class="read-progress-text small muted"><span data-read-step>{{ $receipt->vision_step ?? 'Envoyé : l\'analyse démarre dans moins d\'une minute' }}</span><span data-read-percent>{{ $receipt->vision_progress !== null ? ' · '.$receipt->vision_progress.' %' : '' }}</span><span data-read-since></span></span>
                                    </span>
                                @elseif ($failed && $receipt->vision_error)
                                    <span class="muted small">{{ $receipt->vision_error }}</span>
                                @endif
                            </span>
                            <span class="receipt-side">
                                @if ($receipt->total_cents) <strong>{{ \App\Models\Price::formatCents($receipt->total_cents) }}</strong> @endif
                                @if ($isReading)
                                    <span class="badge-reading">analyse en cours…</span>
                                @elseif ($toSend)
                                    <span class="badge-reading">à envoyer à l'IA</span>
                                @elseif ($failed)
                                    <span class="badge-warn">échec de l'analyse</span>
                                @else
                                    <span @class([
                                        'badge-warn' => $receipt->status === 'a_valider',
                                        'badge-season' => $receipt->status === 'traite',
                                        'badge-off' => $receipt->status === 'ignore',
                                    ])>
                                        {{ $receipt->statusLabel() }}@if ($receipt->status === 'a_valider' && $todo) · {{ $todo }} ligne{{ $todo > 1 ? 's' : '' }} à vérifier @endif
                                    </span>
                                @endif
                                <span class="muted small">{{ \App\Models\Receipt::SOURCES[$receipt->source] ?? $receipt->source }}</span>
                            </span>
                        </a>
                        @if ($toSend || $failed || ($isReading && $receipt->vision_progress === null))
                            <div class="import-actions">
                                @if ($isReading)
                                    <form method="post" action="{{ route('receipts.ai.cancel', $receipt) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-ghost btn-small">Annuler l'envoi</button>
                                    </form>
                                @elseif ($busy && ! $busy->is($receipt))
                                    <span class="btn btn-small" aria-disabled="true" title="L'IA est déjà occupée : un document à la fois.">{{ $failed ? 'Renvoyer à l\'IA' : 'Envoyer à l\'IA pour analyse' }}</span>
                                @else
                                    <a href="{{ route('receipts.ai', $receipt) }}" class="btn btn-small">{{ $failed ? 'Renvoyer à l\'IA' : 'Envoyer à l\'IA pour analyse' }}</a>
                                @endif
                            </div>
                        @endif
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
