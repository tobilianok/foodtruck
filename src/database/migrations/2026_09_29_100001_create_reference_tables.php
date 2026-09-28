<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * v0.3.0 : référentiel - rayons, magasins, ingrédients, conditionnements, prix.
 * Les unités (g, kg, ml, cl, c. à soupe...) sont définies dans le code (App\Support\Units).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aisles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 60)->unique();
            $table->unsignedSmallInteger('position')->default(1000);
            $table->timestamps();
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 60)->unique();
            $table->string('kind', 20);
            $table->unsignedSmallInteger('position')->default(1000);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('households', function (Blueprint $table) {
            $table->foreignId('main_store_id')->nullable()->after('weekly_budget_cents')->constrained('stores')->nullOnDelete();
            $table->foreignId('produce_store_id')->nullable()->after('main_store_id')->constrained('stores')->nullOnDelete();
        });

        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->foreignId('aisle_id')->constrained()->restrictOnDelete();
            $table->string('base_unit', 10);
            $table->decimal('piece_weight_g', 8, 2)->nullable();
            $table->decimal('density', 6, 3)->nullable();
            $table->json('season_months')->nullable();
            $table->boolean('is_fresh')->default(false);
            $table->boolean('is_staple')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ingredient_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('label', 60);
            $table->decimal('quantity', 10, 2);
            $table->boolean('is_bulk')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_pack_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('price_cents');
            $table->string('source', 12);
            $table->boolean('is_promo')->default(false);
            $table->date('observed_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['ingredient_pack_id', 'store_id', 'observed_on']);
        });

        $now = now();

        $aisles = [
            'fruits-legumes' => 'Fruits et légumes',
            'boucherie' => 'Boucherie, volaille',
            'poissonnerie' => 'Poissonnerie',
            'cremerie' => 'Crèmerie (lait, œufs, beurre, crème)',
            'fromages' => 'Fromages',
            'charcuterie-traiteur' => 'Charcuterie, traiteur',
            'boulangerie' => 'Boulangerie',
            'epicerie-salee' => 'Épicerie salée (pâtes, riz, conserves)',
            'epicerie-sucree' => 'Épicerie sucrée (farine, sucre, chocolat)',
            'condiments' => 'Huiles, condiments, épices',
            'surgeles' => 'Surgelés',
            'boissons' => 'Boissons',
            'bebe' => 'Bébé',
            'maison' => 'Hygiène, entretien',
        ];
        $position = 10;
        foreach ($aisles as $slug => $name) {
            DB::table('aisles')->insert(['name' => $name, 'slug' => $slug, 'position' => $position, 'created_at' => $now, 'updated_at' => $now]);
            $position += 10;
        }

        $stores = [
            'leclerc-drive' => ['Leclerc Drive', 'drive'],
            'morin' => ['Morin Fruits et Légumes', 'primeur'],
            'lidl' => ['Lidl', 'discount'],
            'carrefour' => ['Carrefour', 'supermarche'],
            'hyper-u' => ['Hyper U', 'supermarche'],
            'grand-frais' => ['Grand Frais', 'frais'],
        ];
        $position = 10;
        foreach ($stores as $slug => [$name, $kind]) {
            DB::table('stores')->insert(['name' => $name, 'slug' => $slug, 'kind' => $kind, 'position' => $position, 'created_at' => $now, 'updated_at' => $now]);
            $position += 10;
        }

        // Foyers existants : Leclerc Drive en magasin principal, Morin pour les fruits et légumes (choix de Louis).
        DB::table('households')->update([
            'main_store_id' => DB::table('stores')->where('slug', 'leclerc-drive')->value('id'),
            'produce_store_id' => DB::table('stores')->where('slug', 'morin')->value('id'),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('prices');
        Schema::dropIfExists('ingredient_packs');
        Schema::dropIfExists('ingredients');

        Schema::table('households', function (Blueprint $table) {
            $table->dropConstrainedForeignId('produce_store_id');
            $table->dropConstrainedForeignId('main_store_id');
        });

        Schema::dropIfExists('stores');
        Schema::dropIfExists('aisles');
    }
};
