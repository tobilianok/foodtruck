<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#c2410c">
    <title>@yield('title', 'Accueil') · Foodtruck</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('foodtruck.version') }}">
</head>
<body>
    <header class="topbar">
        <div class="wrap topbar-inner">
            <a class="brand" href="{{ route('home') }}">
                <span class="brand-mark" aria-hidden="true">FT</span>
                <span>Foodtruck</span>
            </a>
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
        @yield('content')
    </main>

    <footer class="wrap footer">
        Foodtruck v{{ config('foodtruck.version') }}
    </footer>
</body>
</html>
