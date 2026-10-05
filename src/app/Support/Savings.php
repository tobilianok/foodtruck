<?php

namespace App\Support;

use App\Models\Household;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Plats à remplacer quand le budget de la semaine est entamé à 80 % ou plus.
 *
 * Pour les plats encore à cuisiner les plus chers, propose des recettes de la même catégorie, publiées,
 * pas déjà au planning de la semaine, dont le coût (prix complets connus) est nettement plus bas
 * pour le même nombre de convives.
 */
class Savings
{
    /** À partir de quelle part du budget on propose des remplacements. */
    public const THRESHOLD = 0.8;

    private const MAX_DISHES = 3;

    private const MAX_OPTIONS = 3;

    /** Économie minimale pour que le remplacement vaille la peine : 50 centimes et 10 % du plat. */
    private const MIN_SAVING_CENTS = 50;

    private const MIN_SAVING_RATIO = 0.10;

    /**
     * @param  Collection<int, MealPlanEntry>  $entries  entrées de la semaine (recipe.ingredients.ingredient.packs.prices chargés)
     * @param  array{total_cents: float, per_entry: array<int, float>}  $cost
     * @return array<int, array{entry: MealPlanEntry, cost_cents: float, options: array<int, array{recipe: Recipe, cost_cents: float, saving_cents: float}>}>
     */
    public static function replacements(Household $household, User $user, Collection $entries, array $cost, string $today): array
    {
        if ($household->weekly_budget_cents <= 0 || $cost['total_cents'] < $household->weekly_budget_cents * self::THRESHOLD) {
            return [];
        }

        $dishes = $entries
            ->filter(fn (MealPlanEntry $e) => $e->isRecipe() && $e->batch_quantity === null && $e->recipe?->yield_unit === 'personnes'
                && $e->date->toDateString() >= $today && ($cost['per_entry'][$e->id] ?? 0) > 0)
            ->sortByDesc(fn (MealPlanEntry $e) => $cost['per_entry'][$e->id])
            ->take(self::MAX_DISHES);

        if ($dishes->isEmpty()) {
            return [];
        }

        $planned = $entries->pluck('recipe_id')->filter()->all();
        $pool = Recipe::visibleTo($user)->where('status', Recipe::STATUS_PUBLISHED)->where('yield_unit', 'personnes')
            ->whereIn('category', $dishes->map(fn ($e) => $e->recipe->category)->unique()->all())
            ->whereNotIn('id', $planned)
            ->with('ingredients.ingredient.packs.prices')->get();

        $result = [];
        foreach ($dishes as $entry) {
            $current = (float) $cost['per_entry'][$entry->id];
            $options = [];

            foreach ($pool->where('category', $entry->recipe->category) as $recipe) {
                $alternative = RecipeCost::compute($recipe, RecipeServing::for($recipe, $household, $entry->servingInput(), $entry->date)->factor);
                if (! $alternative['complete']) {
                    continue;
                }

                $saving = $current - $alternative['total_cents'];
                if ($saving >= self::MIN_SAVING_CENTS && $saving >= $current * self::MIN_SAVING_RATIO) {
                    $options[] = ['recipe' => $recipe, 'cost_cents' => $alternative['total_cents'], 'saving_cents' => $saving];
                }
            }

            if ($options !== []) {
                usort($options, fn ($a, $b) => $b['saving_cents'] <=> $a['saving_cents']);
                $result[] = ['entry' => $entry, 'cost_cents' => $current, 'options' => array_slice($options, 0, self::MAX_OPTIONS)];
            }
        }

        return $result;
    }
}
