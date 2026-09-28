<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Household extends Model
{
    protected $fillable = ['name', 'weekly_budget_cents', 'main_store_id', 'produce_store_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'weekly_budget_cents' => 'integer',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(HouseholdMember::class)->orderBy('position')->orderBy('id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class)->orderBy('name');
    }

    public function equipment(): BelongsToMany
    {
        return $this->belongsToMany(Equipment::class, 'household_equipment')->orderBy('position')->orderBy('name');
    }

    public function mainStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'main_store_id');
    }

    /** Magasin habituel pour les fruits et légumes (ex. le primeur). */
    public function produceStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'produce_store_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(HouseholdInvitation::class)->latest();
    }

    /** Nombre de parts d'un repas pour tout le foyer (somme des coefficients). */
    public function totalPortions(): float
    {
        return (float) $this->members->sum('portion_coefficient');
    }

    public function budgetEuros(): float
    {
        return $this->weekly_budget_cents / 100;
    }

    public function adminCount(): int
    {
        return $this->users()->where('household_role', User::HOUSEHOLD_ADMIN)->count();
    }
}
