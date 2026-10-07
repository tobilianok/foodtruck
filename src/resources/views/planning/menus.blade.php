@extends('layouts.app')

@section('title', 'Mes menus')

@section('content')
    @php $Price = \App\Models\Price::class; @endphp
    <p class="back"><a href="{{ route('planning.index') }}">← Planning</a></p>
    <x-page-header title="Mes menus" lead="Des repas composés (plat, accompagnement, entrée, dessert) à replanifier en un clic." />

    @if ($menus->isEmpty())
        <section class="empty">
            <span class="empty-icon">@include('partials.icon', ['name' => 'plate'])</span>
            <h2>Aucun menu pour l'instant</h2>
            <p>Dans le planning, mets plusieurs recettes sur un même repas (le + de la case, ou « Avec » en ajoutant un plat), puis touche « Enregistrer comme menu ».</p>
            <a class="btn" href="{{ route('planning.index') }}">Aller au planning</a>
        </section>
    @else
        <section class="menu-cards">
            @foreach ($menus as $menu)
                <article class="panel menu-card">
                    <h2>{{ $menu->name }}</h2>
                    <ol>
                        @foreach ($menu->recipes as $recipe)
                            <li><a href="{{ route('recipes.show', $recipe) }}">{{ $recipe->title }}</a> <small class="muted">{{ $recipe->categoryLabel() }}</small></li>
                        @endforeach
                    </ol>
                    <p class="hint small">
                        Environ {{ $Price::formatCents($costs[$menu->id] ?? 0) }} le repas pour tout le foyer
                        @php $used = $uses[$menu->id] ?? 0; @endphp
                        · {{ $used > 0 ? 'planifié '.$used.' fois' : 'pas encore planifié' }}
                    </p>
                    <div class="row-actions">
                        <a class="btn btn-small" href="{{ route('planning.create', ['menu' => $menu->id]) }}">Planifier</a>
                        <form method="post" action="{{ route('planning.menus.destroy', $menu) }}">
                            @csrf @method('delete')
                            <button type="submit" class="btn btn-small btn-ghost btn-danger" data-confirm="Supprimer le menu « {{ $menu->name }} » ? Les repas déjà planifiés restent.">Supprimer</button>
                        </form>
                    </div>
                    <details>
                        <summary class="small">Renommer</summary>
                        <form method="post" action="{{ route('planning.menus.update', $menu) }}" class="menu-rename">
                            @csrf @method('put')
                            <input type="text" name="name" value="{{ $menu->name }}" maxlength="80" required aria-label="Nouveau nom">
                            <button type="submit" class="btn btn-small btn-ghost">Renommer</button>
                        </form>
                    </details>
                </article>
            @endforeach
        </section>
        @error('name') <p class="alert alert-error">{{ $message }}</p> @enderror
    @endif
@endsection
