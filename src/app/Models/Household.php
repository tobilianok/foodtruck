<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Household extends Model
{
    protected $fillable = [
        'name', 'weekly_budget_cents', 'menu_veggy_min', 'main_store_id', 'produce_store_id', 'meal_slots', 'usual_absences', 'created_by',
        'paperless_url', 'paperless_token', 'paperless_tag', 'paperless_recipe_tag', 'paperless_synced_at', 'paperless_last_error',
    ];

    protected $hidden = ['paperless_token'];

    protected function casts(): array
    {
        return [
            'weekly_budget_cents' => 'integer',
            'menu_veggy_min' => 'integer',
            'meal_slots' => 'array',
            'usual_absences' => 'array',
            'paperless_token' => 'encrypted',
            'paperless_synced_at' => 'datetime',
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

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function hasPaperless(): bool
    {
        return filled($this->paperless_url) && filled($this->paperless_token);
    }

    public function paperlessTag(): string
    {
        return $this->paperless_tag ?: 'courses alimentaires';
    }

    public function paperlessRecipeTag(): string
    {
        return $this->paperless_recipe_tag ?: 'recettes';
    }

    public function recipeImports(): HasMany
    {
        return $this->hasMany(RecipeImport::class)->latest('id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(HouseholdInvitation::class)->latest();
    }

    public function mealPlanEntries(): HasMany
    {
        return $this->hasMany(MealPlanEntry::class);
    }

    /** v0.22.0 : menus enregistrés (repas composés réutilisables), par nom. */
    public function savedMenus(): HasMany
    {
        return $this->hasMany(SavedMenu::class)->orderBy('name');
    }

    public function pantryItems(): HasMany
    {
        return $this->hasMany(PantryItem::class);
    }

    public function shoppingLists(): HasMany
    {
        return $this->hasMany(ShoppingList::class)->latest('id');
    }

    /** Créneaux affichés dans le planning, dans l'ordre de la journée (tous par défaut). @return array<int, string> */
    public function mealSlots(): array
    {
        $chosen = $this->meal_slots;

        return empty($chosen)
            ? MealPlanEntry::DEFAULT_SLOTS
            : array_values(array_filter(MealPlanEntry::slotCodes(), fn ($slot) => in_array($slot, $chosen, true)));
    }

    /** Créneaux du planning où l'on mange (semaine type). */
    public function eatingSlots(): array
    {
        return array_values(array_intersect($this->mealSlots(), MealPlanEntry::EATING_SLOTS));
    }

    /** Membre habituellement absent ce jour de la semaine (1 = lundi) à ce repas. */
    public function isUsuallyAbsent(int $memberId, int $weekday, string $slot): bool
    {
        return in_array($memberId, array_map('intval', $this->usual_absences[$slot][(string) $weekday] ?? []), true);
    }

    /**
     * Membres présents d'après la semaine type pour ce jour et ce repas :
     * null = tout le foyer, [] = personne à la maison.
     *
     * @return array<int, int>|null
     */
    public function usualEaters(\Carbon\CarbonInterface $date, string $slot): ?array
    {
        $absent = array_map('intval', $this->usual_absences[$slot][(string) $date->isoWeekday()] ?? []);
        if ($absent === []) {
            return null;
        }

        return $this->members->pluck('id')->map(fn ($id) => (int) $id)->diff($absent)->values()->all();
    }

    /** Nombre de parts d'un repas pour tout le foyer (somme des coefficients à la date donnée, aujourd'hui par défaut). */
    public function totalPortions(?\Illuminate\Support\Carbon $on = null): float
    {
        return (float) $this->members->sum(fn (HouseholdMember $m) => $m->coefficientOn($on));
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
