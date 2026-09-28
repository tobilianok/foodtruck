<?php

namespace App\Support;

use App\Models\Recipe;

/**
 * Coût estimé d'une recette : quantité réellement utilisée × meilleur prix connu ramené à l'unité.
 * (Estimation « au prorata » : l'achat réel par conditionnements sera calculé par la liste de courses.)
 *
 * Relations attendues : ingredients.ingredient.packs.prices
 */
class RecipeCost
{
    /** @return array{total_cents: float, missing: array<int, string>, complete: bool} */
    public static function compute(Recipe $recipe, float $factor = 1): array
    {
        $total = 0.0;
        $missing = [];

        foreach ($recipe->ingredients as $line) {
            $ingredient = $line->ingredient;

            // « selon goût » et ingrédients facultatifs : non comptés
            if ($line->quantity === null || $line->unit === null || $line->is_optional) {
                continue;
            }

            // Produit de base sans conditionnement (l'eau du robinet) : gratuit
            if ($ingredient->packs->isEmpty() && $ingredient->is_staple) {
                continue;
            }

            $base = $line->baseQuantity($factor);
            $best = $ingredient->bestOffer();

            if ($base === null || $best === null) {
                $missing[] = $ingredient->name;

                continue;
            }

            $total += $base * $best['per_reference_cents'] / Units::referenceFactor($ingredient->base_unit);
        }

        return ['total_cents' => $total, 'missing' => array_values(array_unique($missing)), 'complete' => $missing === []];
    }

    /** Coût par unité de rendement (par personne, par pot, par pièce…). */
    public static function perYield(Recipe $recipe, array $cost): ?float
    {
        if ($recipe->yield_quantity <= 0) {
            return null;
        }

        // Rendement au poids : coût pour 100 g
        $divisor = $recipe->yield_unit === 'grammes' ? $recipe->yield_quantity / 100 : $recipe->yield_quantity;

        return $cost['total_cents'] / $divisor;
    }

    public static function isCheap(Recipe $recipe, array $cost): bool
    {
        $per = self::perYield($recipe, $cost);

        return $recipe->yield_unit === 'personnes' && $cost['complete'] && $per !== null && $per <= Recipe::CHEAP_PER_PERSON_CENTS;
    }
}
