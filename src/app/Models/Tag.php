<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    /** v0.22.0 : longueur d'un nom d'étiquette (colonne de 40 caractères). */
    public const NAME_MAX = 40;

    /** Étiquettes créées par le foyer : après celles de départ. */
    public const CUSTOM_POSITION = 1000;

    protected $fillable = ['name', 'slug', 'position'];

    /** v0.22.0 : étiquette de ce nom (« viandes » retrouve « Viandes »), créée au besoin. */
    public static function findOrCreateNamed(string $name): self
    {
        $name = \Illuminate\Support\Str::limit(trim($name), self::NAME_MAX, '');
        $slug = \Illuminate\Support\Str::limit(\Illuminate\Support\Str::slug($name), self::NAME_MAX, '');

        // Par l'adresse, ou par le nom (une étiquette de départ renommée garde son adresse : « Végétarien » = veggy)
        return static::where('slug', $slug)->first()
            ?? static::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
            ?? static::create(['slug' => $slug, 'name' => $name, 'position' => self::CUSTOM_POSITION]);
    }

    /** Étiquette de départ (veggy, végan… servent au menu automatique) : renommable, pas supprimable. */
    public function isBuiltIn(): bool
    {
        return $this->position < self::CUSTOM_POSITION;
    }

    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class);
    }

    public static function ordered()
    {
        return static::query()->orderBy('position')->orderBy('name')->get();
    }
}
