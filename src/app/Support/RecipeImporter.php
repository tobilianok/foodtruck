<?php

namespace App\Support;

use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\Recipe;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import du premier lot de recettes (database/data/recipes.php).
 * N'ajoute que les recettes absentes (repérées par leur slug) : rien n'est écrasé.
 */
class RecipeImporter
{
    /** @return array{recipes: int, skipped: int} */
    public static function import(?string $file = null): array
    {
        $rows = require $file ?? database_path('data/recipes.php');
        $ingredients = Ingredient::all()->keyBy('slug');
        $equipment = Equipment::pluck('id', 'slug');
        $counts = ['recipes' => 0, 'skipped' => 0];

        DB::transaction(function () use ($rows, $ingredients, $equipment, &$counts) {
            foreach ($rows as $row) {
                if (Recipe::where('slug', Str::slug($row['title']))->exists()) {
                    $counts['skipped']++;

                    continue;
                }

                self::importOne($row, $ingredients, $equipment);
                $counts['recipes']++;
            }
        });

        return $counts;
    }

    private static function importOne(array $row, Collection $ingredients, Collection $equipment): Recipe
    {
        $appliance = fn (string $slug) => $equipment[$slug] ?? throw new RuntimeException("« {$row['title']} » : appareil inconnu « {$slug} ».");

        $lines = [];
        foreach ($row['ingredients'] as $item) {
            [$ref, $quantity, $unit, $note, $optional, $group] = $item + [3 => null, 4 => false, 5 => null];
            $ingredient = $ingredients[$ref] ?? throw new RuntimeException("« {$row['title']} » : ingrédient inconnu « {$ref} ».");

            if ($quantity !== null) {
                Units::toBase($quantity, $unit, $ingredient); // lève une exception si la quantité n'est pas convertible
            }

            $lines[] = [
                'group_label' => $group,
                'ingredient_id' => $ingredient->id,
                'quantity' => $quantity,
                'unit' => $unit,
                'note' => $note,
                'is_optional' => (bool) $optional,
            ];
        }

        $steps = [];
        foreach ($row['steps'] as $item) {
            [$body, $timer, $device] = $item + [1 => null, 2 => null];
            $steps[] = [
                'body' => $body,
                'timer_minutes' => $timer,
                'equipment_id' => $device ? $appliance($device) : null,
            ];
        }

        [$prep, $cook, $rest] = $row['times'];

        $recipe = RecipeWriter::save(new Recipe, [
            'fields' => [
                'title' => $row['title'],
                'description' => $row['description'] ?? null,
                'category' => $row['category'],
                'yield_quantity' => $row['yield'][0],
                'yield_unit' => $row['yield'][1],
                'prep_minutes' => $prep,
                'cook_minutes' => $cook,
                'rest_minutes' => $rest,
                'difficulty' => $row['difficulty'],
                'protein' => $row['protein'],
                'source' => 'Premier lot Foodtruck',
                'industrial_price_cents' => $row['industrial'],
                'status' => Recipe::STATUS_PUBLISHED,
            ],
            'ingredients' => $lines,
            'steps' => $steps,
            'tag_ids' => RecipeWriter::tagIds($row['tags']),
            'equipment_ids' => array_map($appliance, $row['equipment']),
        ]);

        if (count($row['tags']) !== $recipe->tags()->count()) {
            throw new RuntimeException("« {$row['title']} » : étiquette inconnue.");
        }

        return $recipe;
    }
}
