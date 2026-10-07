@extends('layouts.app')

@section('title', 'Envoyer à l\'IA')

@section('content')
    {{-- v0.18.0 : page de contrôle avant l'envoi d'une fiche à Ollama. Rien n'est envoyé tant que Louis n'a pas cliqué « Envoyer à l'IA ». --}}
    <x-page-header title="Envoyer la fiche à l'IA pour analyse" lead="Voici exactement ce qui partira vers Ollama. Rien n'est envoyé tant que tu n'as pas confirmé.">
        <a href="{{ route('recipes.imports.index') }}" class="btn btn-ghost">Retour aux fiches</a>
    </x-page-header>

    <section class="panel">
        <h2>{{ $import->title ?? 'Document n° '.$import->paperless_document_id }}</h2>
        <dl class="ai-facts">
            <dt>Fiche</dt><dd>Paperless n° {{ $import->paperless_document_id }}</dd>
            <dt>Destination</dt><dd><code>{{ $destination }}</code> (Ollama sur ton PC)</dd>
            <dt>Modèle</dt><dd><code>{{ $model }}</code></dd>
            <dt>Envoyé</dt><dd>les pages cochées ci-dessous en images ({{ $dpi }} dpi), la consigne et le format de réponse. Rien d'autre : ni le texte de Paperless, ni tes recettes, ni ton compte.</dd>
        </dl>
        @if ($import->layout_status === 'echec' && $import->layout_error)
            <div class="alert alert-error">Dernier envoi : {{ $import->layout_error }}</div>
        @endif
    </section>

    @if ($error)
        <div class="alert alert-error">Aperçu impossible : {{ $error }}</div>
    @else
        <form method="post" action="{{ route('recipes.imports.ai.send', $import) }}" class="stack">
            @csrf
            @error('pages')
                <div class="alert alert-error">{{ $message }}</div>
            @enderror
            <section class="panel">
                <h2>Pages à envoyer <span class="muted">({{ count($pages) }})</span></h2>
                <p class="hint small">Décoche une page inutile (photo du plat, pictogrammes) : l'analyse sera plus rapide. L'aperçu est en 100 dpi, l'envoi en {{ $dpi }} dpi.</p>
                <div class="ai-pages">
                    @foreach ($pages as $i => $png)
                        <label class="ai-page">
                            <input type="checkbox" name="pages[]" value="{{ $i }}" @checked($selected === [] || in_array($i, $selected, true))>
                            <span class="ai-page-label">Page {{ $i + 1 }}</span>
                            <img src="data:image/png;base64,{{ $png }}" alt="Page {{ $i + 1 }} de la fiche" loading="lazy">
                        </label>
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
            </section>

            <div class="row-actions">
                @if ($busy)
                    <button type="submit" class="btn" disabled>Envoyer à l'IA</button>
                    <p class="hint small">L'IA est déjà occupée avec {{ \App\Support\VisionQueue::describe($busy) }} : un document à la fois.</p>
                @else
                    <button type="submit" class="btn">Envoyer à l'IA</button>
                @endif
                <a href="{{ route('recipes.imports.index') }}" class="btn btn-ghost">Ne rien envoyer</a>
            </div>
        </form>
    @endif
@endsection
