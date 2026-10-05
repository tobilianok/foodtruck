<?php

namespace App\Support;

use App\Models\Household;
use App\Models\Ingredient;
use App\Models\MealPlanEntry;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calcule la liste de courses d'une période du planning.
 *
 * - Plats cuisinés de la période (les restes ne sont jamais recomptés : ils viennent d'un plat déjà cuisiné).
 * - Quantités de chaque ingrédient additionnées dans l'unité de base, puis traduites en conditionnements entiers.
 * - Magasin : principal par défaut, magasin des fruits et légumes pour ce rayon ; à défaut de prix, le moins cher qui en a un.
 * - Produits de base (sel, huile, farine…) : à vérifier chez soi, hors budget.
 *
 * Recalculable à volonté : les choix de la famille (articles cochés, magasin ou section choisis à la main)
 * et les articles ajoutés à la main sont conservés.
 */
class ShoppingListBuilder
{
    public const RELATIONS = ['recipe.ingredients.ingredient.aisle', 'recipe.ingredients.ingredient.packs.prices'];

    /** Plats à acheter pour cette période, hors repas écartés. @return Collection<int, MealPlanEntry> */
    public static function entries(ShoppingList $list, bool $withExcluded = false): Collection
    {
        $household = $list->household;

        $entries = $household->mealPlanEntries()
            ->with(self::RELATIONS)
            ->where('kind', MealPlanEntry::KIND_RECIPE)->whereNotNull('recipe_id')->where('is_frozen', false)
            ->whereDate('date', '>=', $list->date_from->toDateString())->whereDate('date', '<=', $list->date_to->toDateString())
            ->orderBy('date')->orderBy('position')->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            $entry->setRelation('household', $household);
        }

        $excluded = array_map('intval', $list->excluded_entry_ids ?? []);

        return $withExcluded ? $entries : $entries->reject(fn (MealPlanEntry $e) => in_array($e->id, $excluded, true))->values();
    }

    /** Empreinte du planning concerné : change dès qu'un repas ou une recette change. */
    public static function signature(ShoppingList $list): string
    {
        $entries = self::entries($list);
        $payload = $entries->map(fn (MealPlanEntry $e) => [$e->id, $e->recipe_id, $e->updated_at?->timestamp, $e->recipe?->updated_at?->timestamp])->all();

        // Le stock fait partie de l'empreinte : le modifier invite à mettre la liste à jour
        $stock = Pantry::available($list->household, $list->date_from);
        ksort($stock);

        return substr(sha1(json_encode([$payload, $stock, $list->date_from->toDateString(), $list->date_to->toDateString()])), 0, 40);
    }

    public static function isStale(ShoppingList $list): bool
    {
        return $list->signature !== self::signature($list);
    }

    /** Recalcule les articles issus des recettes, en gardant les choix de la famille et les ajouts manuels. */
    public static function rebuild(ShoppingList $list): void
    {
        $household = $list->household->loadMissing('members', 'mainStore', 'produceStore');
        $list->setRelation('household', $household);

        $entries = self::entries($list);
        $needs = self::needs($entries, $household);
        $stock = Pantry::available($household, $list->date_from);
        $stores = Store::active();
        $storeIds = $stores->pluck('id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($list, $household, $needs, $stock, $storeIds) {
            $existing = $list->items()->where('source', ShoppingListItem::SOURCE_RECIPE)->get()->keyBy('ingredient_id');

            foreach ($needs as $ingredientId => $need) {
                /** @var Ingredient $ingredient */
                $ingredient = $need['ingredient'];
                $item = $existing->get($ingredientId) ?? new ShoppingListItem(['shopping_list_id' => $list->id, 'source' => ShoppingListItem::SOURCE_RECIPE]);

                // Stock : déduit du besoin, sauf si la famille a choisi d'acheter quand même (ou a fixé la section à la main)
                $needed = $need['needed'];
                $inStock = ! $item->stock_ignored && ! $item->section_locked ? (float) ($stock[$ingredient->id] ?? 0.0) : 0.0;
                $stockUsed = $needed !== null ? min($needed, $inStock) : null;
                $net = $needed !== null ? max(0.0, $needed - $inStock) : null;
                $covered = $inStock > 0 && ($needed === null || $net <= 0.0001);

                // Magasin choisi à la main : conservé tant qu'il existe encore
                $locked = (bool) $item->store_locked && in_array((int) $item->store_id, $storeIds, true);
                $storeId = $locked ? (int) $item->store_id : self::chooseStore($ingredient, $net, $household, $storeIds);

                $item->fill($covered
                    ? ['store_id' => $storeId, 'purchase' => null, 'estimated_cents' => null, 'used_cents' => null, 'best_store_id' => null, 'best_cents' => null]
                    : self::priced($ingredient, $net, $storeId, $storeIds));
                $item->fill([
                    'ingredient_id' => $ingredient->id,
                    'label' => $ingredient->name,
                    'aisle_id' => $ingredient->aisle_id,
                    'store_locked' => $locked,
                    'needed_base' => $needed,
                    'stock_base' => $stockUsed !== null && $stockUsed > 0 ? round($stockUsed, 3) : null,
                    'base_unit' => $ingredient->base_unit,
                    'uses' => $need['uses'],
                    'note' => $need['note'],
                ]);

                if (! $item->section_locked) {
                    $item->section = match (true) {
                        $covered => ShoppingListItem::SECTION_STOCK,
                        (bool) $ingredient->is_staple => ShoppingListItem::SECTION_CHECK,
                        default => ShoppingListItem::SECTION_BUY,
                    };
                }

                $item->save();
                $existing->forget($ingredientId);
            }

            // Ingrédients qui ne sont plus nécessaires (repas retiré ou écarté)
            foreach ($existing as $obsolete) {
                $obsolete->delete();
            }

            $list->forceFill(['signature' => self::signature($list)])->save();
            $list->bump();
        });
    }

    /**
     * Besoins cumulés par ingrédient.
     *
     * @param  Collection<int, MealPlanEntry>  $entries
     * @return array<int, array{ingredient: Ingredient, needed: ?float, uses: array<int, array{title: string, quantity: string}>, note: ?string}>
     */
    public static function needs(Collection $entries, Household $household): array
    {
        $needs = [];

        foreach ($entries as $entry) {
            $serving = RecipeServing::for($entry->recipe, $household, $entry->servingInput());

            foreach ($entry->recipe->ingredients as $line) {
                if ($line->is_optional) {
                    continue;
                }

                $ingredient = $line->ingredient;

                // L'eau du robinet et autres produits de base sans conditionnement ne s'achètent pas
                if ($ingredient->is_staple && $ingredient->packs->isEmpty()) {
                    continue;
                }

                $need = &$needs[$ingredient->id];
                $need ??= ['ingredient' => $ingredient, 'base' => 0.0, 'counted' => false, 'open' => [], 'uses' => []];

                $label = $line->quantityLabel($serving->factor);
                $base = $line->baseQuantity($serving->factor);

                if ($base !== null) {
                    $need['base'] += $base;
                    $need['counted'] = true;
                } elseif ($line->quantity !== null && $line->unit !== null) {
                    // Unité non convertible (cuillères sans densité…) : on le signale, on ne devine pas
                    $need['open'][] = $label.' ('.$entry->recipe->title.')';
                }

                $need['uses'][$entry->recipe->title][] = $label;
                unset($need);
            }
        }

        $result = [];
        foreach ($needs as $id => $need) {
            $uses = [];
            foreach ($need['uses'] as $title => $labels) {
                $labels = array_values(array_diff($labels, ['selon goût'])) ?: ['selon goût'];
                $uses[] = ['title' => $title, 'quantity' => implode(' + ', $labels)];
            }

            $result[$id] = [
                'ingredient' => $need['ingredient'],
                'needed' => $need['counted'] ? round($need['base'], 3) : null,
                'uses' => $uses,
                'note' => $need['open'] === [] ? null : mb_substr('Quantité à estimer : '.implode(', ', $need['open']), 0, 160),
            ];
        }

        return $result;
    }

    /** Magasin habituel pour cet ingrédient : primeur pour les fruits et légumes, magasin principal sinon, puis le moins cher qui a un prix. */
    public static function chooseStore(Ingredient $ingredient, ?float $needed, Household $household, array $storeIds): ?int
    {
        $isProduce = $ingredient->aisle?->slug === 'fruits-legumes';
        $preferred = array_values(array_filter([
            $isProduce ? $household->produce_store_id : $household->main_store_id,
            $household->main_store_id,
            $household->produce_store_id,
        ], fn ($id) => $id !== null && in_array((int) $id, $storeIds, true)));

        foreach (array_unique($preferred) as $storeId) {
            if (PackPlanner::plan($ingredient, $needed, (int) $storeId) !== null) {
                return (int) $storeId;
            }
        }

        $cheapest = PackPlanner::cheapest($ingredient, $needed, $storeIds);

        // Aucun prix connu : l'article reste rattaché au magasin habituel, prix inconnu
        return $cheapest['store_id'] ?? (isset($preferred[0]) ? (int) $preferred[0] : ($storeIds[0] ?? null));
    }

    /**
     * Conditionnements, prix et meilleure alternative pour un magasin donné.
     *
     * @return array<string, mixed>
     */
    public static function priced(Ingredient $ingredient, ?float $needed, ?int $storeId, array $storeIds): array
    {
        $plan = $storeId !== null ? PackPlanner::plan($ingredient, $needed, $storeId) : null;
        $best = PackPlanner::cheapest($ingredient, $needed, $storeIds);

        return [
            'store_id' => $storeId,
            'purchase' => $plan['parts'] ?? null,
            'estimated_cents' => $plan['cents'] ?? null,
            'used_cents' => $storeId !== null ? PackPlanner::usedCents($ingredient, $needed, $storeId) : null,
            'best_store_id' => $best['store_id'] ?? null,
            'best_cents' => $best['cents'] ?? null,
        ];
    }

    /** Réécrit le prix d'un article quand on le déplace dans un autre magasin. */
    public static function moveToStore(ShoppingListItem $item, int $storeId): void
    {
        $storeIds = Store::active()->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($item->ingredient_id === null) {
            $item->update(['store_id' => $storeId, 'store_locked' => true]);

            return;
        }

        $ingredient = Ingredient::with('packs.prices')->findOrFail($item->ingredient_id);
        $item->fill(self::priced($ingredient, $item->needed_base, $storeId, $storeIds));
        $item->store_locked = true;
        $item->save();
    }
}
