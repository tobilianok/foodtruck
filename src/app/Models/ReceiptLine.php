<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptLine extends Model
{
    public $timestamps = false;

    /** Reconnue par un libellé mémorisé pour ce magasin. */
    public const STATUS_KNOWN = 'reconnu';

    /** Proposition (libellé connu ailleurs ou ressemblance), à confirmer. */
    public const STATUS_SUGGESTED = 'propose';

    public const STATUS_UNKNOWN = 'a_associer';

    public const STATUS_IGNORED = 'ignore';

    public const STATUS_APPLIED = 'applique';

    protected $fillable = [
        'receipt_id', 'position', 'kind', 'raw_label', 'normalized_label', 'quantity', 'quantity_unit',
        'unit_price_cents', 'total_cents', 'discount_cents', 'vat_rate', 'status', 'ingredient_id', 'ingredient_pack_id', 'pack_price_cents', 'price_id',
    ];

    /** Taux de TVA à partir duquel une ligne est présumée non alimentaire (produits d'entretien, alcool…). */
    public const NON_FOOD_VAT = 19.0;

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_price_cents' => 'integer',
            'total_cents' => 'integer',
            'discount_cents' => 'integer',
            'pack_price_cents' => 'integer',
            'vat_rate' => 'float',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(IngredientPack::class, 'ingredient_pack_id');
    }

    /** Ingrédient proposé sans conditionnement (quantité du ticket absente du référentiel). */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function isNonFoodVat(): bool
    {
        return $this->vat_rate !== null && $this->vat_rate >= self::NON_FOOD_VAT;
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }

    public function isWeighted(): bool
    {
        return $this->quantity_unit === 'kg';
    }

    public function quantityLabel(): string
    {
        if ($this->isWeighted()) {
            return number_format($this->quantity, 3, ',', ' ').' kg';
        }

        return $this->quantity == 1 ? '1' : rtrim(rtrim(number_format($this->quantity, 3, ',', ' '), '0'), ',');
    }

    public function paidCents(): int
    {
        return $this->total_cents - $this->discount_cents;
    }
}
