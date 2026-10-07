<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.19.0 : tickets lus par le modèle de vision (Ollama sur le PC de Louis), envoyés un par un après une page de
 * contrôle, comme les fiches de recettes. État de l'envoi, avancement (barre de progression) et réponse du modèle
 * (relue par Receipts\VisionReceiptParser sans renvoyer le ticket à l'IA).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->string('vision_status', 12)->nullable()->after('status');
            $table->string('vision_error', 255)->nullable()->after('vision_status');
            $table->unsignedTinyInteger('vision_progress')->nullable()->after('vision_error');
            $table->string('vision_step', 120)->nullable()->after('vision_progress');
            $table->timestamp('vision_started_at')->nullable()->after('vision_step');
            $table->longText('vision')->nullable()->after('vision_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn(['vision_status', 'vision_error', 'vision_progress', 'vision_step', 'vision_started_at', 'vision']);
        });
    }
};
