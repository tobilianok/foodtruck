<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v0.1.1 : un compte Foodtruck est identifié par son identifiant Authentik
 * (authentik_sub), pas par son e-mail. Deux comptes Authentik peuvent donc
 * partager la même adresse (ex. akadmin et un compte personnel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['email']);
            $table->unique('email');
        });
    }
};
