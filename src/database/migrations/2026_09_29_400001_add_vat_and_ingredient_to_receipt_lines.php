<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v0.5.1 : taux de TVA de la ligne (20 % = probablement non alimentaire)
 * et ingrédient proposé quand aucun conditionnement ne correspond à la quantité du ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipt_lines', function (Blueprint $table) {
            $table->decimal('vat_rate', 4, 2)->nullable()->after('discount_cents');
            $table->foreignId('ingredient_id')->nullable()->after('status')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('receipt_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ingredient_id');
            $table->dropColumn('vat_rate');
        });
    }
};
