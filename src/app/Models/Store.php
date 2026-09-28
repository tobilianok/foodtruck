<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    public const KINDS = [
        'drive' => 'Drive',
        'supermarche' => 'Supermarché',
        'discount' => 'Discount',
        'primeur' => 'Primeur',
        'frais' => 'Produits frais',
    ];

    protected $fillable = ['name', 'slug', 'kind', 'position', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    public static function active()
    {
        return static::query()->where('is_active', true)->orderBy('position')->orderBy('name')->get();
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
