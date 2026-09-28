<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * v0.2.0 : foyers, membres (coefficients de portion), rattachement des comptes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->unsignedInteger('weekly_budget_cents')->default(10000);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('household_id')->nullable()->after('role')->constrained()->nullOnDelete();
            $table->string('household_role', 20)->nullable()->after('household_id');
        });

        Schema::create('household_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('category', 20);
            $table->decimal('portion_coefficient', 3, 2);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['household_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_members');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('household_id');
            $table->dropColumn('household_role');
        });

        Schema::dropIfExists('households');
    }
};
