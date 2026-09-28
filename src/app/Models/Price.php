<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Price extends Model
{
    /** Origine du prix : estimation de départ, saisie manuelle, ticket de caisse. */
    public const SOURCES = [
        'estimation' => 'estimé',
        'manuel' => 'saisi',
        'ticket' => 'ticket',
    ];

    protected $fillable = ['ingredient_pack_id', 'store_id', 'price_cents', 'source', 'is_promo', 'observed_on', 'created_by'];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'is_promo' => 'boolean',
            'observed_on' => 'date',
        ];
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(IngredientPack::class, 'ingredient_pack_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }

    public static function formatCents(float $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ').' €';
    }

    /** Montant saisi par l'utilisateur (« 1,05 », « 1.05 », « 1,05 € ») → centimes. */
    public static function parseEuros(?string $input): ?int
    {
        $clean = str_replace([' ', "\u{00A0}", '€'], '', (string) $input);
        $clean = str_replace(',', '.', $clean);

        if ($clean === '' || ! is_numeric($clean) || (float) $clean < 0) {
            return null;
        }

        return (int) round((float) $clean * 100);
    }
}
