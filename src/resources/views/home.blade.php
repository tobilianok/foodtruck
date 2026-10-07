@extends('layouts.app')

@section('title', 'Accueil')

@section('content')
    <section class="home-hero">
        <h1>Bonjour {{ auth()->user()->firstName() }}</h1>
        <p class="lead">
            @if ($flow->done) Les courses de la semaine sont faites et vérifiées.
            @elseif ($flow->planned === 0 && $flow->key === 'plan') Voilà comment on prépare la semaine, en 4 étapes.
            @else Voilà où tu en es cette semaine.
            @endif
        </p>
    </section>

    <ol class="route" style="--i: {{ $flow->index }}" aria-label="Les 4 étapes de la semaine">
        @foreach ($flow->stops() as $stop)
            <li @class(['route-stop', 'is-done' => $stop['state'] === 'done', 'is-current' => $stop['state'] === 'current'])>
                {{-- v0.20.0 : chaque étape dit où on en est vraiment, et mène à sa page --}}
                <a class="route-link" href="{{ $stop['url'] }}">
                    <span class="route-dot">
                        @if ($stop['state'] === 'done') @include('partials.icon', ['name' => 'check']) @else {{ $loop->iteration }} @endif
                    </span>
                    <span class="route-label">{{ $stop['label'] }}</span>
                    <span class="route-status">{{ $stop['status'] }}</span>
                </a>
            </li>
        @endforeach
        <span class="route-truck">@include('partials.truck')</span>
    </ol>

    <section @class(['next-step', 'is-done' => $flow->done])>
        <div>
            <h2>{{ $flow->title }}</h2>
            <p>{{ $flow->text }}</p>
        </div>
        @if ($flow->method === 'post')
            <form method="post" action="{{ $flow->url }}">@csrf <button type="submit" class="btn btn-big">{{ $flow->button }}</button></form>
        @else
            <a class="btn btn-big" href="{{ $flow->url }}">{{ $flow->button }}</a>
        @endif
        @if ($flow->more)
            <p class="next-more"><a href="{{ $flow->moreUrl }}">{{ $flow->more }}</a></p>
        @endif
    </section>

    <div class="home-grid">
        <section class="panel">
            <h2>Aujourd'hui</h2>
            @if ($todayMeals->isEmpty())
                <p class="muted">Rien de prévu aujourd'hui.@if ($weekCount) {{ $weekCount }} plat{{ $weekCount > 1 ? 's' : '' }} cette semaine.@endif</p>
            @else
                <ul class="today-meals">
                    @foreach ($todayMeals as $meal)
                        <li><strong>{{ $meal->slotLabel() }}</strong>
                            <span>
                                @if ($meal->kind === 'hors_maison') Hors maison{{ $meal->note ? ' ('.$meal->note.')' : '' }}
                                @elseif ($meal->kind === 'note') {{ $meal->note }}
                                @elseif ($meal->isLeftover()) Restes de {{ $meal->source?->recipe?->title }}
                                @else {{ $meal->recipe?->title }} @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <a class="mini-link" href="{{ route('planning.index') }}">Voir la semaine</a>
        </section>

        @if ($weekCount > 0 && $budgetCents > 0)
            @php
                $ratio = $costCents / $budgetCents;
                $state = $ratio >= 1 ? 'over' : ($ratio >= .8 ? 'warn' : 'ok');
            @endphp
            <section class="panel">
                <h2>Budget de la semaine</h2>
                <p class="budget-figure">{{ \App\Models\Price::formatCents($costCents) }} <small>sur {{ \App\Models\Price::formatCents($budgetCents) }}</small></p>
                <div class="gauge gauge-{{ $state }}" role="img" aria-label="{{ round(min($ratio, 1) * 100) }} % du budget"><span style="width: {{ round(min($ratio, 1) * 100) }}%"></span></div>
                <p class="muted">
                    @if ($state === 'over') Tu dépasses le budget : regarde les plats à remplacer dans le planning.
                    @elseif ($state === 'warn') Tu approches du budget.
                    @else Tu es dans le budget. @endif
                </p>
                <a class="mini-link" href="{{ route('planning.index') }}">Économiser sur la semaine</a>
            </section>
        @endif

        @if ($billed && ! $flow->done)
            <section class="panel">
                <h2>Dernier bilan</h2>
                <p class="budget-figure">{{ \App\Models\Price::formatCents($billedCmp['paid_cents']) }} <small>payés sur {{ \App\Models\Price::formatCents($billedCmp['budget_cents']) }} de budget</small></p>
                <p class="muted">Courses du {{ $billed->periodLabel() }}.</p>
                <a class="mini-link" href="{{ route('shopping.bilan', $billed) }}">Revoir le bilan</a>
            </section>
        @endif

        @if ($soon->isNotEmpty())
            <section class="panel">
                <h2>À consommer vite</h2>
                <ul class="soon-list">
                    @foreach ($soon as $lot)
                        <li><span>{{ $lot->ingredient?->name }}</span>
                            <span class="stock-expiry is-soon">{{ $lot->expires_on->locale('fr')->isoFormat('D MMM') }}</span></li>
                    @endforeach
                </ul>
                <a class="mini-link" href="{{ route('stock.recipes') }}">Que cuisiner avec ça ?</a>
            </section>
        @endif
    </div>
@endsection
