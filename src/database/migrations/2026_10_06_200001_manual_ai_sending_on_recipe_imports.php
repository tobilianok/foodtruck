<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.18.1 : les fiches ne partent plus toutes seules vers l'IA ; Louis les envoie une par une, avec les pages qu'il a
 * cochées. Plus de nouvelles tentatives automatiques (colonnes de la v0.18.0 retirées). Les fiches en attente
 * d'envoi automatique repassent « à envoyer ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_imports', function (Blueprint $table) {
            $table->json('layout_pages')->nullable()->after('layout_started_at');
        });
        Schema::table('recipe_imports', function (Blueprint $table) {
            $table->dropColumn(['layout_attempts', 'layout_retry_at']);
        });

        DB::table('recipe_imports')->where('layout_status', 'attente')->whereNull('recipe_id')->update([
            'layout_status' => 'a_envoyer', 'layout_progress' => null, 'layout_step' => null, 'layout_started_at' => null, 'layout_error' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('recipe_imports', function (Blueprint $table) {
            $table->unsignedSmallInteger('layout_attempts')->default(0)->after('layout_started_at');
            $table->timestamp('layout_retry_at')->nullable()->after('layout_attempts');
        });
        Schema::table('recipe_imports', function (Blueprint $table) {
            $table->dropColumn('layout_pages');
        });
        DB::table('recipe_imports')->where('layout_status', 'a_envoyer')->update(['layout_status' => 'attente']);
    }
};
