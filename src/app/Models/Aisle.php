<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Aisle extends Model
{
    protected $fillable = ['name', 'slug', 'position'];

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class)->orderBy('name');
    }

    public static function ordered()
    {
        return static::query()->orderBy('position')->orderBy('name')->get();
    }
}
