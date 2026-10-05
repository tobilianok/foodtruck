<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.12.0 : fiches de recettes scannées dans Paperless (étiquette « recettes »),
 * lues automatiquement puis insérées comme recettes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->string('paperless_recipe_tag', 80)->nullable()->after('paperless_tag');
        });

        Schema::create('recipe_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('paperless_document_id');
            $table->timestamp('paperless_modified_at')->nullable();
            $table->string('title', 200)->nullable();
            $table->longText('raw_text')->nullable();
            $table->json('parsed')->nullable();
            $table->json('issues')->nullable();
            $table->string('status', 20)->default('a_relire');
            $table->foreignId('recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('auto_published')->default(false);
            $table->timestamps();

            $table->unique(['household_id', 'paperless_document_id']);
        });

        Schema::create('recipe_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('normalized_label', 160)->unique();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('hits')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_aliases');
        Schema::dropIfExists('recipe_imports');
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('paperless_recipe_tag');
        });
    }
};
