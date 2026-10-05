@extends('layouts.app')

@section('title', 'Bilan des courses')

@section('content')
    @php
        $Price = \App\Models\Price::class;
        $budget = $cmp['budget_cents'];
        $paid = $cmp['paid_cents'];
        $hasReceipts = $cmp['receipts']->isNotEmpty();
        $percent = $budget > 0 ? (int) round($paid / $budget * 100) : 0;
        $level = $percent > 100 ? 'over' : ($percent >= 80 ? 'warn' : 'ok');
        $gap = $cmp['matched_paid_cents'] - $cmp['matched_estimated_cents'];
        $signed = fn (int $cents) => ($cents > 0 ? '+' : ($cents < 0 ? '−' : '')).$Price::formatCents(abs($cents));
    @endphp

    <section class="hero hero-compact">
        <p><a href="{{ route('shopping.show', $list) }}">← Courses du {{ $list->periodLabel() }}</a></p>
        <h1>Bilan des courses du {{ $list->periodLabel() }}</h1>
        <p class="lead">Ce qui a été payé, comparé à ce qui était prévu.</p>
    </section>

    @if (! $hasReceipts)
        <section class="panel">
            <h2>Aucun ticket rattaché</h2>
            <p>Le bilan se fait d'après les tickets de caisse. Un ticket traité (page <a href="{{ route('receipts.index') }}">Tickets</a>) est rattaché tout seul à la liste dont la période correspond à sa date d'achat ; tu peux aussi le faire à la main :</p>
            @forelse ($loose as $receipt)
                <form method="post" action="{{ route('receipts.link', $receipt) }}" class="inline-form bilan-attach">
                    @csrf
                    <input type="hidden" name="list_id" value="{{ $list->id }}">
                    <span>{{ $receipt->label() }} @if ($receipt->total_cents !== null) <span class="muted">· {{ $Price::formatCents($receipt->total_cents) }}</span> @endif</span>
                    <button type="submit" class="btn btn-small">Rattacher à cette liste</button>
                </form>
            @empty
                <p class="hint">Aucun ticket traité disponible pour l'instant.</p>
            @endforelse
        </section>
    @else
        <section class="panel budget-panel">
            <div class="bilan-figures">
                <div><span class="muted small">Budget de la période</span><strong>{{ $Price::formatCents($budget) }}</strong></div>
                <div><span class="muted small">Estimé (liste)</span><strong>{{ $Price::formatCents($cmp['estimated_cents']) }}</strong></div>
                <div><span class="muted small">Payé (tickets)</span><strong>{{ $Price::formatCents($paid) }}</strong></div>
            </div>
            <div class="gauge gauge-{{ $level }}" role="img" aria-label="{{ $percent }} % du budget"><span style="width: {{ min(100, $percent) }}%"></span></div>
            <p class="hint small">
                @if ($paid <= $budget)
                    {{ $percent }} % du budget : {{ $Price::formatCents($budget - $paid) }} de marge.
                @else
                    Budget dépassé de {{ $Price::formatCents($paid - $budget) }}.
                @endif
                @if ($cmp['matched'] > 0)
                    Sur les {{ $cmp['matched'] }} article{{ $cmp['matched'] > 1 ? 's' : '' }} retrouvé{{ $cmp['matched'] > 1 ? 's' : '' }} avec une estimation :
                    {{ $gap === 0 ? 'payé comme prévu' : ($gap > 0 ? $Price::formatCents($gap).' de plus que prévu' : $Price::formatCents(abs($gap)).' de moins que prévu') }}.
                @endif
            </p>
            <p class="small">
                Tickets pris en compte :
                @foreach ($cmp['receipts'] as $receipt)
                    <a href="{{ route('receipts.show', $receipt) }}">{{ $receipt->label() }}</a>@if ($receipt->total_cents !== null) ({{ $Price::formatCents($receipt->total_cents) }})@endif @if (! $loop->last) · @endif
                @endforeach
            </p>
        </section>

        <section class="panel">
            <h2>Article par article</h2>
            <div class="table-wrap">
                <table class="history-table bilan-table">
                    <thead><tr><th>Article</th><th>Estimé</th><th>Payé</th><th>Écart</th></tr></thead>
                    <tbody>
                    @foreach ($cmp['rows'] as $row)
                        @php
                            $diff = $row['found'] && $row['estimated_cents'] !== null ? $row['paid_cents'] - $row['estimated_cents'] : null;
                        @endphp
                        <tr @class(['is-missing' => ! $row['found']])>
                            <td>{{ $row['item']->label }}
                                @if ($row['stores']) <span class="muted small">· {{ implode(', ', $row['stores']) }}</span> @endif
                                @if (! $row['found'])
                                    <span class="muted small">· @if ($row['item']->ingredient_id === null) article libre, non comparable @else pas retrouvé sur les tickets @endif</span>
                                @endif
                            </td>
                            <td>{{ $row['estimated_cents'] !== null ? $Price::formatCents($row['estimated_cents']) : '—' }}</td>
                            <td>{{ $row['paid_cents'] !== null ? $Price::formatCents($row['paid_cents']) : '—' }}</td>
                            <td @class(['diff-up' => $diff > 0, 'diff-down' => $diff < 0])>{{ $diff !== null ? ($diff === 0 ? '=' : $signed($diff)) : '' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($cmp['missing'] > 0)
                <p class="hint small">{{ $cmp['missing'] }} article{{ $cmp['missing'] > 1 ? 's' : '' }} de la liste n'{{ $cmp['missing'] > 1 ? 'ont' : 'a' }} pas été retrouvé{{ $cmp['missing'] > 1 ? 's' : '' }} sur les tickets : oubli, autre magasin (ticket pas encore rattaché) ou produit lu sous un autre nom.</p>
            @endif
        </section>

        @if ($cmp['extras'])
            <section class="panel">
                <h2>Achats hors liste <span class="muted small">{{ $Price::formatCents($cmp['extras_cents']) }}</span></h2>
                <ul class="frozen-list">
                    @foreach ($cmp['extras'] as $extra)
                        <li><span>{{ $extra['label'] }} @if ($extra['store']) <span class="muted small">· {{ $extra['store'] }}</span> @endif</span><span>{{ $Price::formatCents($extra['paid_cents']) }}</span></li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($prices['hausses'] || $prices['recalages'])
            <section class="panel">
                <h2>Prix</h2>
                @if ($prices['hausses'])
                    <h3 class="small">Prix qui montent</h3>
                    <ul class="frozen-list">
                        @foreach ($prices['hausses'] as $p)
                            <li><span>{{ $p['ingredient'] }} @if ($p['pack']) <span class="muted small">· {{ $p['pack'] }}</span> @endif <span class="muted small">· {{ $p['store'] }}</span></span>
                                <span class="diff-up">{{ $Price::formatCents($p['from_cents']) }} → {{ $Price::formatCents($p['to_cents']) }} (+{{ $p['percent'] }} %)</span></li>
                        @endforeach
                    </ul>
                    <p class="hint small">Compare avec un autre magasin sur la page <a href="{{ route('prices.index') }}">Prix</a> avant la prochaine liste.</p>
                @endif
                @if ($prices['recalages'])
                    <h3 class="small">Estimations recalées sur le prix réel</h3>
                    <ul class="frozen-list">
                        @foreach ($prices['recalages'] as $p)
                            <li><span>{{ $p['ingredient'] }} @if ($p['pack']) <span class="muted small">· {{ $p['pack'] }}</span> @endif <span class="muted small">· {{ $p['store'] }}</span></span>
                                <span @class(['diff-up' => $p['percent'] > 0, 'diff-down' => $p['percent'] < 0])>{{ $Price::formatCents($p['from_cents']) }} → {{ $Price::formatCents($p['to_cents']) }} ({{ $p['percent'] > 0 ? '+' : '−' }}{{ abs($p['percent']) }} %)</span></li>
                        @endforeach
                    </ul>
                    <p class="hint small">Les prochaines listes utilisent ces prix réels.</p>
                @endif
            </section>
        @endif
    @endif

    @if ($stocked->isNotEmpty())
        <section class="panel">
            <h2>Rangé au stock après ces courses</h2>
            <ul class="frozen-list">
                @foreach ($stocked as $lot)
                    <li><span>{{ $lot->ingredient->name }}</span><span class="muted">{{ $lot->quantityLabel() }}</span></li>
                @endforeach
            </ul>
            <p class="hint small"><a href="{{ route('stock.recipes') }}">Que cuisiner avec ça ?</a></p>
        </section>
    @endif
@endsection
