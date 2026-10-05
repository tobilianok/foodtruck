@extends('layouts.app')

@section('title', 'Bienvenue')

@section('content')
    <section class="hero">
        <h1>Bienvenue {{ auth()->user()->firstName() }} !</h1>
        <p class="lead">Trois questions pour créer ton foyer. Tout reste modifiable ensuite dans « Mon foyer ».</p>
    </section>

    <form method="post" action="{{ route('onboarding.store') }}" class="stack" data-age-grid="{{ json_encode(collect(\App\Models\HouseholdMember::AGE_GRID)->map(fn ($r) => [$r[0], $r[1]])) }}">
        @csrf

        <section class="panel">
            <h2><span class="step">1</span> Le foyer</h2>
            <div class="grid-2">
                <label class="field">
                    <span>Nom du foyer</span>
                    <input type="text" name="name" value="{{ old('name', 'Famille '.auth()->user()->firstName()) }}" maxlength="60" required>
                </label>
                <label class="field">
                    <span>Budget courses par semaine</span>
                    <span class="input-suffix">
                        <input type="number" name="budget" value="{{ old('budget', 100) }}" min="10" max="2000" step="1" required>
                        <span>€</span>
                    </span>
                    <small>Plafond pour les ingrédients des repas planifiés.</small>
                </label>
                @include('household.store-fields', ['main' => old('main_store_id'), 'produce' => old('produce_store_id')])
            </div>
        </section>

        <section class="panel">
            <h2><span class="step">2</span> Qui mange à la maison ?</h2>
            <p class="hint">Le coefficient sert à calculer les portions. Avec la date de naissance, il suit l'âge tout seul ({{ \App\Models\HouseholdMember::gridSummary() }}). Sans date de naissance (adultes), saisis-le : 1 par défaut. « Régler à la main » le fixe (ex. gros mangeur : 1,5).</p>

            @php
                $members = old('members', [['name' => auth()->user()->firstName(), 'coefficient' => 1]]);
                $me = old('me', array_key_first($members));
            @endphp

            <div class="members" data-members>
                @foreach ($members as $key => $member)
                    @include('onboarding.member-row', ['key' => $key, 'member' => $member, 'me' => $me])
                @endforeach
            </div>

            <template data-member-template>
                @include('onboarding.member-row', ['key' => '__KEY__', 'member' => ['name' => '', 'coefficient' => 1], 'me' => null])
            </template>

            <button type="button" class="btn btn-ghost" data-add-member>+ Ajouter une personne</button>
            <p class="total">Total : <strong data-total-portions>…</strong> <span data-total-unit>parts</span> par repas</p>
        </section>

        <section class="panel">
            <h2><span class="step">3</span> Les appareils de la cuisine</h2>
            <p class="hint">Les recettes qui demandent un appareil que tu n'as pas seront signalées.</p>
            @include('household.equipment-fields', ['equipment' => $equipment, 'owned' => old('equipment', [])])
        </section>

        <div class="actions">
            <button type="submit" class="btn">Créer mon foyer</button>
        </div>
    </form>
@endsection
