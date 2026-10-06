<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.18.0 : lecture des fiches par le modèle de vision : avancement (barre de progression dans « Fiches Paperless »)
 * et nouvelles tentatives automatiques en cas d'échec (srv-nas redémarré…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_imports', function (Blueprint $table) {
            $table->unsignedTinyInteger('layout_progress')->nullable()->after('layout_error');
            $table->string('layout_step', 120)->nullable()->after('layout_progress');
            $table->timestamp('layout_started_at')->nullable()->after('layout_step');
            $table->unsignedSmallInteger('layout_attempts')->default(0)->after('layout_started_at');
            $table->timestamp('layout_retry_at')->nullable()->after('layout_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('recipe_imports', function (Blueprint $table) {
            $table->dropColumn(['layout_progress', 'layout_step', 'layout_started_at', 'layout_attempts', 'layout_retry_at']);
        });
    }
};
