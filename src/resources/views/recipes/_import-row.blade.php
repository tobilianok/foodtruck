@php
    $rows = $import->parsed['rows'] ?? [];
    $problems = collect($rows)->whereNotNull('problem')->count();
    $recipe = $import->recipe;
@endphp
<li @class(['import-item' => ! empty($discard)])>
    <a href="{{ route('recipes.imports.show', $import) }}" class="receipt-link">
        <span class="receipt-main">
            <strong>{{ $recipe?->title ?? ($import->parsed['recipe']['title'] ?? $import->title ?? 'Document n° '.$import->paperless_document_id) }}</strong>
            <span class="muted">
                Paperless n° {{ $import->paperless_document_id }}
                @if ($recipe)
                    · recette {{ $recipe->isPublished() ? ($import->auto_published ? 'publiée automatiquement' : 'publiée') : 'en brouillon' }}
                @elseif ($problems)
                    · {{ $problems }} ingrédient{{ $problems > 1 ? 's' : '' }} à compléter
                @endif
            </span>
            @if (! empty($import->issues) && (! $recipe || ! $recipe->isPublished()))
                <span class="muted small">{{ implode(' ', $import->issues) }}</span>
            @endif
        </span>
        <span class="receipt-side">
            @if ($recipe?->isPublished())
                <span class="badge-season">publiée</span>
            @elseif ($recipe)
                <span class="badge-warn">brouillon</span>
            @else
                <span class="badge-warn">à compléter</span>
            @endif
        </span>
    </a>
    @if (! empty($discard))
        <form method="post" action="{{ route('recipes.imports.ignore', $import) }}" class="import-discard">
            @csrf
            <button type="submit" class="btn btn-ghost btn-small btn-danger" data-confirm="Supprimer cette fiche ? « Chercher dans Paperless » la relira depuis le début tant que le document porte l'étiquette{{ $recipe ? ' ; son brouillon de recette sera supprimé' : '' }}.">Supprimer</button>
        </form>
    @endif
</li>
