@php
    $source = $entry->isLeftover() ? $entry->source : $entry;
    $recipe = $source?->recipe;
    $parts = $entry->partsLabel($household);
@endphp
<article @class(['meal', 'meal-leftover' => $entry->isLeftover(), 'meal-out' => $entry->kind === 'hors_maison', 'meal-note' => $entry->kind === 'note'])>
    @if ($entry->kind === 'hors_maison')
        <span class="meal-title">🍽 Hors maison</span>
        @if ($entry->note) <span class="meal-meta">{{ $entry->note }}</span> @endif
    @elseif ($entry->kind === 'note')
        <span class="meal-title">📝 {{ $entry->note }}</span>
    @elseif ($recipe)
        <a class="meal-title" href="{{ route('recipes.show', [$recipe, ...$source->servingInput()]) }}">
            @if ($entry->isLeftover()) ↩ @endif{{ $recipe->title }}
        </a>
        <span class="meal-meta">
            @if ($entry->isLeftover())
                restes du {{ $source->date->locale('fr')->isoFormat('ddd') }} {{ mb_strtolower($source->slotLabel(true)) }}
            @endif
            @if ($parts) {{ $entry->isLeftover() ? '· ' : '' }}{{ $parts }} @endif
            @if (! $entry->isLeftover() && $entry->meals > 1) · {{ $entry->meals }} repas @endif
            @if ($entryCost) · {{ \App\Models\Price::formatCents($entryCost) }} @endif
        </span>
    @else
        <span class="meal-title muted">Recette supprimée</span>
    @endif
    <a class="meal-edit" href="{{ route('planning.edit', $entry) }}">{{ $entry->isLeftover() ? 'déplacer, congeler…' : 'modifier' }}</a>
</article>
