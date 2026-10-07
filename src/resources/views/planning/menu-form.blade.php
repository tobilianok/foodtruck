@extends('layouts.app')

@section('title', 'Enregistrer comme menu')

@section('content')
    @php $day = \Illuminate\Support\Carbon::parse($date); @endphp
    <p class="back"><a href="{{ route('planning.week', \App\Support\MealPlanner::weekStart($date)->toDateString()) }}">← Retour au planning</a></p>
    <x-page-header title="Enregistrer comme menu"
                   :lead="'Repas du '.mb_strtolower(\App\Models\MealPlanEntry::SLOTS[$slot][0]).' '.$day->locale('fr')->isoFormat('dddd D MMMM').' : à replanifier ensuite en un clic.'" />

    <form method="post" action="{{ route('planning.menus.store') }}" class="stack">
        @csrf
        <input type="hidden" name="date" value="{{ $date }}">
        <input type="hidden" name="creneau" value="{{ $slot }}">
        <section class="panel">
            <label class="field">
                <span>Nom du menu</span>
                <input type="text" name="name" value="{{ old('name', $name) }}" maxlength="80" required>
            </label>
            @error('name') <p class="alert alert-error">{{ $message }}</p> @enderror
            <fieldset class="field">
                <legend>Recettes du menu</legend>
                <div class="checks">
                    @foreach ($dishes as $dish)
                        <label class="check">
                            <input type="checkbox" name="recettes[]" value="{{ $dish->recipe_id }}" @checked(in_array($dish->recipe_id, array_map('intval', old('recettes', $dishes->pluck('recipe_id')->all()))))>
                            <span>{{ $dish->recipe->title }} <small class="muted">{{ $dish->recipe->categoryLabel() }}</small></span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            @error('recettes') <p class="alert alert-error">{{ $message }}</p> @enderror
            <p class="hint small">Le menu garde les recettes, pas les convives : à chaque fois, elles sont calculées pour les personnes présentes à ce repas.</p>
        </section>
        <div class="actions">
            <a class="btn btn-ghost" href="{{ route('planning.week', \App\Support\MealPlanner::weekStart($date)->toDateString()) }}">Annuler</a>
            <button type="submit" class="btn">Enregistrer le menu</button>
        </div>
    </form>
@endsection
