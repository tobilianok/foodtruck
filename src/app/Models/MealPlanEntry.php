<?php

namespace App\Models;

use App\Support\RecipeServing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un plat du planning (recette cuisinée), des restes d'un plat, un repas hors maison ou une note libre.
 */
class MealPlanEntry extends Model
{
    public const KIND_RECIPE = 'recette';

    public const KIND_LEFTOVER = 'restes';

    public const KIND_OUT = 'hors_maison';

    public const KIND_NOTE = 'note';

    /** Créneaux dans l'ordre de la journée : code => [libellé, libellé court]. */
    public const SLOTS = [
        'petit-dejeuner' => ['Petit-déjeuner', 'P.-déj.'],
        'dejeuner' => ['Déjeuner', 'Midi'],
        'gouter' => ['Goûter', 'Goûter'],
        'diner' => ['Dîner', 'Soir'],
        'preparation' => ['À préparer', 'À prép.'],
    ];

    /** Créneaux affichés tant que le foyer n'a rien choisi. */
    public const DEFAULT_SLOTS = ['dejeuner', 'diner', 'preparation'];

    /** Créneaux où l'on mange (semaine type, convives) : tous sauf les préparations. */
    public const EATING_SLOTS = ['petit-dejeuner', 'dejeuner', 'gouter', 'diner'];

    /** Créneaux qui reçoivent automatiquement les restes d'un plat. */
    public const LEFTOVER_SLOTS = ['dejeuner', 'diner'];

    protected $fillable = [
        'household_id', 'date', 'slot', 'position', 'kind', 'recipe_id', 'source_entry_id', 'eaters', 'guest_adults',
        'guest_children', 'meals', 'parts_manual', 'batch_quantity', 'is_frozen', 'note', 'created_by',
        'proposed_at', 'proposal_reason',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'eaters' => 'array',
            'guest_adults' => 'integer',
            'guest_children' => 'integer',
            'meals' => 'integer',
            'parts_manual' => 'float',
            'batch_quantity' => 'float',
            'is_frozen' => 'boolean',
            'proposed_at' => 'datetime',
        ];
    }

    /** Repas confirmés : ceux que Foodtruck a seulement proposés (menu automatique) sont exclus. */
    public function scopeConfirmed($query)
    {
        return $query->whereNull('proposed_at');
    }

    /** Repas proposé par le menu automatique, pas encore gardé ni validé. */
    public function isProposal(): bool
    {
        return $this->proposed_at !== null;
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** Plat cuisiné dont ces restes proviennent. */
    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_entry_id');
    }

    public function leftovers(): HasMany
    {
        return $this->hasMany(self::class, 'source_entry_id')->orderBy('date')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRecipe(): bool
    {
        return $this->kind === self::KIND_RECIPE && $this->recipe_id !== null;
    }

    public function isLeftover(): bool
    {
        return $this->kind === self::KIND_LEFTOVER;
    }

    public function slotLabel(bool $short = false): string
    {
        return self::SLOTS[$this->slot][$short ? 1 : 0] ?? $this->slot;
    }

    /** Ordre chronologique d'un créneau (date + rang du créneau dans la journée). */
    public function sortKey(): string
    {
        return $this->date->toDateString().'-'.array_search($this->slot, array_keys(self::SLOTS), true);
    }

    /**
     * Réglages de proratisation au format de RecipeServing (mêmes clés que l'adresse de la fiche recette).
     * Convives non précisés : ceux de la semaine type pour ce jour et ce repas (null = tout le foyer).
     */
    public function servingInput(): array
    {
        if ($this->batch_quantity !== null) {
            return ['quantite' => $this->batch_quantity];
        }

        $eaters = $this->eaters ?? $this->household?->usualEaters($this->date, $this->slot);

        return array_filter([
            'ajuste' => $eaters !== null ? 1 : null,
            'qui' => $eaters,
            'adultes' => $this->guest_adults ?: null,
            'enfants' => $this->guest_children ?: null,
            'repas' => $this->meals > 1 ? $this->meals : null,
            'parts' => $this->parts_manual,
        ], fn ($v) => $v !== null);
    }

    public function serving(?Household $household = null): ?RecipeServing
    {
        $source = $this->isLeftover() ? $this->source : $this;
        if (! $source?->recipe) {
            return null;
        }

        return RecipeServing::for($source->recipe, $household ?? $this->household, $source->servingInput(), $source->date);
    }

    /** Parts servies sur ce créneau (un repas du plat, restes compris). */
    public function partsLabel(?Household $household = null): ?string
    {
        $serving = $this->serving($household);
        if ($serving === null) {
            return null;
        }

        return $serving->isPortions() ? RecipeServing::partsLabel($serving->perMeal) : $serving->totalLabel();
    }

    public static function slotCodes(): array
    {
        return array_keys(self::SLOTS);
    }
}
