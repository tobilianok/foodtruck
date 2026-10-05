<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v0.10.0 : âge des membres.
 * Avec une date de naissance, le coefficient de portion suit l'âge à la date de chaque repas
 * (sauf coefficient réglé à la main). Sans date de naissance, le coefficient enregistré est conservé tel quel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('household_members', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('category');
            $table->boolean('coefficient_manual')->default(false)->after('portion_coefficient');
        });
    }

    public function down(): void
    {
        Schema::table('household_members', function (Blueprint $table) {
            $table->dropColumn(['birth_date', 'coefficient_manual']);
        });
    }
};
