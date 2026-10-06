@php
    $rows = $import->parsed['rows'] ?? [];
    $problems = collect($rows)->whereNotNull('problem')->count();
    $recipe = $import->recipe;
    $ready = \App\Support\RecipeScan\ScanImporter::isReady($import);
    // v0.18.1 : envoi à l'IA décidé fiche par fiche
    $toSend = $import->canBeSent() && ! \App\Support\RecipeScan\ScanImporter::readByVision($import);
    $failed = $import->layout_status === \App\Models\RecipeImport::LAYOUT_FAILED;
@endphp
<li @class(['import-item' => ! empty($discard)])>
    <a href="{{ route('recipes.imports.show', $import) }}" class="receipt-link">
        <span class="receipt-main">
            <strong>{{ $recipe?->title ?? ($import->parsed['recipe']['title'] ?? $import->title ?? 'Document n° '.$import->paperless_document_id) }}</strong>
            <span class="muted">
                Paperless n° {{ $import->paperless_document_id }}
                @if ($recipe)
                    · recette {{ $recipe->isPublished() ? ($import->auto_published ? 'publiée automatiquement' : 'publiée') : 'en brouillon' }}
                @elseif ($import->isReading())
                    · {{ $import->layout_progress !== null ? 'analyse en cours' : 'envoyée, en file' }}
                @elseif ($toSend && $failed)
                    · analyse par l'IA impossible
                @elseif ($toSend)
                    · pas encore envoyée à l'IA
                @elseif ($problems)
                    · {{ $problems }} ingrédient{{ $problems > 1 ? 's' : '' }} à compléter
                @elseif ($ready)
                    · tout est reconnu, il ne reste qu'à valider
                @endif
            </span>
            @if ($import->isReading())
                {{-- v0.18.0 : avancement de la lecture, mis à jour toutes les 3 secondes --}}
                <span class="read-progress" data-read-progress="{{ $import->id }}">
                    <span class="read-progress-bar"><span class="read-progress-fill" style="width: {{ (int) ($import->layout_progress ?? 0) }}%"></span></span>
                    <span class="read-progress-text small muted"><span data-read-step>{{ $import->layout_step ?? \App\Http\Controllers\RecipeImportController::waiting($import) }}</span><span data-read-percent>{{ $import->layout_progress !== null ? ' · '.$import->layout_progress.' %' : '' }}</span><span data-read-since></span></span>
                </span>
            @endif
            @if (! empty($import->issues) && ! $import->isReading() && (! $recipe || ! $recipe->isPublished()))
                <span class="muted small">{{ implode(' ', $import->issues) }}</span>
            @endif
        </span>
        <span class="receipt-side">
            @if ($import->isReading())
                <span class="badge-reading">analyse en cours…</span>
            @elseif ($toSend && $failed)
                <span class="badge-warn">échec de l'analyse</span>
            @elseif ($toSend)
                <span class="badge-reading">à envoyer à l'IA</span>
            @elseif ($recipe?->isPublished())
                <span class="badge-season">publiée</span>
            @elseif ($recipe)
                <span class="badge-warn">brouillon</span>
            @elseif ($ready)
                <span class="badge-season">à valider</span>
            @else
                <span class="badge-warn">à compléter</span>
            @endif
        </span>
    </a>
    @if ($toSend || ($import->isReading() && $import->layout_progress === null))
        <div class="import-actions">
            @if ($toSend)
                @if (! empty($busy) && $busy->id !== $import->id)
                    <span class="btn btn-small" aria-disabled="true" title="Une autre fiche est en cours d'analyse : une fiche à la fois.">{{ $failed ? 'Renvoyer à l\'IA' : 'Envoyer à l\'IA pour analyse' }}</span>
                @else
                    <a href="{{ route('recipes.imports.ai', $import) }}" class="btn btn-small">{{ $failed ? 'Renvoyer à l\'IA' : 'Envoyer à l\'IA pour analyse' }}</a>
                @endif
            @else
                <form method="post" action="{{ route('recipes.imports.ai.cancel', $import) }}">
                    @csrf
                    <button type="submit" class="btn btn-ghost btn-small">Annuler l'envoi</button>
                </form>
            @endif
        </div>
    @endif
    @if (! empty($discard))
        <form method="post" action="{{ route('recipes.imports.ignore', $import) }}" class="import-discard">
            @csrf
            <button type="submit" class="btn btn-ghost btn-small btn-danger" data-confirm="Supprimer cette fiche ? « Chercher dans Paperless » la relira depuis le début tant que le document porte l'étiquette{{ $recipe ? ' ; son brouillon de recette sera supprimé' : '' }}.">Supprimer</button>
        </form>
    @endif
</li>
