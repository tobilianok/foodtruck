<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HouseholdMember extends Model
{
    /** Catégories et coefficient de portion proposé par défaut. */
    public const CATEGORIES = [
        'adulte' => ['label' => 'Adulte', 'coefficient' => 1.0],
        'enfant' => ['label' => 'Enfant', 'coefficient' => 0.6],
        'tout-petit' => ['label' => 'Tout-petit', 'coefficient' => 0.0],
    ];

    protected $fillable = ['household_id', 'name', 'category', 'portion_coefficient', 'user_id', 'position'];

    protected function casts(): array
    {
        return [
            'portion_coefficient' => 'float',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category]['label'] ?? $this->category;
    }

    public static function defaultCoefficient(string $category): float
    {
        return self::CATEGORIES[$category]['coefficient'] ?? 1.0;
    }
}
