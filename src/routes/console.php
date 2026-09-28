<?php

use App\Models\User;
use App\Services\OidcClient;
use App\Support\ReferenceImporter;
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

/*
 * ./ft php artisan foodtruck:users
 * Liste des comptes (créés à la première connexion via Authentik).
 */
Artisan::command('foodtruck:users', function () {
    $rows = User::orderBy('id')->get()->map(fn (User $u) => [
        $u->id,
        $u->username,
        $u->name,
        $u->email,
        $u->role,
        $u->last_login_at?->timezone('Europe/Paris')->format('d/m/Y H:i'),
    ])->all();

    $this->table(['#', 'Identifiant', 'Nom', 'E-mail', 'Rôle', 'Dernière connexion'], $rows);
})->purpose('Liste les comptes Foodtruck');

/*
 * ./ft php artisan foodtruck:role <identifiant> <admin|membre>
 */
Artisan::command('foodtruck:role {username} {role}', function (string $username, string $role) {
    if (! in_array($role, [User::ROLE_ADMIN, User::ROLE_MEMBER], true)) {
        $this->error('Rôle attendu : admin ou membre.');

        return 1;
    }

    $user = User::where('username', $username)->first();
    if (! $user) {
        $this->error("Aucun compte « {$username} ». La personne doit s'être connectée au moins une fois.");

        return 1;
    }

    if ($role === User::ROLE_MEMBER && $user->isAdmin() && User::where('role', User::ROLE_ADMIN)->count() === 1) {
        $this->error('Impossible : c\'est le dernier administrateur.');

        return 1;
    }

    $user->role = $role;
    $user->save();
    $this->info("{$user->name} ({$user->username}) est maintenant {$role}.");

    return 0;
})->purpose('Change le rôle d\'un compte (admin ou membre)');

/*
 * ./ft php artisan foodtruck:remove-user <identifiant>
 * Supprime le compte Foodtruck (pas le compte Authentik) et ses sessions.
 */
Artisan::command('foodtruck:remove-user {username}', function (string $username) {
    $user = User::where('username', $username)->first();
    if (! $user) {
        $this->error("Aucun compte « {$username} ».");

        return 1;
    }

    if ($user->isAdmin() && User::where('role', User::ROLE_ADMIN)->count() === 1) {
        $this->error('Impossible : c\'est le dernier administrateur. Nomme d\'abord un autre admin.');

        return 1;
    }

    DB::table('sessions')->where('user_id', $user->id)->delete();
    $user->delete();
    $this->info("Compte Foodtruck « {$username} » supprimé (le compte Authentik est inchangé).");

    return 0;
})->purpose('Supprime un compte Foodtruck');

/*
 * ./ft php artisan foodtruck:reference
 * Importe le jeu d'ingrédients de départ (n'ajoute que les absents, n'écrase rien).
 */
Artisan::command('foodtruck:reference', function () {
    $counts = ReferenceImporter::import();
    $this->info("Ingrédients ajoutés : {$counts['ingredients']} ({$counts['packs']} conditionnements, {$counts['prices']} prix estimés).");
    if ($counts['skipped'] > 0) {
        $this->line("Déjà présents, laissés tels quels : {$counts['skipped']}.");
    }
})->purpose('Importe le jeu d\'ingrédients de départ');
