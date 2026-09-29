@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Pages">
        @if ($paginator->onFirstPage())
            <span class="muted">← Précédents</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}">← Précédents</a>
        @endif
        <span class="muted">Page {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}">Suivants →</a>
        @else
            <span class="muted">Suivants →</span>
        @endif
    </nav>
@endif
