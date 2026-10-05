<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v0.7.0 : planning des repas.
 * Une ligne = un plat (ou des restes, un repas hors maison, une note) sur un créneau d'un jour.
 * Les restes pointent vers le plat cuisiné (source_entry_id) : ils ne sont comptés qu'une fois dans les courses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plan_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('slot', 16);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('kind', 12)->default('recette');
            $table->foreignId('recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_entry_id')->nullable()->constrained('meal_plan_entries')->cascadeOnDelete();
            $table->json('eaters')->nullable();
            $table->unsignedTinyInteger('guest_adults')->default(0);
            $table->unsignedTinyInteger('guest_children')->default(0);
            $table->unsignedTinyInteger('meals')->default(1);
            $table->decimal('parts_manual', 5, 2)->nullable();
            $table->decimal('batch_quantity', 8, 2)->nullable();
            $table->boolean('is_frozen')->default(false);
            $table->string('note', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['household_id', 'date']);
        });

        Schema::table('households', function (Blueprint $table) {
            // Créneaux affichés dans le planning (null = déjeuner, dîner, à préparer)
            $table->json('meal_slots')->nullable()->after('produce_store_id');
            // Semaine type : membres habituellement absents, par créneau et jour de la semaine (1 = lundi)
            $table->json('usual_absences')->nullable()->after('meal_slots');
        });
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn(['meal_slots', 'usual_absences']);
        });

        Schema::dropIfExists('meal_plan_entries');
    }
};
