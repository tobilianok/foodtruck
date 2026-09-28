<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * v0.4.0 : recettes (ingrédients, étapes, étiquettes, appareils requis, favoris).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->string('category', 20);
            $table->decimal('yield_quantity', 8, 2);
            $table->string('yield_unit', 12);
            $table->unsignedSmallInteger('prep_minutes')->nullable();
            $table->unsignedSmallInteger('cook_minutes')->nullable();
            $table->unsignedSmallInteger('rest_minutes')->nullable();
            $table->string('difficulty', 12)->default('facile');
            $table->string('protein', 20)->nullable();
            $table->string('source', 255)->nullable();
            $table->unsignedInteger('industrial_price_cents')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('thumb_path')->nullable();
            $table->string('status', 12)->default('publie');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('recipes')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'category']);
        });

        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('group_label', 60)->nullable();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 10, 2)->nullable();
            $table->string('unit', 10)->nullable();
            $table->string('note', 120)->nullable();
            $table->boolean('is_optional')->default(false);
        });

        Schema::create('recipe_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->text('body');
            $table->unsignedSmallInteger('timer_minutes')->nullable();
            $table->foreignId('equipment_id')->nullable()->constrained('equipment')->nullOnDelete();
        });

        Schema::create('recipe_equipment', function (Blueprint $table) {
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->primary(['recipe_id', 'equipment_id']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->string('slug', 40)->unique();
            $table->unsignedSmallInteger('position')->default(1000);
            $table->timestamps();
        });

        Schema::create('recipe_tag', function (Blueprint $table) {
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['recipe_id', 'tag_id']);
        });

        Schema::create('recipe_favorites', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['user_id', 'recipe_id']);
        });

        $tags = [
            'veggy' => 'Veggy',
            'vegan' => 'Végan',
            'rapido' => 'Rapido',
            'gourmand' => 'Gourmand',
            'batch-cooking' => 'Batch cooking',
            'enfant-friendly' => 'Enfant-friendly',
            'one-pot' => 'One-pot',
            'sans-four' => 'Sans four',
            'leger' => 'Léger',
            'reconfort' => 'Réconfort',
            'invites' => 'Invités',
            'lunchbox' => 'Lunchbox',
            'congelation' => 'Congélation possible',
        ];
        $now = now();
        $position = 10;
        foreach ($tags as $slug => $name) {
            DB::table('tags')->insert(['name' => $name, 'slug' => $slug, 'position' => $position, 'created_at' => $now, 'updated_at' => $now]);
            $position += 10;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_favorites');
        Schema::dropIfExists('recipe_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('recipe_equipment');
        Schema::dropIfExists('recipe_steps');
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipes');
    }
};
