<?php

namespace App\Support;

use App\Models\Household;
use App\Models\Ingredient;
use App\Models\PantryItem;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Carbon\CarbonInterface;

/**
 * Stock du foyer : ce qu'il y a déjà à la maison.
 *
 * - Les listes de courses déduisent le stock (non périmé) de leurs besoins.
 * - À la fin des courses : le stock utilisé par la liste est consommé (le plus proche de sa date limite d'abord),
 *   et les restes d'emballages achetés (40 cl de lait sur 1 L…) entrent en stock.
 */
class Pantry
{
    /** Rayons dont les produits vont au frigo ou au congélateur par défaut. */
    private const COLD_AISLES = ['cremerie', 'fromages', 'boucherie', 'poissonnerie', 'charcuterie-traiteur'];

    /** Emplacement habituel d'un ingrédient (modifiable à la main). */
    public static function locationFor(Ingredient $ingredient): string
    {
        $aisle = $ingredient->aisle?->slug ?? $ingredient->aisle()->value('slug');

        return match (true) {
            $aisle === 'surgeles' => 'congelateur',
            in_array($aisle, self::COLD_AISLES, true) => 'frigo',
            default => 'placard',
        };
    }

    /**
     * Quantité disponible par ingrédient (unité de base), hors lots périmés à cette date.
     *
     * @return array<int, float>
     */
    public static function available(Household $household, ?CarbonInterface $date = null): array
    {
        $day = ($date ?? now('Europe/Paris'))->toDateString();

        return $household->pantryItems()
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', $day))
            ->get(['ingredient_id', 'quantity'])
            ->groupBy('ingredient_id')
            ->map(fn ($lots) => (float) $lots->sum('quantity'))
            ->all();
    }

    /** Ajoute un lot ; une quantité de même ingrédient, emplacement et date limite s'additionne. */
    public static function add(Household $household, Ingredient $ingredient, float $quantity, ?string $expiresOn = null, ?string $location = null, string $source = 'manuel', ?int $listId = null, ?int $userId = null, ?string $note = null): PantryItem
    {
        $location = array_key_exists((string) $location, PantryItem::LOCATIONS) ? $location : self::locationFor($ingredient);

        $lot = $household->pantryItems()
            ->where('ingredient_id', $ingredient->id)->where('location', $location)
            ->when($expiresOn === null, fn ($q) => $q->whereNull('expires_on'), fn ($q) => $q->whereDate('expires_on', $expiresOn))
            ->first();

        if ($lot !== null) {
            $lot->update(['quantity' => round($lot->quantity + $quantity, 3)]);

            return $lot;
        }

        return $household->pantryItems()->create([
            'ingredient_id' => $ingredient->id,
            'quantity' => round($quantity, 3),
            'expires_on' => $expiresOn,
            'location' => $location,
            'note' => $note,
            'source' => $source,
            'shopping_list_id' => $listId,
            'created_by' => $userId,
        ]);
    }

    /** Retire une quantité du stock, en commençant par les lots qui expirent le plus tôt. Renvoie la quantité retirée. */
    public static function consume(Household $household, int $ingredientId, float $quantity, ?CarbonInterface $date = null): float
    {
        $day = ($date ?? now('Europe/Paris'))->toDateString();
        $left = $quantity;

        $lots = $household->pantryItems()->where('ingredient_id', $ingredientId)
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', $day))
            ->get()
            ->sortBy(fn (PantryItem $lot) => ($lot->expires_on?->toDateString() ?? '9999-12-31').'-'.str_pad((string) $lot->id, 10, '0', STR_PAD_LEFT));

        foreach ($lots as $lot) {
            if ($left <= 0.0001) {
                break;
            }
            $take = min($lot->quantity, $left);
            $remaining = round($lot->quantity - $take, 3);
            $remaining <= 0.0001 ? $lot->delete() : $lot->update(['quantity' => $remaining]);
            $left -= $take;
        }

        return round($quantity - max(0.0, $left), 3);
    }

    /** Un reste d'emballage vaut d'être gardé au-delà de 2 % du besoin (15 g ou ml au moins) ; une pièce entière suffit. */
    public static function isWorthKeeping(float $surplus, string $baseUnit, ?float $needed): bool
    {
        if ($baseUnit === 'piece') {
            return $surplus >= 0.5;
        }

        return $surplus >= max(15.0, ($needed ?? 0.0) * 0.02);
    }

    /**
     * Fin des courses : met le stock à jour d'après la liste (une seule fois par liste).
     *
     * @return array{consumed: int, added: int}
     */
    public static function applyList(ShoppingList $list, ?int $userId = null): array
    {
        if ($list->stock_applied_at !== null) {
            return ['consumed' => 0, 'added' => 0];
        }

        $household = $list->household;
        $consumed = 0;
        $added = 0;

        foreach ($list->items()->whereNotNull('ingredient_id')->get() as $item) {
            /** @var ShoppingListItem $item */
            $ingredient = Ingredient::with('aisle')->find($item->ingredient_id);
            if ($ingredient === null) {
                continue;
            }

            if (($item->stock_base ?? 0) > 0 && self::consume($household, $ingredient->id, $item->stock_base, $list->date_from) > 0) {
                $consumed++;
            }

            if ($item->section !== ShoppingListItem::SECTION_BUY || ! $item->is_checked || empty($item->purchase)) {
                continue;
            }

            $bought = (float) collect($item->purchase)->sum(fn (array $part) => (float) $part['quantity']);
            $used = $item->needed_base !== null ? max(0.0, $item->needed_base - ($item->stock_base ?? 0.0)) : 0.0;
            $surplus = round($bought - $used, 3);

            if (self::isWorthKeeping($surplus, $ingredient->base_unit, $item->needed_base)) {
                self::add($household, $ingredient, $surplus, null, null, 'courses', $list->id, $userId);
                $added++;
            }
        }

        $list->forceFill(['stock_applied_at' => now()])->save();

        return ['consumed' => $consumed, 'added' => $added];
    }
}
