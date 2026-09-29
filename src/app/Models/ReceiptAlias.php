<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Libellé de ticket mémorisé : « LAIT 1/2 ECR MDD 1L » chez Leclerc Drive = Lait demi-écrémé, bouteille 1 L.
 * is_ignored : ligne non alimentaire à ignorer (sac, consigne, lessive…).
 */
class ReceiptAlias extends Model
{
    protected $fillable = ['store_id', 'normalized_label', 'ingredient_pack_id', 'is_ignored', 'hits', 'created_by'];

    protected function casts(): array
    {
        return ['is_ignored' => 'boolean', 'hits' => 'integer'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(IngredientPack::class, 'ingredient_pack_id');
    }
}
