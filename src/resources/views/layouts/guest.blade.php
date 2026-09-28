<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#c2410c">
    <title>@yield('title') · Foodtruck</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('foodtruck.version') }}">
</head>
<body class="guest">
    <main class="guest-card">
        <div class="brand brand-center">
            <span class="brand-mark" aria-hidden="true">FT</span>
            <span>Foodtruck</span>
        </div>
        @yield('content')
    </main>
</body>
</html>
