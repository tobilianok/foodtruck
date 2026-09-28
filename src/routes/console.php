<?php

use App\Services\OidcClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * ./ft php artisan foodtruck:check
 * Contrôle rapide du socle : base de données, URL publique, Authentik.
 */
Artisan::command('foodtruck:check', function (OidcClient $oidc) {
    $ok = fn (string $msg) => $this->line("  <fg=green>OK</>    {$msg}");
    $ko = fn (string $msg) => $this->line("  <fg=red>ERREUR</> {$msg}");
    $errors = 0;

    $this->info('Foodtruck v'.config('foodtruck.version'));

    try {
        DB::select('select 1');
        $ok('Base de données joignable ('.config('database.default').')');
    } catch (Throwable $e) {
        $ko('Base de données : '.$e->getMessage());
        $errors++;
    }

    if (str_starts_with((string) config('app.url'), 'https://')) {
        $ok('URL publique : '.config('app.url'));
    } else {
        $ko('APP_URL doit commencer par https:// (actuel : '.config('app.url').')');
        $errors++;
    }

    if (config('app.debug')) {
        $this->line('  <fg=yellow>ATTENTION</> APP_DEBUG est actif');
    } else {
        $ok('Mode debug désactivé');
    }

    Cache::forget('oidc.discovery');
    try {
        $discovery = $oidc->discovery();
        $ok('Authentik joignable, émetteur : '.$discovery['issuer']);
        $ok('URL de retour à déclarer dans Authentik : '.config('oidc.redirect'));
    } catch (Throwable $e) {
        $ko('Authentik : '.$e->getMessage());
        $errors++;
    }

    $this->newLine();
    $errors === 0 ? $this->info('Tout est prêt.') : $this->error("{$errors} point(s) à corriger.");

    return $errors === 0 ? 0 : 1;
})->purpose('Vérifie la configuration du socle Foodtruck');
