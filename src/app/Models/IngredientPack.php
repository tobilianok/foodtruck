<?php

namespace App\Models;

use App\Support\Units;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class IngredientPack extends Model
{
    protected $fillable = ['ingredient_id', 'label', 'quantity', 'is_bulk', 'position'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'is_bulk' => 'boolean',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    /** Dernier prix connu dans chaque magasin (relation prices chargée). */
    public function currentPrices(): Collection
    {
        return $this->prices
            ->sortByDesc(fn (Price $p) => $p->observed_on->format('Ymd').str_pad((string) $p->id, 10, '0', STR_PAD_LEFT))
            ->unique('store_id')
            ->keyBy('store_id');
    }

    public function currentPriceFor(int $storeId): ?Price
    {
        return $this->currentPrices()->get($storeId);
    }

    /** Prix ramené à l'unité de référence (€/kg, €/L, €/pièce), en centimes. */
    public function perReferenceCents(Price $price): float
    {
        if ($this->quantity <= 0) {
            return INF;
        }

        return $price->price_cents / $this->quantity * Units::referenceFactor($this->ingredient->base_unit);
    }

    public function quantityLabel(): string
    {
        return Units::format($this->quantity, $this->ingredient->base_unit);
    }
}
