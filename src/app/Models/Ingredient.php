<?php

namespace App\Models;

use App\Support\Units;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ingredient extends Model
{
    public const MONTHS = [1 => 'janv.', 2 => 'févr.', 3 => 'mars', 4 => 'avr.', 5 => 'mai', 6 => 'juin', 7 => 'juil.', 8 => 'août', 9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.'];

    protected $fillable = ['name', 'slug', 'aisle_id', 'base_unit', 'piece_weight_g', 'density', 'season_months', 'is_fresh', 'is_staple', 'created_by'];

    protected function casts(): array
    {
        return [
            'piece_weight_g' => 'float',
            'density' => 'float',
            'season_months' => 'array',
            'is_fresh' => 'boolean',
            'is_staple' => 'boolean',
        ];
    }

    public function aisle(): BelongsTo
    {
        return $this->belongsTo(Aisle::class);
    }

    public function packs(): HasMany
    {
        return $this->hasMany(IngredientPack::class)->orderBy('position')->orderBy('quantity');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** null = disponible toute l'année (pas de saisonnalité). */
    public function isInSeason(?int $month = null): ?bool
    {
        if (empty($this->season_months)) {
            return null;
        }

        return in_array($month ?? (int) now('Europe/Paris')->month, array_map('intval', $this->season_months), true);
    }

    public function seasonLabel(): string
    {
        if (empty($this->season_months)) {
            return 'toute l\'année';
        }

        $months = array_map('intval', $this->season_months);
        sort($months);

        return implode(', ', array_map(fn ($m) => self::MONTHS[$m], $months));
    }

    /**
     * Meilleure offre connue : le prix le plus bas ramené au kg / L / pièce,
     * tous conditionnements et magasins confondus (relations packs.prices chargées).
     *
     * @return array{price: Price, pack: IngredientPack, per_reference_cents: float}|null
     */
    public function bestOffer(?array $storeIds = null): ?array
    {
        $best = null;

        foreach ($this->packs as $pack) {
            $pack->setRelation('ingredient', $this);

            foreach ($pack->currentPrices() as $price) {
                if ($storeIds !== null && ! in_array($price->store_id, $storeIds, true)) {
                    continue;
                }

                $perReference = $pack->perReferenceCents($price);
                if ($best === null || $perReference < $best['per_reference_cents']) {
                    $best = ['price' => $price, 'pack' => $pack, 'per_reference_cents' => $perReference];
                }
            }
        }

        return $best;
    }

    public function referenceUnit(): string
    {
        return Units::referenceUnit($this->base_unit);
    }

    public function baseUnitLabel(): string
    {
        return Units::BASE_CHOICES[$this->base_unit] ?? $this->base_unit;
    }
}
