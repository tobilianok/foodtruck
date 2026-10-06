<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.16.0 : plusieurs unités par ingrédient (« 1 sachet de coriandre = 10 g », « 1 gousse d'ail = 5 g »).
 * L'unité de base de l'ingrédient (g, ml, pièce) reste la référence : liste de courses, prix, stock.
 * Une ligne de recette dans une de ces unités est notée « u:<slug> » dans recipe_ingredients.unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 36);
            $table->string('name', 40);
            $table->string('plural', 40)->nullable();
            $table->decimal('quantity', 12, 4);          // dans l'unité de base de l'ingrédient
            $table->boolean('is_estimate')->default(false); // valeur typique proposée par Foodtruck, à corriger au besoin
            $table->timestamps();
            $table->unique(['ingredient_id', 'slug']);
        });

        Schema::table('recipe_ingredients', function (Blueprint $table) {
            $table->string('unit', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_units');
    }
};
