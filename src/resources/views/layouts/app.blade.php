<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#c2410c">
    <title>@yield('title', 'Accueil') · Foodtruck</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('foodtruck.version') }}">
    <script src="{{ asset('js/app.js') }}?v={{ config('foodtruck.version') }}" defer></script>
</head>
<body>
    <header class="topbar">
        <div class="wrap topbar-inner">
            <a class="brand" href="{{ route('home') }}">
                <span class="brand-mark" aria-hidden="true">FT</span>
                <span>Foodtruck</span>
            </a>
            @if (auth()->user()->household_id)
                <nav class="mainnav" aria-label="Navigation principale">
                    <a href="{{ route('home') }}" @class(['is-active' => request()->routeIs('home')])>Accueil</a>
                    <a href="{{ route('ingredients.index') }}" @class(['is-active' => request()->routeIs('ingredients.*')])>Ingrédients</a>
                    <a href="{{ route('prices.index') }}" @class(['is-active' => request()->routeIs('prices.*')])>Prix</a>
                    <a href="{{ route('household.show') }}" @class(['is-active' => request()->routeIs('household.*')])>Mon foyer</a>
                </nav>
            @endif
            <div class="account">
                <span class="account-name">{{ auth()->user()->name }}</span>
                @if (auth()->user()->isAdmin())
                    <span class="badge">admin</span>
                @endif
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-ghost">Déconnexion</button>
                </form>
            </div>
        </div>
    </header>

    <main class="wrap">
        @include('partials.flash')
        @yield('content')
    </main>

    <footer class="wrap footer">
        Foodtruck v{{ config('foodtruck.version') }}
    </footer>
</body>
</html>
