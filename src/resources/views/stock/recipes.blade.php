@extends('layouts.app')

@section('title', 'Que cuisiner ?')

@section('content')
    @php $Price = \App\Models\Price::class; @endphp

    <section class="hero hero-compact planning-head">
        <div>
            <p class="eyebrow">Anti-gaspi</p>
            <h1>Que cuisiner ?</h1>
        </div>
        <div class="week-nav"><a class="btn btn-small btn-ghost" href="{{ route('stock.index') }}">← Mon stock</a></div>
    </section>

    @if ($soon->isNotEmpty())
        <section class="panel stock-soon">
            <h2>À consommer vite</h2>
            <ul class="stock-list">
                @foreach ($soon as $lot)
                    <li><span><strong>{{ $lot->ingredient->name }}</strong> <span class="muted">{{ $lot->quantityLabel() }}</span></span>
                        <span @class(['stock-expiry', 'is-expired' => $lot->isExpired(), 'is-soon' => $lot->isSoon()])>{{ $lot->expiryLabel() }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if (! $hasStock)
        <section class="panel"><p>Ton stock est vide : <a href="{{ route('stock.index') }}">ajoute ce que tu as</a> et les recettes réalisables avec apparaîtront ici.</p></section>
    @elseif ($suggestions->isEmpty())
        <section class="panel"><p>Aucune recette ne correspond assez à ton stock pour l'instant. Les produits de base (sel, huile, farine…) ne sont pas comptés.</p></section>
    @else
        <p class="hint">Recettes classées selon ce que tu as déjà : d'abord celles qui utilisent un produit à consommer vite, puis celles dont le plus d'ingrédients sont en stock. Les quantités sont celles de ton foyer.</p>
        <section class="stock-recipes">
            @foreach ($suggestions as $s)
                <article class="panel stock-recipe">
                    <header>
                        <h2><a href="{{ route('recipes.show', $s['recipe']) }}">{{ $s['recipe']->title }}</a></h2>
                        <span class="stock-ratio" title="Ingrédients entièrement couverts par le stock">{{ $s['covered'] }} / {{ $s['total'] }} en stock</span>
                    </header>
                    <div class="gauge gauge-ok" role="img" aria-label="{{ (int) round($s['ratio'] * 100) }} % couvert"><span style="width: {{ (int) round($s['ratio'] * 100) }}%"></span></div>
                    @if ($s['expiring'])
                        <p class="stock-flag">À finir : {{ implode(', ', $s['expiring']) }}</p>
                    @endif
                    <p class="small"><span class="muted">En stock :</span>
                        @foreach ($s['have'] as $h)<span @class(['chip-have', 'is-soon' => $h['soon']])>{{ $h['name'] }}</span>@endforeach
                    </p>
                    @if ($s['missing'])
                        <p class="small"><span class="muted">À acheter :</span> {{ implode(', ', $s['missing']) }}@if ($s['missing_cents'] >= 1) <span class="muted">(≈ {{ $Price::formatCents($s['missing_cents']) }})</span>@endif</p>
                    @else
                        <p class="small stock-flag">Tout est déjà en stock.</p>
                    @endif
                    <p><a class="btn btn-small" href="{{ route('planning.create', ['recette' => $s['recipe']->slug]) }}">Ajouter au planning</a></p>
                </article>
            @endforeach
        </section>
    @endif
@endsection
