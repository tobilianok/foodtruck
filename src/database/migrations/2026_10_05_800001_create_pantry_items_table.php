<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v0.9.0 : stock du foyer (placard, frigo, congélateur) et anti-gaspi.
 * Un lot = une quantité d'un ingrédient, dans son unité de base, avec une date limite facultative.
 * Les listes de courses déduisent le stock ; à la fin des courses, les restes d'emballages y entrent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pantry_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->date('expires_on')->nullable();
            $table->string('location', 12)->default('placard');
            $table->string('note', 80)->nullable();
            $table->string('source', 10)->default('manuel'); // manuel | courses
            $table->foreignId('shopping_list_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['household_id', 'ingredient_id']);
            $table->index(['household_id', 'expires_on']);
        });

        Schema::table('shopping_list_items', function (Blueprint $table) {
            // Part du besoin couverte par le stock (unité de base) ; stock ignoré pour cet article si la case est cochée
            $table->decimal('stock_base', 12, 3)->nullable()->after('needed_base');
            $table->boolean('stock_ignored')->default(false)->after('stock_base');
        });

        Schema::table('shopping_lists', function (Blueprint $table) {
            // Posé quand la fin des courses a mis à jour le stock : évite de le faire deux fois
            $table->timestamp('stock_applied_at')->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->dropColumn('stock_applied_at');
        });
        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->dropColumn(['stock_base', 'stock_ignored']);
        });
        Schema::dropIfExists('pantry_items');
    }
};
