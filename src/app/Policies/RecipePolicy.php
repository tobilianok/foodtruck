<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\User;

/**
 * Recettes publiques : tout le monde voit les recettes publiées (et ses brouillons).
 * Modification et suppression : l'auteur ou un admin de l'application ; les autres dupliquent.
 */
class RecipePolicy
{
    public function view(User $user, Recipe $recipe): bool
    {
        return $recipe->isPublished() || $recipe->author_id === $user->id || $user->isAdmin();
    }

    public function update(User $user, Recipe $recipe): bool
    {
        return $recipe->author_id === $user->id || $user->isAdmin();
    }

    public function delete(User $user, Recipe $recipe): bool
    {
        return $this->update($user, $recipe);
    }
}
