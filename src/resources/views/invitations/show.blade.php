@extends('layouts.app')

@section('title', 'Invitation')

@section('content')
    <section class="panel narrow">
        <h1>Rejoindre « {{ $invitation->household->name }} »</h1>
        <p>
            {{ $invitation->creator?->name ?? 'Un administrateur' }} t'invite à rejoindre ce foyer :
            menus, recettes et listes de courses seront partagés.
        </p>

        <form method="post" action="{{ route('invitation.accept') }}" class="stack">
            @csrf
            @if ($freeMembers->isNotEmpty())
                <fieldset class="field">
                    <legend>Qui es-tu dans ce foyer ?</legend>
                    @foreach ($freeMembers as $member)
                        <label class="check">
                            <input type="radio" name="member" value="{{ $member->id }}" @checked($loop->first)>
                            <span>{{ $member->name }} ({{ $member->categoryLabel() }})</span>
                        </label>
                    @endforeach
                    <label class="check">
                        <input type="radio" name="member" value="new">
                        <span>Je ne suis pas dans la liste : m'ajouter comme adulte</span>
                    </label>
                </fieldset>
            @else
                <p class="hint">Tu seras ajouté(e) aux membres du foyer en tant qu'adulte ; l'admin pourra ajuster.</p>
            @endif

            <div class="actions">
                <button type="submit" class="btn">Rejoindre le foyer</button>
            </div>
        </form>

        <form method="post" action="{{ route('invitation.dismiss') }}" class="leave">
            @csrf
            <button type="submit" class="btn btn-small btn-ghost">Non merci, créer mon propre foyer</button>
        </form>
    </section>
@endsection
