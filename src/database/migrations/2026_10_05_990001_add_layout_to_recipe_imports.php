<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v0.15.0 : lecture des fiches par position (service foodtruck-ocr).
 * - layout : blocs de texte lus sur le scan, avec leur position (réponse du service, pour relire sans tout refaire) ;
 * - layout_status : « attente » (lecture à faire), « lu », « echec » (le texte de Paperless a servi à la place) ;
 * - layout_error : raison de l'échec, affichée à la relecture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_imports', function (Blueprint $table) {
            $table->json('layout')->nullable()->after('raw_text');
            $table->string('layout_status', 12)->nullable()->after('layout');
            $table->string('layout_error', 255)->nullable()->after('layout_status');
        });
    }

    public function down(): void
    {
        Schema::table('recipe_imports', function (Blueprint $table) {
            $table->dropColumn(['layout', 'layout_status', 'layout_error']);
        });
    }
};
