<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Libellé de fiche appris (« bonne cs de crème épaisse ») → ingrédient du référentiel. */
class RecipeAlias extends Model
{
    protected $fillable = ['normalized_label', 'ingredient_id', 'hits'];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
