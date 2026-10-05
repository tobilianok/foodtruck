<?php

namespace App\Support;

use App\Models\Household;
use App\Models\PantryItem;
use App\Models\Recipe;
use App\Models\User;

/**
 * « Que cuisiner ? » : recettes classées selon ce qu'il y a déjà en stock.
 * Les produits qui expirent bientôt font remonter les recettes qui les utilisent.
 */
class AntiWaste
{
    /** Produits de base (sel, huile…) supposés à la maison : ils ne comptent ni pour ni contre une recette. */
    private static function counts(\App\Models\RecipeIngredient $line): bool
    {
        return ! $line->is_optional && $line->quantity !== null && $line->unit !== null
            && ! ($line->ingredient->is_staple);
    }

    /** Lots périmant dans les prochains jours (ou déjà périmés), du plus pressé au moins pressé. */
    public static function expiring(Household $household, int $days = PantryItem::SOON_DAYS): \Illuminate\Support\Collection
    {
        $limit = now('Europe/Paris')->addDays($days)->toDateString();

        return $household->pantryItems()->with('ingredient')
            ->whereNotNull('expires_on')->whereDate('expires_on', '<=', $limit)
            ->orderBy('expires_on')->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{recipe: Recipe, covered: int, total: int, partial: int, ratio: float, expiring: array<int, string>, have: array<int, array{name: string, soon: bool}>, missing: array<int, string>, missing_cents: float, factor: float}>
     */
    public static function suggestions(Household $household, User $user, int $limit = 24): \Illuminate\Support\Collection
    {
        $household->loadMissing('members');
        $stock = Pantry::available($household);
        if ($stock === []) {
            return collect();
        }

        // Produits qui expirent bientôt (et qui ne le sont pas déjà)
        $soon = self::expiring($household)->filter(fn (PantryItem $lot) => ! $lot->isExpired())->pluck('ingredient_id')->flip()->all();

        $results = [];
        $recipes = Recipe::visibleTo($user)->where('status', Recipe::STATUS_PUBLISHED)
            ->with('ingredients.ingredient.packs.prices')->get();

        foreach ($recipes as $recipe) {
            $factor = RecipeServing::for($recipe, $household, [])->factor;
            $lines = $recipe->ingredients->filter(fn ($line) => self::counts($line))->unique('ingredient_id');
            if ($lines->isEmpty()) {
                continue;
            }

            $covered = $partial = 0;
            $have = [];
            $missing = [];
            $missingCents = 0.0;
            $expiring = [];

            foreach ($lines as $line) {
                $ingredient = $line->ingredient;
                $available = (float) ($stock[$ingredient->id] ?? 0.0);
                $needed = $line->baseQuantity($factor);
                $enough = $available > 0 && ($needed === null || $available >= $needed * 0.98);

                if ($enough) {
                    $covered++;
                } elseif ($available > 0) {
                    $partial++;
                }

                if ($available > 0) {
                    $isSoon = isset($soon[$ingredient->id]);
                    $have[] = ['name' => $ingredient->name, 'soon' => $isSoon];
                    if ($isSoon) {
                        $expiring[] = $ingredient->name;
                    }
                }

                if (! $enough) {
                    $missing[] = $ingredient->name;
                    $short = $needed === null ? null : max(0.0, $needed - $available);
                    $offer = $ingredient->bestOffer();
                    if ($short !== null && $offer !== null) {
                        $missingCents += $short * $offer['per_reference_cents'] / Units::referenceFactor($ingredient->base_unit);
                    }
                }
            }

            // Au moins un ingrédient du stock, et une part raisonnable de la recette déjà couverte (ou un produit à finir)
            $ratio = $covered / $lines->count();
            if ($covered + $partial === 0 || ($ratio < 0.25 && $expiring === [])) {
                continue;
            }

            $results[] = [
                'recipe' => $recipe, 'covered' => $covered, 'total' => $lines->count(), 'partial' => $partial, 'ratio' => $ratio,
                'expiring' => $expiring, 'have' => $have, 'missing' => $missing, 'missing_cents' => $missingCents, 'factor' => $factor,
            ];
        }

        return collect($results)
            ->sort(fn ($a, $b) => [count($b['expiring']), $b['ratio'], $a['missing_cents']] <=> [count($a['expiring']), $a['ratio'], $b['missing_cents']])
            ->take($limit)->values();
    }
}
