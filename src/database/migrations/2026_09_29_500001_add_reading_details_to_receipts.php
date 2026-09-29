<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v0.5.2 : détails de lecture des tickets.
 * Ligne : remarque de lecture (pesée illisible, montant déduit, lecture corrigée), non alimentaire, rubrique (Leclerc Drive).
 * Ticket : nombre de lignes annoncé (« Nombre de lignes: 32 ») et lignes que l'OCR n'a pas su lire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipt_lines', function (Blueprint $table) {
            $table->string('note', 120)->nullable()->after('vat_rate');
            $table->boolean('non_food')->default(false)->after('note');
            $table->string('section', 60)->nullable()->after('non_food');
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->unsignedSmallInteger('expected_lines')->nullable()->after('total_cents');
            $table->json('unread_lines')->nullable()->after('expected_lines');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn(['expected_lines', 'unread_lines']);
        });

        Schema::table('receipt_lines', function (Blueprint $table) {
            $table->dropColumn(['note', 'non_food', 'section']);
        });
    }
};
