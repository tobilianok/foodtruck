@extends('layouts.app')

@section('title', 'Plus')

@section('content')
    @php
        $household = auth()->user()->household;
        $toReview = $household->receipts()->where('status', 'a_valider')->count();
        $soon = \App\Support\AntiWaste::expiring($household)->count();
        $tiles = [
            ['stock.index', 'fridge', 'Frigo et placards', 'Ce qu\'il te reste à la maison, pour ne rien jeter.', $soon ? $soon.' à consommer vite' : null],
            ['receipts.index', 'receipt', 'Tickets de caisse', 'Tes tickets lus par l\'IA de ton PC et validés par toi, pour connaître les vrais prix.', $toReview ? $toReview.' à valider' : null],
            ['ingredients.index', 'plate', 'Ingrédients', 'Le référentiel : noms, rayons, saisons, conditionnements et prix de chaque ingrédient.', null],
            ['prices.index', 'tag', 'Prix par magasin', 'Mettre à jour les prix après les courses, magasin par magasin.', null],
            ['household.show', 'users', 'Mon foyer', 'Les personnes, le budget, les magasins et les invitations.', null],
            ['help', 'help', 'Comment ça marche', 'Les 4 étapes de la semaine et les réponses aux questions courantes.', null],
        ];
    @endphp

    <x-page-header title="Plus" lead="Tout le reste de Foodtruck, quand tu en as besoin." />

    <div class="tiles">
        @foreach ($tiles as [$route, $icon, $label, $text, $badge])
            <a class="tile" href="{{ route($route) }}">
                <span class="tile-icon">@include('partials.icon', ['name' => $icon])</span>
                <strong>{{ $label }}@if ($badge)<span class="tile-badge">{{ $badge }}</span>@endif</strong>
                <span class="tile-text">{{ $text }}</span>
            </a>
        @endforeach
    </div>

    <section class="panel">
        <h2>Mon compte</h2>
        <p>{{ auth()->user()->name }} · foyer « {{ $household->name }} »@if (auth()->user()->isAdmin()) <span class="badge">admin</span>@endif</p>
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-ghost">@include('partials.icon', ['name' => 'logout']) Se déconnecter</button>
        </form>
    </section>
@endsection
