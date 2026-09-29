<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v0.5.0 : tickets de caisse (Paperless ou saisie manuelle), lignes lues,
 * libellés mémorisés (alias) et réglages Paperless du foyer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->string('paperless_url')->nullable()->after('produce_store_id');
            $table->text('paperless_token')->nullable()->after('paperless_url');
            $table->string('paperless_tag', 80)->nullable()->after('paperless_token');
            $table->timestamp('paperless_synced_at')->nullable()->after('paperless_tag');
            $table->string('paperless_last_error', 500)->nullable()->after('paperless_synced_at');
        });

        Schema::table('stores', function (Blueprint $table) {
            // Autres noms rencontrés sur les tickets / correspondants Paperless (« E.Leclerc Drive Vichy »…)
            $table->json('receipt_names')->nullable()->after('kind');
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('source', 12);
            $table->unsignedBigInteger('paperless_document_id')->nullable();
            $table->timestamp('paperless_modified_at')->nullable();
            $table->string('title', 200)->nullable();
            $table->string('correspondent', 120)->nullable();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->date('purchased_on')->nullable();
            $table->integer('total_cents')->nullable();
            $table->longText('raw_text')->nullable();
            $table->string('status', 12)->default('a_valider');
            $table->boolean('auto_applied')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['household_id', 'paperless_document_id']);
            $table->index(['household_id', 'status']);
        });

        Schema::create('receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('kind', 10)->default('produit');
            $table->string('raw_label', 200);
            $table->string('normalized_label', 200);
            $table->decimal('quantity', 10, 3)->default(1);
            $table->string('quantity_unit', 6)->default('piece');
            $table->integer('unit_price_cents')->nullable();
            $table->integer('total_cents');
            $table->integer('discount_cents')->default(0);
            $table->string('status', 12)->default('a_associer');
            $table->foreignId('ingredient_pack_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('pack_price_cents')->nullable();
            $table->foreignId('price_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::create('receipt_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('normalized_label', 200);
            $table->foreignId('ingredient_pack_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_ignored')->default(false);
            $table->unsignedInteger('hits')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'normalized_label']);
            $table->index('normalized_label');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_aliases');
        Schema::dropIfExists('receipt_lines');
        Schema::dropIfExists('receipts');

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('receipt_names');
        });

        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn(['paperless_url', 'paperless_token', 'paperless_tag', 'paperless_synced_at', 'paperless_last_error']);
        });
    }
};
