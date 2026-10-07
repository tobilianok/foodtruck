<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v0.22.0 : repas composés et menus enregistrés.
 * Un repas du planning (un jour + un créneau) peut réunir plusieurs recettes (plat, accompagnement, entrée, dessert) :
 * ce sont plusieurs lignes de meal_plan_entries sur la même case, comme avant. Un repas composé peut être enregistré
 * comme menu (« Jarret-purée ») et replanifié en un clic ; les plats planifiés depuis un menu le rappellent
 * (saved_menu_id, vidé si le menu est supprimé).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['household_id', 'name']);
        });

        Schema::create('saved_menu_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saved_menu_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['saved_menu_id', 'recipe_id']);
        });

        Schema::table('meal_plan_entries', function (Blueprint $table) {
            $table->foreignId('saved_menu_id')->nullable()->after('recipe_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('meal_plan_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('saved_menu_id');
        });
        Schema::dropIfExists('saved_menu_recipes');
        Schema::dropIfExists('saved_menus');
    }
};
