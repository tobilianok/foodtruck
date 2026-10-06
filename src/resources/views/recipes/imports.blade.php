@extends('layouts.app')

@section('title', 'Fiches Paperless')

@section('content')
    @if ($reading > 0)
        {{-- v0.18.0 : fiches en cours de lecture, avancement suivi toutes les 3 secondes ; la page se recharge quand une fiche est prête --}}
        <script id="read-progress-url" type="application/json">@json(route('recipes.imports.progress'))</script>
    @endif
    <x-page-header title="Fiches Paperless" lead="Les recettes scannées dans Paperless sont lues automatiquement, une à la fois, puis t'attendent ici pour une relecture et une validation.">
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
        <div class="panel-head">
            <h2>À relire <span class="muted">({{ $pending->count() }})</span></h2>
            @if ($pending->count() > 1)
                <form method="post" action="{{ route('recipes.imports.discard-all') }}">
                    @csrf
                    <button type="submit" class="btn btn-ghost btn-small btn-danger" data-confirm="Supprimer les {{ $pending->count() }} fiches à relire ? « Chercher dans Paperless » les relira depuis le début tant que leurs documents portent l'étiquette.">Tout supprimer</button>
                </form>
            @endif
        </div>
        @if ($pending->isEmpty())
            <p class="hint">Rien à relire. Dépose une fiche dans Paperless avec l'étiquette « {{ $household->paperlessRecipeTag() }} » : elle apparaîtra ici ou directement dans les recettes.</p>
        @else
            <ul class="receipt-list">
                @foreach ($pending as $import)
                    @include('recipes._import-row', ['import' => $import, 'discard' => true])
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

@endsection
