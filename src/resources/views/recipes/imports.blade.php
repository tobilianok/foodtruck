@extends('layouts.app')

@section('title', 'Fiches Paperless')

@section('content')
    <x-page-header title="Fiches Paperless" lead="Les recettes scannées dans Paperless sont lues automatiquement. Celles qui sont entièrement reconnues sont publiées toutes seules ; les autres t'attendent ici.">
        @if ($household->hasPaperless())
            <form method="post" action="{{ route('recipes.imports.sync') }}">
                @csrf
                <button type="submit" class="btn">Chercher dans Paperless</button>
            </form>
        @endif
    </x-page-header>

    @if ($household->hasPaperless())
        <p class="hint small">
            Étiquette « {{ $household->paperlessRecipeTag() }} »,
            @if ($household->paperless_synced_at)
                dernière synchronisation le {{ $household->paperless_synced_at->timezone('Europe/Paris')->format('d/m à H:i') }} (automatique toutes les heures).
            @else
                jamais synchronisé.
            @endif
        </p>
    @else
        <div class="alert alert-info">
            Paperless n'est pas encore relié.
            <a href="{{ route('household.show') }}#avance">Le configurer dans « Mon foyer »</a>.
        </div>
    @endif

    <section class="panel">
        <h2>À relire <span class="muted">({{ $pending->count() }})</span></h2>
        @if ($pending->isEmpty())
            <p class="hint">Rien à relire. Dépose une fiche dans Paperless avec l'étiquette « {{ $household->paperlessRecipeTag() }} » : elle apparaîtra ici ou directement dans les recettes.</p>
        @else
            <ul class="receipt-list">
                @foreach ($pending as $import)
                    @include('recipes._import-row', ['import' => $import])
                @endforeach
            </ul>
        @endif
    </section>

    @if ($done->isNotEmpty())
        <section class="panel">
            <h2>Déjà transformées en recettes <span class="muted">({{ $done->count() }})</span></h2>
            <ul class="receipt-list">
                @foreach ($done as $import)
                    @include('recipes._import-row', ['import' => $import])
                @endforeach
            </ul>
        </section>
    @endif

    @if ($ignored->isNotEmpty())
        <details class="panel">
            <summary class="more-summary">Fiches mises de côté ({{ $ignored->count() }})</summary>
            <ul class="receipt-list">
                @foreach ($ignored as $import)
                    <li>
                        <span class="receipt-link">
                            <span class="receipt-main">
                                <strong>{{ $import->title ?? 'Document n° '.$import->paperless_document_id }}</strong>
                                <span class="muted">Paperless n° {{ $import->paperless_document_id }}</span>
                            </span>
                            <span class="receipt-side">
                                <form method="post" action="{{ route('recipes.imports.restore', $import) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-ghost btn-small">Reprendre</button>
                                </form>
                            </span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
@endsection
