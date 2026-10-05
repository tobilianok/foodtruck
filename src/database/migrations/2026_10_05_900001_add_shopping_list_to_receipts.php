<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v0.9.1 : un ticket de caisse peut être rattaché à la liste de courses qu'il solde,
 * pour comparer ce qui a été payé à ce qui était estimé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->foreignId('shopping_list_id')->nullable()->after('store_id')->constrained('shopping_lists')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shopping_list_id');
        });
    }
};
