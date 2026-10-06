<?php

use App\Models\Household;
use App\Models\User;
use App\Services\OidcClient;
use App\Support\Receipts\PaperlessClient;
use App\Support\Receipts\ReceiptSync;
use App\Support\RecipeScan\RecipeScanSync;
use App\Support\ReferenceImporter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
 * ./ft reset   (recommandé : sauvegarde la base avant)
 * Efface les données d'usage - planning, listes de courses, stock - et garde tout le reste.
 */
Artisan::command('foodtruck:reset {--foyer= : Numéro du foyer (par défaut : tous)} {--apercu : Affiche seulement ce qui serait effacé} {--oui : Efface sans demander confirmation}', function () {
    $householdId = $this->option('foyer') !== null ? (int) $this->option('foyer') : null;
    $counts = App\Support\UsageReset::counts($householdId);

    $this->info('Remise à zéro des données d\'usage'.($householdId ? " (foyer n° {$householdId})" : ' (tous les foyers)'));
    foreach ($counts as $label => $count) {
        $this->line(sprintf('  %-20s %d', $label, $count));
    }
    $this->line('  Conservé : comptes, foyers et réglages, recettes, ingrédients, magasins, tickets, prix.');

    if ($this->option('apercu')) {
        return 0;
    }

    if (array_sum($counts) === 0) {
        $this->info('Rien à effacer.');

        return 0;
    }

    if (! $this->option('oui') && $this->ask('Pour confirmer, tape EFFACER') !== 'EFFACER') {
        $this->warn('Annulé : rien n\'a été effacé.');

        return 1;
    }

    App\Support\UsageReset::run($householdId);
    $this->info('Terminé : le planning, les listes et le stock sont vides.');

    return 0;
})->purpose('Efface planning, listes de courses et stock (garde comptes, recettes, référentiel, tickets, prix)');

/*
 * ./ft vider-recettes   (sauvegarde la base avant, demande de taper EFFACER)
 * v0.18.0 : supprime TOUTES les recettes pour ne réimporter que les fiches de Louis (voir App\Support\RecipeWipe).
 */
Artisan::command('foodtruck:vider-recettes {--apercu : Affiche seulement ce qui serait effacé} {--oui : Efface sans demander confirmation}', function (RecipeScanSync $sync) {
    $counts = App\Support\RecipeWipe::counts();

    $this->info('Suppression de toutes les recettes');
    foreach ($counts as $label => $count) {
        $this->line(sprintf('  %-32s %d', $label, $count));
    }
    $this->line('  Conservé : comptes, foyers, ingrédients et leurs unités, magasins, tickets, prix, stock, listes de courses.');

    if ($this->option('apercu')) {
        return 0;
    }
    if (array_sum($counts) === 0) {
        $this->info('Rien à effacer.');

        return 0;
    }
    if (! $this->option('oui') && $this->ask('Pour confirmer, tape EFFACER') !== 'EFFACER') {
        $this->warn('Annulé : rien n\'a été effacé.');

        return 1;
    }

    App\Support\RecipeWipe::run();
    $this->info('Toutes les recettes sont supprimées.');

    // Les fiches de Paperless sont aussitôt remises en lecture (le planificateur les lit une par une)
    foreach (Household::all()->filter->hasPaperless() as $household) {
        $this->line("{$household->name} : ".RecipeScanSync::summary($sync->run($household)));
    }
    $this->line('Avancement de la lecture : Recettes → Fiches Paperless.');

    return 0;
})->purpose('Supprime toutes les recettes et relit les fiches de Paperless');

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

    try {
        foreach (Household::whereNotNull('paperless_url')->get() as $household) {
            if (! $household->hasPaperless()) {
                continue;
            }
            try {
                $check = PaperlessClient::for($household)->check($household->paperlessTag());
                $ok("Paperless ({$household->name}) : {$check['documents']} document(s) avec l'étiquette « {$household->paperlessTag()} »");
            } catch (Throwable $e) {
                $ko("Paperless ({$household->name}) : ".$e->getMessage());
                $errors++;
            }
        }
    } catch (Throwable) {
        // Table pas encore migrée : rien à vérifier
    }

    // v0.18.0 : préparation des pages (foodtruck-pages) puis lecture par le modèle de vision (Ollama sur le PC)
    if (\App\Support\RecipeScan\PagesClient::enabled()) {
        try {
            $health = \App\Support\RecipeScan\PagesClient::make()->health();
            ($health['ok'] ?? false)
                ? $ok('Préparation des pages (foodtruck-pages) : Poppler '.($health['poppler'] ?? '?'))
                : $ko('Préparation des pages (foodtruck-pages) : service en mauvais état');
            $errors += ($health['ok'] ?? false) ? 0 : 1;
        } catch (Throwable $e) {
            $ko('Préparation des pages : '.$e->getMessage());
            $errors++;
        }
    }

    // v0.18.0 : modèle de vision (Ollama sur le PC). PC éteint : ce n'est pas une panne, les fiches attendent
    if (\App\Support\RecipeScan\VisionClient::enabled()) {
        $vision = \App\Support\RecipeScan\VisionClient::make();
        try {
            $health = $vision->health();
            ($health['ok'] ?? false)
                ? $ok('Lecture par le modèle de vision : '.$vision->model().' (Ollama '.($health['version'] ?? '?').', '.config('foodtruck.vision_url').')')
                : $ko('Modèle '.$vision->model().' absent d\'Ollama (présents : '.(implode(', ', $health['modeles'] ?? []) ?: 'aucun').') : les fiches attendent');
            $errors += ($health['ok'] ?? false) ? 0 : 1;
        } catch (\App\Support\RecipeScan\VisionUnavailable $e) {
            $this->line('  <fg=yellow>INFO</>  '.$e->getMessage().' : les fiches attendent, la lecture sera retentée');
        } catch (Throwable $e) {
            $ko($e->getMessage());
            $errors++;
        }
    } else {
        $this->line('  <fg=yellow>INFO</>  Lecture par le modèle de vision désactivée (FOODTRUCK_VISION_URL vide) : le texte de Paperless sert');
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

/*
 * ./ft php artisan foodtruck:unites
 * v0.16.0 : pré-remplit les unités courantes des ingrédients (gousse, botte, sachet, boîte…) avec des valeurs
 * typiques marquées « valeur typique ». N'écrase jamais une unité existante ; relançable sans risque.
 */
Artisan::command('foodtruck:unites', function () {
    $added = 0;
    $touched = 0;
    \App\Models\Ingredient::with('units')->orderBy('name')->each(function ($ingredient) use (&$added, &$touched) {
        $n = \App\Support\TypicalUnits::seed($ingredient);
        $added += $n;
        $touched += $n > 0 ? 1 : 0;
    });
    $this->info("Unités typiques ajoutées : {$added} (sur {$touched} ingrédient(s)). Les valeurs se corrigent sur la fiche de chaque ingrédient.");
})->purpose('Pré-remplit les unités courantes des ingrédients');

/*
 * ./ft php artisan foodtruck:tickets
 * Synchronise les tickets Paperless de tous les foyers configurés (lancé toutes les heures).
 */
Artisan::command('foodtruck:tickets', function (ReceiptSync $sync) {
    $households = Household::whereNotNull('paperless_url')->get()->filter->hasPaperless();

    if ($households->isEmpty()) {
        $this->line('Aucun foyer relié à Paperless.');

        return 0;
    }

    $failed = 0;
    foreach ($households as $household) {
        $counts = $sync->run($household);
        $this->line("{$household->name} : ".ReceiptSync::summary($counts));
        $failed += $counts['error'] ? 1 : 0;
    }

    return $failed ? 1 : 0;
})->purpose('Synchronise les tickets de caisse depuis Paperless');

/*
 * ./ft php artisan foodtruck:reparse [--tout]
 * Relit les tickets encore « à valider » avec le lecteur à jour (après une mise à jour des règles).
 * --tout : relit aussi les tickets traités (les libellés déjà validés restent reconnus, les prix ne sont pas dupliqués).
 */
Artisan::command('foodtruck:reparse {--tout : relire aussi les tickets déjà traités}', function (\App\Support\Receipts\ReceiptProcessor $processor) {
    $statuses = $this->option('tout')
        ? [\App\Models\Receipt::STATUS_TO_REVIEW, \App\Models\Receipt::STATUS_DONE]
        : [\App\Models\Receipt::STATUS_TO_REVIEW];
    $receipts = \App\Models\Receipt::whereIn('status', $statuses)->get();

    foreach ($receipts as $receipt) {
        $receipt->total_cents = null;
        $processor->ingest($receipt);
    }

    $done = $receipts->filter(fn ($r) => $r->fresh()->status === \App\Models\Receipt::STATUS_DONE)->count();
    $this->info($receipts->count().' ticket(s) relu(s) : '.$done.' entièrement reconnu(s), '.($receipts->count() - $done).' à valider.');
})->purpose('Relit les tickets avec les règles de lecture à jour');

/*
 * ./ft php artisan foodtruck:recettes
 * Récupère dans Paperless les fiches de recettes (étiquette « recettes »), les lit et crée les recettes
 * (lancé toutes les heures, 20 minutes après les tickets).
 */
Artisan::command('foodtruck:recettes', function (RecipeScanSync $sync) {
    $households = Household::whereNotNull('paperless_url')->get()->filter->hasPaperless();

    if ($households->isEmpty()) {
        $this->line('Aucun foyer relié à Paperless.');

        return 0;
    }

    $failed = 0;
    foreach ($households as $household) {
        $counts = $sync->run($household);
        $this->line("{$household->name} : ".RecipeScanSync::summary($counts));
        $failed += $counts['error'] ? 1 : 0;
    }

    return $failed ? 1 : 0;
})->purpose('Lit les fiches de recettes déposées dans Paperless');

/*
 * ./ft php artisan foodtruck:relire-recettes
 * Relit les fiches de recettes encore « à relire » avec les règles à jour (après l'ajout d'ingrédients au référentiel,
 * par exemple) : celles qui sont désormais entièrement reconnues deviennent des recettes.
 */
Artisan::command('foodtruck:relire-recettes', function (\App\Support\RecipeScan\ScanImporter $importer) {
    $imports = \App\Models\RecipeImport::where('status', \App\Models\RecipeImport::STATUS_TO_REVIEW)->whereNull('recipe_id')
        // Fiches dont le scan attend sa lecture : c'est foodtruck:lire-fiches qui s'en charge
        ->when(\App\Support\RecipeScan\VisionClient::ready(), fn ($q) => $q->where(fn ($q) => $q->whereNull('layout_status')->orWhere('layout_status', '!=', \App\Models\RecipeImport::LAYOUT_PENDING)))
        ->get();

    foreach ($imports as $import) {
        $importer->ingest($import);
    }

    $ready = $imports->filter(fn ($i) => \App\Support\RecipeScan\ScanImporter::isReady($i->fresh()))->count();
    $this->info($imports->count().' fiche(s) relue(s) : '.$ready.' prête(s) à valider, '.($imports->count() - $ready).' à compléter.');
})->purpose('Relit les fiches de recettes en attente avec les règles à jour');

/*
 * ./ft php artisan foodtruck:lire-fiches [--toutes] [--en-attente]
 * Lit les fiches en attente avec le modèle de vision (Ollama sur le PC ; lancé chaque minute par le planificateur, une fiche à
 * la fois ; une lecture en échec est retentée toute seule).
 * --toutes : relit aussi, d'après le scan, toutes les fiches encore à relire (après l'installation du service).
 * --en-attente : avec --toutes, remet seulement les fiches en lecture et rend la main ; le planificateur les lit
 *                une par une (barre de progression dans « Fiches Paperless »).
 */
Artisan::command('foodtruck:lire-fiches {--toutes : Remettre en lecture toutes les fiches encore à relire} {--en-attente : Seulement les remettre en lecture (le planificateur les lit)}', function (\App\Support\RecipeScan\RecipeLayoutRunner $runner) {
    if (! \App\Support\RecipeScan\VisionClient::ready()) {
        $this->line('Lecture par le modèle de vision désactivée (FOODTRUCK_VISION_URL ou FOODTRUCK_PAGES_URL vide).');

        return 0;
    }

    if ($this->option('toutes')) {
        $this->line(\App\Support\RecipeScan\RecipeLayoutRunner::queueAllToReview().' fiche(s) remise(s) en lecture.');
        if ($this->option('en-attente')) {
            $this->line('Le planificateur les lit une par une : avancement dans Recettes → Fiches Paperless.');

            return 0;
        }
    }

    $counts = $runner->processPending($this->option('toutes') ? null : 50);
    if ($counts['busy']) {
        $this->line('Une lecture est déjà en cours.');

        return 0;
    }
    if ($counts['read'] + $counts['failed'] + $counts['left'] > 0) {
        $this->info($counts['read'].' fiche(s) lue(s) par le modèle, '.$counts['failed'].' en échec (lecture retentée plus tard), '.$counts['left'].' encore en attente.');
    }

    return $counts['failed'] > 0 ? 1 : 0;
})->purpose('Lit les fiches de recettes en attente avec le modèle de vision (Ollama)');

// v0.18.0 : une lecture par le modèle de vision dure 30 minutes au plus (verrou d'une heure, comme celui du lecteur)
Schedule::command('foodtruck:lire-fiches')->everyMinute()->withoutOverlapping(60)->runInBackground();

Schedule::command('foodtruck:tickets')->hourly()->withoutOverlapping(30);
Schedule::command('foodtruck:recettes')->hourlyAt(20)->withoutOverlapping(30);
