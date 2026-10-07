<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * v0.22.0 : menu enregistré d'un foyer (« Jarret-purée ») : plusieurs recettes servies ensemble, enregistrées depuis un
 * repas composé du planning et replanifiables en un clic. L'ordre des recettes est celui du repas (plat d'abord).
 */
class SavedMenu extends Model
{
    public const MAX_RECIPES = 6;

    protected $fillable = ['household_id', 'name', 'created_by'];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'saved_menu_recipes')->withPivot('position')->withTimestamps()
            ->orderByPivot('position');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(MealPlanEntry::class);
    }

    /** Identifiants des recettes, dans l'ordre du menu. @return array<int, int> */
    public function recipeIds(): array
    {
        return $this->recipes->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
