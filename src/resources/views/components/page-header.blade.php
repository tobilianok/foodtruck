@props(['title', 'lead' => null])
<header class="page-head">
    <div>
        <h1>{{ $title }}</h1>
        @if ($lead)
            <p class="page-lead">{{ $lead }}</p>
        @endif
    </div>
    @if (! $slot->isEmpty())
        <div class="page-actions">{{ $slot }}</div>
    @endif
</header>
