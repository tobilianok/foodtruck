<?php

namespace App\Models;

use App\Support\Units;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Unité propre à un ingrédient : « 1 sachet = 10 g », « 1 gousse = 5 g », « 1 boîte = 400 ml ». */
class IngredientUnit extends Model
{
    protected $fillable = ['ingredient_id', 'slug', 'name', 'plural', 'quantity', 'is_estimate'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'is_estimate' => 'boolean',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** Code enregistré dans une ligne de recette. */
    public function code(): string
    {
        return Units::CUSTOM_PREFIX.$this->slug;
    }

    public function label(float $quantity = 1): string
    {
        return $quantity > 1 && $this->plural ? $this->plural : $this->name;
    }

    /** « 1 sachet = 10 g » */
    public function equivalence(string $baseUnit): string
    {
        return '1 '.$this->name.' = '.Units::format($this->quantity, $baseUnit);
    }
}
