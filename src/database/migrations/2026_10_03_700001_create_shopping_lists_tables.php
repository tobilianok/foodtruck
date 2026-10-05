<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v0.8.0 : liste de courses.
 * Une liste couvre une période du planning. Ses articles sont recalculés depuis le planning
 * (quantités cumulées, conditionnements entiers, magasin), en gardant ce que la famille a décidé :
 * articles cochés, magasin choisi à la main, produit de base déplacé entre « à acheter » et « à vérifier ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            // Repas du planning écartés de cette liste (ex. repas chez des amis)
            $table->json('excluded_entry_ids')->nullable();
            // Empreinte du planning au moment du calcul : détecte un planning modifié depuis
            $table->string('signature', 40)->nullable();
            // Incrémenté quand la liste change de forme (pas à chaque case cochée)
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['household_id', 'archived_at']);
        });

        Schema::create('shopping_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 120);
            $table->foreignId('aisle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 10)->default('recette'); // recette | manuel
            $table->string('section', 10)->default('achat');  // achat | verifier (produit de base à contrôler chez soi)
            $table->boolean('section_locked')->default(false);
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('store_locked')->default(false);
            $table->decimal('needed_base', 12, 3)->nullable();
            $table->string('base_unit', 10)->nullable();
            $table->string('quantity_text', 60)->nullable();
            $table->json('purchase')->nullable();     // [{pack_id, label, count, price_cents}]
            $table->unsignedInteger('estimated_cents')->nullable(); // prix en caisse (conditionnements entiers)
            $table->unsignedInteger('used_cents')->nullable();      // part réellement utilisée par les recettes
            $table->foreignId('best_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->unsignedInteger('best_cents')->nullable();
            $table->json('uses')->nullable();         // [{title, quantity}]
            $table->string('note', 160)->nullable();
            $table->boolean('is_checked')->default(false);
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['shopping_list_id', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_list_items');
        Schema::dropIfExists('shopping_lists');
    }
};
