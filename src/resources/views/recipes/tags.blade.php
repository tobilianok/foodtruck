@extends('layouts.app')

@section('title', 'Étiquettes des recettes')

@section('content')
    <p class="back"><a href="{{ route('recipes.index') }}">← Recettes</a></p>
    <x-page-header title="Étiquettes des recettes" lead="Pour classer et filtrer les recettes : Viandes, Fêtes, Accompagnement… Une étiquette sert à tout le monde." />

    <section class="panel">
        <h2>Créer une étiquette</h2>
        <form method="post" action="{{ route('recipes.tags.store') }}" class="menu-rename">
            @csrf
            <input type="text" name="name" value="{{ old('name') }}" maxlength="200" required placeholder="ex. Viandes, Poissons, Fêtes" aria-label="Nouvelle étiquette">
            <button type="submit" class="btn">Créer</button>
        </form>
        @error('name') <p class="alert alert-error">{{ $message }}</p> @enderror
        <p class="hint small">Plusieurs d'un coup : sépare-les par des virgules. Une étiquette qui existe déjà n'est pas créée deux fois. On peut aussi en créer depuis le formulaire d'une recette.</p>
    </section>

    <section class="panel">
        <h2>Étiquettes <span class="muted">({{ $tags->count() }})</span></h2>
        <ul class="frozen-list tag-list">
            @foreach ($tags as $tag)
                <li>
                    <span><a href="{{ route('recipes.index', ['etiquette' => $tag->slug]) }}">{{ $tag->name }}</a>
                        <span class="muted small">· {{ $tag->recipes_count }} recette{{ $tag->recipes_count > 1 ? 's' : '' }}</span></span>
                    @if ($canManage)
                        <span class="row-actions">
                            <form method="post" action="{{ route('recipes.tags.update', $tag) }}" class="menu-rename">
                                @csrf @method('put')
                                <input type="text" name="name" value="{{ $tag->name }}" maxlength="{{ \App\Models\Tag::NAME_MAX }}" required aria-label="Nouveau nom de « {{ $tag->name }} »">
                                <button type="submit" class="btn btn-small btn-ghost">Renommer</button>
                            </form>
                            @unless ($tag->isBuiltIn())
                                <form method="post" action="{{ route('recipes.tags.destroy', $tag) }}">
                                    @csrf @method('delete')
                                    <button type="submit" class="btn btn-small btn-ghost btn-danger" data-confirm="Supprimer l'étiquette « {{ $tag->name }} »{{ $tag->recipes_count ? ' ? Elle sera retirée de '.$tag->recipes_count.' recette'.($tag->recipes_count > 1 ? 's' : '') : ' ?' }}">Supprimer</button>
                                </form>
                            @endunless
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
        @unless ($canManage)
            <p class="hint small">Renommer ou supprimer une étiquette est réservé aux administrateurs.</p>
        @endunless
    </section>
@endsection
