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
        'unit_price_cents', 'total_cents', 'discount_cents', 'status', 'ingredient_pack_id', 'pack_price_cents', 'price_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_price_cents' => 'integer',
            'total_cents' => 'integer',
            'discount_cents' => 'integer',
            'pack_price_cents' => 'integer',
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
