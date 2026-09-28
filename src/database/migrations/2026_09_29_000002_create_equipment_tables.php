<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * v0.2.0 : appareils de cuisine (liste commune, extensible) et appareils de chaque foyer.
 * Le slug servira à relier les recettes aux appareils requis (v0.4.0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 60)->unique();
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('position')->default(1000);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('household_equipment', function (Blueprint $table) {
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->primary(['household_id', 'equipment_id']);
        });

        $defaults = [
            'four' => 'Four',
            'plaques' => 'Plaques de cuisson',
            'micro-ondes' => 'Micro-ondes',
            'air-fryer' => 'Air fryer',
            'companion' => 'Companion Moulinex',
            'cookeo' => 'Cookeo',
            'yaourtiere' => 'Yaourtière',
            'robot-patissier' => 'Robot pâtissier',
            'blender' => 'Blender',
            'mixeur-plongeant' => 'Mixeur plongeant',
            'machine-a-pain' => 'Machine à pain',
            'autocuiseur' => 'Autocuiseur (cocotte-minute)',
            'congelateur' => 'Congélateur',
            'barbecue-plancha' => 'Barbecue / plancha',
        ];

        $now = now();
        $position = 10;
        foreach ($defaults as $slug => $name) {
            DB::table('equipment')->insert([
                'name' => $name,
                'slug' => $slug,
                'is_default' => true,
                'position' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $position += 10;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('household_equipment');
        Schema::dropIfExists('equipment');
    }
};
