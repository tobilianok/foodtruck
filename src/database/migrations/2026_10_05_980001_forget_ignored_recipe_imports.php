<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * v0.13.2 : « Supprimer » une fiche Paperless l'efface complètement (elle n'est plus « mise de côté »).
 * Les fiches mises de côté avant cette version sont effacées aussi : « Chercher dans Paperless » les relira
 * depuis le début si leurs documents portent encore l'étiquette de recettes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('recipe_imports')->where('status', 'ignoree')->delete();
    }

    public function down(): void
    {
        // Rien à rétablir : les fiches effacées se recréent à la synchronisation.
    }
};
