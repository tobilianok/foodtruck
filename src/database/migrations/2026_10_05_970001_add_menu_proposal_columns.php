<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v0.13.0 : menu automatique.
 * - meal_plan_entries.proposed_at : repas proposé par Foodtruck, pas encore gardé ni validé. Tant qu'il est
 *   renseigné, le repas apparaît dans le planning mais ne compte ni dans la liste de courses ni sur l'accueil.
 * - meal_plan_entries.proposal_reason : pourquoi ce plat a été choisi (« 4,20 € pour ce repas · de saison »).
 * - households.menu_veggy_min : nombre minimal de repas végétariens par semaine (2 par défaut).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_plan_entries', function (Blueprint $table) {
            $table->timestamp('proposed_at')->nullable()->after('is_frozen');
            $table->string('proposal_reason', 200)->nullable()->after('proposed_at');
        });

        Schema::table('households', function (Blueprint $table) {
            $table->unsignedTinyInteger('menu_veggy_min')->default(2);
        });
    }

    public function down(): void
    {
        Schema::table('meal_plan_entries', function (Blueprint $table) {
            $table->dropColumn(['proposed_at', 'proposal_reason']);
        });

        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('menu_veggy_min');
        });
    }
};
