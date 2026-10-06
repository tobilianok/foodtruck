<?php

namespace App\Support;

use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\Price;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import du jeu d'ingrédients de départ (database/data/ingredients.php).
 * N'ajoute que les ingrédients absents : ce qui a été corrigé dans l'appli n'est jamais écrasé.
 */
class ReferenceImporter
{
    public const ESTIMATE_DATE = '2026-09-01';

    /** @return array{ingredients: int, packs: int, prices: int, skipped: int} */
    public static function import(?string $file = null): array
    {
        $rows = require $file ?? database_path('data/ingredients.php');
        $aisles = Aisle::pluck('id', 'slug');
        $stores = Store::pluck('id', 'slug');
        $counts = ['ingredients' => 0, 'packs' => 0, 'prices' => 0, 'skipped' => 0];

        DB::transaction(function () use ($rows, $aisles, $stores, &$counts) {
            foreach ($rows as [$name, $aisle, $baseUnit, $pieceWeight, $density, $months, $fresh, $staple, $packs]) {
                $slug = Str::slug($name);

                if (Ingredient::where('slug', $slug)->exists()) {
                    $counts['skipped']++;

                    continue;
                }

                if (! isset($aisles[$aisle]) || ! array_key_exists($baseUnit, Units::BASE_CHOICES)) {
                    throw new RuntimeException("Ligne invalide pour « {$name} » (rayon {$aisle}, unité {$baseUnit}).");
                }

                $ingredient = Ingredient::create([
                    'name' => $name,
                    'slug' => $slug,
                    'aisle_id' => $aisles[$aisle],
                    'base_unit' => $baseUnit,
                    'piece_weight_g' => $pieceWeight,
                    'density' => $density,
                    'season_months' => $months,
                    'is_fresh' => $fresh,
                    'is_staple' => $staple,
                ]);
                $counts['ingredients']++;

                foreach (array_values($packs) as $position => [$label, $quantity, $bulk, $prices]) {
                    $pack = $ingredient->packs()->create([
                        'label' => $label,
                        'quantity' => $quantity,
                        'is_bulk' => $bulk,
                        'position' => ($position + 1) * 10,
                    ]);
                    $counts['packs']++;

                    foreach ($prices as $storeSlug => $cents) {
                        if (! isset($stores[$storeSlug])) {
                            throw new RuntimeException("Magasin inconnu « {$storeSlug} » pour « {$name} ».");
                        }

                        Price::create([
                            'ingredient_pack_id' => $pack->id,
                            'store_id' => $stores[$storeSlug],
                            'price_cents' => $cents,
                            'source' => 'estimation',
                            'observed_on' => self::ESTIMATE_DATE,
                        ]);
                        $counts['prices']++;
                    }
                }

                // v0.16.0 : unités courantes (gousse, botte, sachet…) pré-remplies avec des valeurs typiques
                TypicalUnits::seed($ingredient);
            }
        });

        return $counts;
    }
}
