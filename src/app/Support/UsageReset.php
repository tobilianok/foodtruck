<?php

namespace App\Support;

use App\Models\MealPlanEntry;
use App\Models\PantryItem;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Illuminate\Support\Facades\DB;

/**
 * Remise à zéro des données d'USAGE (planning, listes de courses, stock) pour repartir propre après des essais.
 *
 * Conservé : comptes, foyers et leurs réglages (membres, semaine type, Paperless, budget), recettes,
 * référentiel (ingrédients, conditionnements, magasins), tickets de caisse et prix.
 * Tout ce qui compte pour les prix reste donc en place.
 */
class UsageReset
{
    /** @return array<string, int> libellé => nombre de lignes concernées */
    public static function counts(?int $householdId = null): array
    {
        $scope = fn ($query) => $householdId === null ? $query : $query->where('household_id', $householdId);

        $listIds = $scope(ShoppingList::query())->pluck('id');

        return [
            'Repas du planning' => $scope(MealPlanEntry::query())->count(),
            'Listes de courses' => $listIds->count(),
            'Articles de listes' => ShoppingListItem::whereIn('shopping_list_id', $listIds)->count(),
            'Lots de stock' => $scope(PantryItem::query())->count(),
        ];
    }

    public static function run(?int $householdId = null): void
    {
        $scope = fn ($query) => $householdId === null ? $query : $query->where('household_id', $householdId);

        DB::transaction(function () use ($scope) {
            $listIds = $scope(ShoppingList::query())->pluck('id');
            ShoppingListItem::whereIn('shopping_list_id', $listIds)->delete();
            ShoppingList::whereIn('id', $listIds)->delete();

            $scope(PantryItem::query())->delete();

            // Les restes pointent vers le plat d'origine : on les retire d'abord
            $scope(MealPlanEntry::query())->whereNotNull('source_entry_id')->delete();
            $scope(MealPlanEntry::query())->delete();
        });
    }
}
