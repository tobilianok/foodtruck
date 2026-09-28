<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Comptes liés à Authentik : identifiant OIDC (sub), rôle, dernière connexion.
 * Le mot de passe local devient facultatif (la connexion passe par Authentik).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('authentik_sub')->nullable()->unique()->after('id');
            $table->string('username')->nullable()->after('name');
            $table->string('role', 20)->default('membre')->after('email');
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['authentik_sub']);
            $table->dropColumn(['authentik_sub', 'username', 'role', 'last_login_at']);
        });
    }
};
