<?php

namespace App\Models;

use App\Support\UnitConversionException;
use App\Support\Units;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeIngredient extends Model
{
    public $timestamps = false;

    protected $fillable = ['recipe_id', 'position', 'group_label', 'ingredient_id', 'quantity', 'unit', 'note', 'is_optional'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'is_optional' => 'boolean',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** Quantité dans l'unité de base de l'ingrédient (null si « selon goût » ou non convertible). */
    public function baseQuantity(float $factor = 1): ?float
    {
        if ($this->quantity === null || $this->unit === null) {
            return null;
        }

        try {
            return Units::toBase($this->quantity * $factor, $this->unit, $this->ingredient);
        } catch (UnitConversionException) {
            return null;
        }
    }

    public function quantityLabel(float $factor = 1): string
    {
        if ($this->quantity === null || $this->unit === null) {
            return 'selon goût';
        }

        return Units::quantityLabel($this->quantity * $factor, $this->unit);
    }

    /** Équivalence dans l'unité de base quand la dimension diffère (« 2 pièces » → « ≈ 250 g »). */
    public function equivalentLabel(float $factor = 1): ?string
    {
        if ($this->unit === null || Units::dimension($this->unit) === Units::dimension($this->ingredient->base_unit)) {
            return null;
        }

        $base = $this->baseQuantity($factor);

        return $base === null ? null : '≈ '.Units::format($base, $this->ingredient->base_unit);
    }
}
