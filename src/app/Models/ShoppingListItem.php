<?php

namespace App\Models;

use App\Support\Units;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShoppingListItem extends Model
{
    public const SOURCE_RECIPE = 'recette';

    public const SOURCE_MANUAL = 'manuel';

    /** À acheter / produit de base à contrôler chez soi (hors budget). */
    public const SECTION_BUY = 'achat';

    public const SECTION_CHECK = 'verifier';

    /** Besoin entièrement couvert par le stock : rien à acheter. */
    public const SECTION_STOCK = 'stock';

    protected $fillable = [
        'shopping_list_id', 'ingredient_id', 'label', 'aisle_id', 'source', 'section', 'section_locked', 'store_id', 'store_locked',
        'needed_base', 'stock_base', 'stock_ignored', 'base_unit', 'quantity_text', 'purchase', 'estimated_cents', 'used_cents', 'best_store_id', 'best_cents',
        'uses', 'note', 'is_checked', 'checked_by', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'section_locked' => 'boolean',
            'store_locked' => 'boolean',
            'needed_base' => 'float',
            'stock_base' => 'float',
            'stock_ignored' => 'boolean',
            'purchase' => 'array',
            'estimated_cents' => 'integer',
            'used_cents' => 'integer',
            'best_cents' => 'integer',
            'uses' => 'array',
            'is_checked' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class, 'shopping_list_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function aisle(): BelongsTo
    {
        return $this->belongsTo(Aisle::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function bestStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'best_store_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function isManual(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
    }

    public function isCovered(): bool
    {
        return $this->section === self::SECTION_STOCK;
    }

    /** « en stock : 40 cl » quand le stock couvre tout ou partie du besoin. */
    public function stockLabel(): ?string
    {
        if ($this->stock_base === null || $this->stock_base <= 0 || $this->base_unit === null) {
            return null;
        }

        return Units::format(Units::practical($this->stock_base, $this->base_unit), $this->base_unit);
    }

    public function isToCheck(): bool
    {
        return $this->section === self::SECTION_CHECK;
    }

    /** Prix connu pour le magasin choisi. */
    public function hasPrice(): bool
    {
        return $this->estimated_cents !== null;
    }

    /** « 1 × Brique 1 L », « 2 × Filet 2,5 kg + 1 × Filet 1 kg », « 450 g (vrac) ». */
    public function purchaseLabel(): ?string
    {
        if (empty($this->purchase)) {
            return null;
        }

        return collect($this->purchase)->map(function (array $part) {
            if (! empty($part['bulk'])) {
                return Units::format((float) $part['quantity'], $this->base_unit ?? 'g').' (vrac)';
            }

            return $part['count'].' × '.$part['label'];
        })->implode(' + ');
    }

    /** « besoin : 60 cl » (quantité cumulée des recettes). */
    public function neededLabel(): ?string
    {
        if ($this->needed_base === null || $this->needed_base <= 0 || $this->base_unit === null) {
            return null;
        }

        return Units::format(Units::practical($this->needed_base, $this->base_unit), $this->base_unit);
    }

    /** Quantité achetée en trop, dans l'unité de base (null si non calculable). */
    public function surplusBase(): ?float
    {
        if ($this->needed_base === null || empty($this->purchase)) {
            return null;
        }

        $bought = collect($this->purchase)->sum(fn (array $part) => (float) $part['quantity']);
        $surplus = $bought - $this->needed_base;

        return $surplus > 0.0001 ? round($surplus, 3) : null;
    }

    public function surplusLabel(): ?string
    {
        $surplus = $this->surplusBase();

        // En dessous de 2 % (ou 15 g / ml), ce n'est pas un reste à signaler
        if ($surplus === null || $this->base_unit === null) {
            return null;
        }
        if ($this->base_unit !== 'piece' && $surplus < max(15.0, $this->needed_base * 0.02)) {
            return null;
        }

        return Units::format(Units::practical($surplus, $this->base_unit), $this->base_unit);
    }

    /** Économie possible en achetant ailleurs, en centimes (0 si le magasin choisi est déjà le moins cher). */
    public function possibleSaving(): int
    {
        if ($this->estimated_cents === null || $this->best_cents === null || $this->best_store_id === $this->store_id) {
            return 0;
        }

        return max(0, $this->estimated_cents - $this->best_cents);
    }
}
