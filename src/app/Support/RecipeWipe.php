<?php

namespace App\Support;

use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\RecipeImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * v0.18.0 (demande de Louis) : suppression de TOUTES les recettes, pour ne réimporter que ses propres fiches,
 * lues par le modèle de vision.
 *
 * Effacé : recettes (ingrédients, étapes, ustensiles, étiquettes, favoris des recettes), leurs photos, les repas du
 * planning qui utilisent une recette (et leurs restes), et toutes les fiches Paperless déjà lues (« Chercher dans
 * Paperless » les relit toutes depuis le début).
 * Conservé : comptes, foyers et réglages, ingrédients et leurs unités, rapprochements appris, magasins, tickets, prix,
 * stock, listes de courses, repas du planning sans recette.
 */
class RecipeWipe
{
    /** @return array<string, int> libellé => nombre de lignes concernées */
    public static function counts(): array
    {
        return [
            'Recettes' => Recipe::count(),
            'Photos de recettes' => Recipe::whereNotNull('photo_path')->count(),
            'Repas du planning avec recette' => MealPlanEntry::whereNotNull('recipe_id')->count(),
            'Fiches Paperless lues' => RecipeImport::count(),
        ];
    }

    public static function run(): void
    {
        $files = Recipe::query()->get(['photo_path', 'thumb_path'])
            ->flatMap(fn (Recipe $r) => [$r->photo_path, $r->thumb_path])->filter()->unique()->values()->all();

        DB::transaction(function () {
            MealPlanEntry::whereNotNull('recipe_id')->delete();
            RecipeImport::query()->delete();
            // Variantes d'abord (parent_id), puis le reste ; les tables liées suivent (suppression en cascade)
            Recipe::whereNotNull('parent_id')->delete();
            Recipe::query()->delete();
        });

        // Photos effacées après la base (si la base échoue, rien n'est perdu), photos proposées par l'IA comprises
        if ($files !== []) {
            Storage::disk('public')->delete($files);
        }
        Storage::disk('public')->deleteDirectory('imports');
    }
}
