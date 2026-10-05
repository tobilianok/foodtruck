<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#1e7a4f">
    <title>@yield('title', 'Accueil') · Foodtruck</title>
    <link rel="preload" href="{{ asset('fonts/bricolage-grotesque-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('fonts/figtree-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('foodtruck.version') }}">
    <script src="{{ asset('js/app.js') }}?v={{ config('foodtruck.version') }}" defer></script>
</head>
@php
    $tabs = [
        ['route' => 'home', 'label' => 'Accueil', 'icon' => 'home', 'on' => ['home']],
        ['route' => 'planning.index', 'label' => 'Menus', 'icon' => 'calendar', 'on' => ['planning.*']],
        ['route' => 'shopping.index', 'label' => 'Courses', 'icon' => 'cart', 'on' => ['shopping.*']],
        ['route' => 'recipes.index', 'label' => 'Recettes', 'icon' => 'book', 'on' => ['recipes.*']],
        ['route' => 'plus', 'label' => 'Plus', 'icon' => 'more', 'on' => ['plus', 'help', 'stock.*', 'receipts.*', 'prices.*', 'ingredients.*', 'household.*']],
    ];
    $initials = collect(preg_split('/\s+/', trim(auth()->user()->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
@endphp
<body>
    <header class="topbar">
        <div class="wrap topbar-inner">
            <a class="brand" href="{{ route('home') }}">
                <span class="brand-mark">@include('partials.truck')</span>
                <span>Foodtruck</span>
            </a>
            @if (auth()->user()->household_id)
                <nav class="mainnav" aria-label="Navigation principale">
                    @foreach ($tabs as $tab)
                        <a href="{{ route($tab['route']) }}" @class(['is-active' => request()->routeIs(...$tab['on'])])>@include('partials.icon', ['name' => $tab['icon']]) {{ $tab['label'] }}</a>
                    @endforeach
                </nav>
            @endif
            <div class="account">
                <span class="account-name">{{ auth()->user()->name }}</span>
                @if (auth()->user()->isAdmin())
                    <span class="badge">admin</span>
                @endif
                <a class="avatar" href="{{ route('plus') }}" title="{{ auth()->user()->name }}">{{ $initials ?: '?' }}</a>
            </div>
        </div>
    </header>

    <main class="wrap @yield('main-class')">
        @include('partials.flash')
        @yield('content')
    </main>

    <footer class="wrap footer">
        Foodtruck v{{ config('foodtruck.version') }}
    </footer>

    @if (auth()->user()->household_id)
        <nav class="tabbar" aria-label="Navigation principale">
            @foreach ($tabs as $tab)
                <a href="{{ route($tab['route']) }}" @class(['is-active' => request()->routeIs(...$tab['on'])]) @if (request()->routeIs(...$tab['on'])) aria-current="page" @endif>
                    <span class="tab-icon">@include('partials.icon', ['name' => $tab['icon']])</span>
                    <span>{{ $tab['label'] }}</span>
                </a>
            @endforeach
        </nav>
    @endif
</body>
</html>
