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

    /**
     * Quantité affichée : telle que saisie pour la recette d'origine (facteur 1),
     * arrondie de façon pratique une fois proratisée (« 1,3 œuf » → « 1 pièce »).
     */
    public function quantityLabel(float $factor = 1): string
    {
        if ($this->quantity === null || $this->unit === null) {
            return 'selon goût';
        }

        if (abs($factor - 1) < 0.0001) {
            return Units::quantityLabel($this->quantity, $this->unit);
        }

        return Units::scaledLabel(Units::practical($this->quantity * $factor, $this->unit), $this->unit);
    }

    /** Valeur exacte du calcul quand l'arrondi pratique la modifie (affichée au survol), sinon null. */
    public function exactLabel(float $factor = 1): ?string
    {
        if ($this->quantity === null || $this->unit === null || abs($factor - 1) < 0.0001) {
            return null;
        }

        $exact = $this->quantity * $factor;

        return abs(Units::practical($exact, $this->unit) - $exact) < 0.005 ? null : 'calcul exact : '.Units::quantityLabel($exact, $this->unit);
    }

    /** Équivalence dans l'unité de base quand la dimension diffère (« 2 pièces » → « ≈ 250 g »). */
    public function equivalentLabel(float $factor = 1): ?string
    {
        if ($this->unit === null || Units::dimension($this->unit) === Units::dimension($this->ingredient->base_unit)) {
            return null;
        }

        // Équivalence calculée sur la quantité réellement utilisée (arrondie) : 1 œuf ≈ 60 g, pas 1,3
        $quantity = abs($factor - 1) < 0.0001 ? $this->quantity : Units::practical($this->quantity * $factor, $this->unit);

        try {
            $base = Units::toBase($quantity, $this->unit, $this->ingredient);
        } catch (UnitConversionException) {
            return null;
        }

        $baseUnit = $this->ingredient->base_unit;

        return '≈ '.Units::format(abs($factor - 1) < 0.0001 ? $base : Units::practical($base, $baseUnit), $baseUnit);
    }
}
