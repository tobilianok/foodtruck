@extends('layouts.app')

@section('title', 'Envoyer le ticket à l\'IA')

@section('content')
    {{-- v0.19.0 : page de contrôle avant l'envoi d'un ticket à Ollama. Rien n'est envoyé tant que Louis n'a pas cliqué « Envoyer à l'IA ». --}}
    <x-page-header title="Envoyer le ticket à l'IA pour analyse" lead="Voici exactement ce qui partira vers Ollama. Rien n'est envoyé tant que tu n'as pas confirmé.">
        <a href="{{ route('receipts.index') }}" class="btn btn-ghost">Retour aux tickets</a>
    </x-page-header>

    <section class="panel">
        <h2>{{ $receipt->title ?: ($receipt->correspondent ?: 'Document n° '.$receipt->paperless_document_id) }}</h2>
        <dl class="ai-facts">
            <dt>Ticket</dt><dd>Paperless n° {{ $receipt->paperless_document_id }}@if ($receipt->correspondent) · {{ $receipt->correspondent }}@endif</dd>
            <dt>Destination</dt><dd><code>{{ $destination }}</code> (Ollama sur ton PC)</dd>
            <dt>Modèle</dt><dd><code>{{ $model }}</code></dd>
            <dt>Envoyé</dt><dd>les images ci-dessous, la consigne et le format de réponse. Rien d'autre : ni le texte de Paperless, ni tes prix, ni ton compte.</dd>
        </dl>
        @if ($receipt->vision_status === 'echec' && $receipt->vision_error)
            <div class="alert alert-error">Dernier envoi : {{ $receipt->vision_error }}</div>
        @elseif ($receipt->vision_status === 'lu')
            <div class="alert alert-info">Ce ticket a déjà été lu par l'IA : le renvoyer remplace sa lecture (les libellés déjà validés restent reconnus).</div>
        @endif
    </section>

    @if ($error)
        <div class="alert alert-error">Aperçu impossible : {{ $error }}</div>
    @else
        <form method="post" action="{{ route('receipts.ai.send', $receipt) }}" class="stack">
            @csrf
            @error('ai')
                <div class="alert alert-error">{{ $message }}</div>
            @enderror
            <section class="panel">
                <h2>Images envoyées <span class="muted">({{ count($images) }})</span></h2>
                <p class="hint small">
                    @if (count($images) > 1)
                        Un ticket long est découpé en morceaux qui se suivent, coupés entre deux lignes : chaque morceau reste lisible jusqu'aux centimes.
                    @endif
                    Les pages A4 (bon de commande de drive) partent en {{ $dpi }} dpi (aperçu en 100 dpi).
                </p>
                <div class="ai-pages ai-pieces">
                    @foreach ($images as $i => $png)
                        <figure class="ai-page">
                            <span class="ai-page-label">{{ count($images) > 1 ? 'Morceau '.($i + 1) : 'Ticket' }}</span>
                            <img src="data:image/png;base64,{{ $png }}" alt="Image {{ $i + 1 }} du ticket" loading="lazy">
                        </figure>
                    @endforeach
                </div>
            </section>

            <section class="panel">
                <details>
                    <summary class="more-summary">Consigne envoyée au modèle</summary>
                    <pre class="raw-text">{{ $prompt }}</pre>
                </details>
                <details>
                    <summary class="more-summary">Format de réponse imposé (JSON)</summary>
                    <pre class="raw-text">{{ $schema }}</pre>
                </details>
                <p class="hint small">L'IA recopie le ticket ; c'est Foodtruck qui vérifie les calculs (quantité × prix, somme des lignes = total), rattache les pesées et les remises. Tu valides ensuite chaque ticket.</p>
            </section>

            <div class="row-actions">
                @if ($busy)
                    <button type="submit" class="btn" disabled>Envoyer à l'IA</button>
                    <p class="hint small">L'IA est déjà occupée avec {{ \App\Support\VisionQueue::describe($busy) }} : un document à la fois.</p>
                @else
                    <button type="submit" class="btn">Envoyer à l'IA</button>
                @endif
                <a href="{{ route('receipts.index') }}" class="btn btn-ghost">Ne rien envoyer</a>
            </div>
        </form>
    @endif

    <form method="post" action="{{ route('receipts.ignore', $receipt) }}" class="ticket-actions">
        @csrf
        <button type="submit" class="btn btn-small btn-ghost">Ignorer ce ticket (ce n'est pas un ticket de courses)</button>
    </form>
@endsection
