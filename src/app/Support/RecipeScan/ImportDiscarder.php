<?php

namespace App\Support\RecipeScan;

use App\Models\MealPlanEntry;
use App\Models\RecipeImport;
use App\Models\User;
use App\Support\RecipePhoto;

/**
 * « Supprimer » une fiche Paperless qui attend sa relecture.
 *
 * La fiche n'est pas effacée : elle est mise de côté (statut « ignorée »), donc « Chercher dans Paperless » et la
 * synchronisation horaire ne la recréent pas, même si le document reste dans Paperless. Elle peut être reprise.
 * Si elle avait déjà donné un brouillon de recette, ce brouillon est supprimé. Une recette publiée, ou un brouillon
 * déjà au planning, n'est jamais supprimé ici.
 */
class ImportDiscarder
{
    /** @return string|null null si la fiche est supprimée, sinon la raison du refus */
    public static function discard(RecipeImport $import, User $user): ?string
    {
        $recipe = $import->recipe;

        if ($recipe !== null) {
            if ($recipe->isPublished()) {
                return 'La recette « '.$recipe->title.' » est publiée : supprime-la depuis sa fiche si tu n\'en veux plus.';
            }
            if (! $user->can('delete', $recipe)) {
                return 'Le brouillon « '.$recipe->title.' » appartient à quelqu\'un d\'autre : seul son auteur ou un administrateur peut le supprimer.';
            }
            if (MealPlanEntry::where('recipe_id', $recipe->id)->exists()) {
                return 'Le brouillon « '.$recipe->title.' » est au planning : retire-le d\'abord du planning.';
            }

            RecipePhoto::delete($recipe->photo_path, $recipe->thumb_path);
            $recipe->delete();
        }

        $import->update(['status' => RecipeImport::STATUS_IGNORED, 'recipe_id' => null]);

        return null;
    }
}
