<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Equipment extends Model
{
    protected $table = 'equipment';

    protected $fillable = ['name', 'slug', 'is_default', 'position', 'created_by'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public static function ordered()
    {
        return static::query()->orderBy('position')->orderBy('name')->get();
    }

    /**
     * Retrouve un appareil par son nom (sans tenir compte des accents ni de la casse)
     * ou l'ajoute à la liste commune.
     */
    public static function findOrCreateByName(string $name, ?int $userId): ?self
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $slug = Str::slug($name);

        if ($slug === '' || mb_strlen($name) > 60) {
            return null;
        }

        return static::firstOrCreate(['slug' => $slug], [
            'name' => Str::ucfirst($name),
            'is_default' => false,
            'created_by' => $userId,
        ]);
    }
}
