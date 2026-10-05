<?php

namespace App\Models;

use App\Support\Units;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un lot du stock du foyer : quantité (unité de base de l'ingrédient) et date limite facultative. */
class PantryItem extends Model
{
    public const LOCATIONS = ['placard' => 'Placard', 'frigo' => 'Frigo', 'congelateur' => 'Congélateur'];

    /** « À consommer vite » : jours restants à partir desquels un lot est signalé. */
    public const SOON_DAYS = 3;

    protected $fillable = ['household_id', 'ingredient_id', 'quantity', 'expires_on', 'location', 'note', 'source', 'shopping_list_id', 'created_by'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'expires_on' => 'date'];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function locationLabel(): string
    {
        return self::LOCATIONS[$this->location] ?? $this->location;
    }

    public function quantityLabel(): string
    {
        return Units::format($this->quantity, $this->ingredient->base_unit);
    }

    /** Jours avant la date limite (négatif = dépassée), null sans date. */
    public function daysLeft(?\Carbon\CarbonInterface $today = null): ?int
    {
        if ($this->expires_on === null) {
            return null;
        }

        // Comparaison de jours entiers, dans le fuseau du foyer
        $today = ($today ?? now('Europe/Paris'))->copy()->startOfDay();
        $limit = \Carbon\Carbon::createFromFormat('Y-m-d', $this->expires_on->toDateString(), $today->getTimezone())->startOfDay();

        return (int) round($today->diffInDays($limit, false));
    }

    public function isExpired(?\Carbon\CarbonInterface $today = null): bool
    {
        return ($this->daysLeft($today) ?? 1) < 0;
    }

    public function isSoon(?\Carbon\CarbonInterface $today = null): bool
    {
        $days = $this->daysLeft($today);

        return $days !== null && $days >= 0 && $days <= self::SOON_DAYS;
    }

    /** « expire demain », « expire dans 3 j », « périmé depuis 2 j ». */
    public function expiryLabel(?\Carbon\CarbonInterface $today = null): ?string
    {
        $days = $this->daysLeft($today);

        return match (true) {
            $days === null => null,
            $days < 0 => 'périmé depuis '.abs($days).' j',
            $days === 0 => 'expire aujourd\'hui',
            $days === 1 => 'expire demain',
            default => 'expire dans '.$days.' j',
        };
    }

    /** Valeur et unité à proposer dans un formulaire : 1 500 g → 1,5 kg. @return array{0: float, 1: string} */
    public function inputValue(): array
    {
        $unit = $this->ingredient->base_unit;

        return match (true) {
            $unit === 'g' && $this->quantity >= 1000 => [round($this->quantity / 1000, 3), 'kg'],
            $unit === 'ml' && $this->quantity >= 1000 => [round($this->quantity / 1000, 3), 'l'],
            default => [round($this->quantity, 3), $unit],
        };
    }
}
