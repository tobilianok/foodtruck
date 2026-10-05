@extends('layouts.app')

@section('title', 'Prix · '.$store->name)

@section('content')
    <section class="hero hero-compact">
        <h1>Mettre à jour les prix</h1>
        <p class="lead">Après tes courses : saisis uniquement les prix qui ont changé. Les champs vides sont ignorés, l'historique est conservé. Un prix « estimé » encore juste ? Retape-le pour le confirmer.</p>
        <p><a href="{{ route('ingredients.index') }}">Tous les ingrédients (modifier un nom, un rayon, une saison, un conditionnement) →</a></p>
    </section>

    <nav class="tabs" aria-label="Magasins">
        @foreach ($stores as $item)
            <a href="{{ route('prices.edit', ['store' => $item, 'rayon' => $rayon]) }}" @class(['is-active' => $item->is($store)])>{{ $item->name }}</a>
        @endforeach
    </nav>

    <form method="get" action="{{ route('prices.edit', $store) }}" class="inline-filter">
        <label class="field field-medium">
            <span>Rayon</span>
            <select name="rayon" onchange="this.form.submit()">
                <option value="">Tous les rayons</option>
                @foreach ($aisles as $aisle)
                    <option value="{{ $aisle->slug }}" @selected($rayon === $aisle->slug)>{{ $aisle->name }}</option>
                @endforeach
            </select>
        </label>
        @if ($all)
            <input type="hidden" name="tous" value="1">
        @endif
        <noscript><button type="submit" class="btn btn-small">Filtrer</button></noscript>
        <p class="small">
            @if ($all)
                Tous les produits sont affichés.
                <a href="{{ route('prices.edit', ['store' => $store, 'rayon' => $rayon]) }}">Seulement ceux déjà vendus ici</a>
            @else
                Seuls les produits déjà vendus chez {{ $store->name }} sont affichés.
                <a href="{{ route('prices.edit', ['store' => $store, 'rayon' => $rayon, 'tous' => 1]) }}">Ajouter un prix pour un autre produit</a>
            @endif
        </p>
    </form>

    <form method="post" action="{{ route('prices.update', $store) }}" class="stack">
        @csrf
        <input type="hidden" name="rayon" value="{{ $rayon }}">
        @if ($all)
            <input type="hidden" name="tous" value="1">
        @endif

        <div class="panel sticky-bar">
            <label class="field field-medium">
                <span>Date des prix (ticket)</span>
                <input type="date" name="observed_on" value="{{ $today }}" max="{{ $today }}" required>
            </label>
            <button type="submit" class="btn">Enregistrer les prix saisis</button>
        </div>

        @forelse ($aisles->filter(fn ($a) => $groups->has($a->id)) as $aisle)
            <section class="panel">
                <h2>{{ $aisle->name }}</h2>
                <div class="price-rows">
                    @foreach ($groups[$aisle->id] as $ingredient)
                        @foreach ($ingredient->packs as $pack)
                            @php $current = $pack->setRelation('ingredient', $ingredient)->currentPriceFor($store->id); @endphp
                            <div class="price-row">
                                <div class="price-row-label">
                                    <strong>{{ $ingredient->name }}</strong>
                                    <span class="muted">{{ $pack->label }}</span>
                                </div>
                                <div class="price-row-current">
                                    @if ($current)
                                        {{ \App\Models\Price::formatCents($current->price_cents) }}
                                        <span @class(['muted', 'small', 'is-estimate' => $current->source === 'estimation'])>{{ $current->sourceLabel() }}</span>
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </div>
                                <label class="price-row-input">
                                    <span class="sr-only">Nouveau prix pour {{ $ingredient->name }}, {{ $pack->label }}</span>
                                    <input type="text" name="prices[{{ $pack->id }}]" inputmode="decimal" placeholder="{{ $current ? number_format($current->price_cents / 100, 2, ',', '') : 'prix' }}">
                                </label>
                                <label class="check small">
                                    <input type="checkbox" name="promo[{{ $pack->id }}]" value="1">
                                    <span>promo</span>
                                </label>
                            </div>
                        @endforeach
                    @endforeach
                </div>
            </section>
        @empty
            <div class="panel"><p>Aucun conditionnement dans ce rayon.</p></div>
        @endforelse

        <div class="actions">
            <button type="submit" class="btn">Enregistrer les prix saisis</button>
        </div>
    </form>
@endsection
