<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class Recipe extends Model
{
    public const CATEGORIES = [
        'plat' => ['Plat', '🍲'],
        'entree' => ['Entrée', '🥗'],
        'accompagnement' => ['Accompagnement', '🥔'],
        'dessert' => ['Dessert', '🍰'],
        'gouter' => ['Goûter', '🍪'],
        'petit-dejeuner' => ['Petit-déjeuner', '🥣'],
        'base' => ['Base maison', '🫙'],
        'boisson' => ['Boisson', '🥤'],
    ];

    /** Rendement : [singulier, pluriel]. « personnes » sera proratisé selon le foyer. */
    public const YIELD_UNITS = [
        'personnes' => ['personne', 'personnes'],
        'parts' => ['part', 'parts'],
        'pieces' => ['pièce', 'pièces'],
        'pots' => ['pot', 'pots'],
        'grammes' => ['g', 'g'],
    ];

    public const DIFFICULTIES = ['facile' => 'Facile', 'moyen' => 'Moyen', 'difficile' => 'Difficile'];

    public const PROTEINS = [
        'boeuf' => 'Bœuf, agneau',
        'volaille' => 'Volaille',
        'porc' => 'Porc',
        'poisson' => 'Poisson',
        'oeufs' => 'Œufs',
        'legumineuses' => 'Légumineuses',
        'laitages' => 'Fromage, laitages',
        'aucune' => 'Sans protéine principale',
    ];

    public const STATUS_PUBLISHED = 'publie';

    public const STATUS_DRAFT = 'brouillon';

    /** Coût par personne sous lequel une recette est dite « économique » (centimes). */
    public const CHEAP_PER_PERSON_CENTS = 150;

    protected $fillable = [
        'title', 'slug', 'description', 'category', 'yield_quantity', 'yield_unit',
        'prep_minutes', 'cook_minutes', 'rest_minutes', 'difficulty', 'protein', 'source',
        'industrial_price_cents', 'photo_path', 'thumb_path', 'status', 'author_id', 'parent_id',
    ];

    protected function casts(): array
    {
        return [
            'yield_quantity' => 'float',
            'prep_minutes' => 'integer',
            'cook_minutes' => 'integer',
            'rest_minutes' => 'integer',
            'industrial_price_cents' => 'integer',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class)->orderBy('position');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(RecipeStep::class)->orderBy('position');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('position');
    }

    public function equipment(): BelongsToMany
    {
        return $this->belongsToMany(Equipment::class, 'recipe_equipment')->orderBy('position');
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'recipe_favorites')->withPivot('created_at');
    }

    /** Recettes publiées + brouillons de l'utilisateur. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn ($q) => $q->where('status', self::STATUS_PUBLISHED)->orWhere('author_id', $user->id));
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function totalMinutes(): int
    {
        return (int) $this->prep_minutes + (int) $this->cook_minutes;
    }

    public function durationLabel(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' min';
        }

        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h.' h'.($m ? ' '.str_pad((string) $m, 2, '0', STR_PAD_LEFT) : '');
    }

    public function yieldLabel(?float $quantity = null): string
    {
        $quantity ??= $this->yield_quantity;
        [$singular, $plural] = self::YIELD_UNITS[$this->yield_unit] ?? [$this->yield_unit, $this->yield_unit];
        $number = rtrim(rtrim(number_format($quantity, 2, ',', ' '), '0'), ',');

        return $number.' '.($quantity > 1 ? $plural : $singular);
    }

    /** Unité du coût unitaire : « personne », « pot », « 100 g »… */
    public function perYieldLabel(): string
    {
        return $this->yield_unit === 'grammes' ? '100 g' : (self::YIELD_UNITS[$this->yield_unit][0] ?? $this->yield_unit);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category][0] ?? $this->category;
    }

    public function categoryIcon(): string
    {
        return self::CATEGORIES[$this->category][1] ?? '🍽️';
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }

    public function thumbUrl(): ?string
    {
        return $this->thumb_path ? Storage::disk('public')->url($this->thumb_path) : $this->photoUrl();
    }

    /**
     * De saison : tous les ingrédients saisonniers de la recette sont de saison ce mois-ci.
     * null si la recette n'a aucun ingrédient saisonnier.
     */
    public function isInSeason(?int $month = null): ?bool
    {
        $seasonal = $this->ingredients->map->ingredient->filter(fn ($i) => ! empty($i->season_months));

        if ($seasonal->isEmpty()) {
            return null;
        }

        return $seasonal->every(fn (Ingredient $i) => $i->isInSeason($month) === true);
    }

    /** Appareils requis que le foyer n'a pas. */
    public function missingEquipment(?Household $household): Collection
    {
        if (! $household) {
            return collect();
        }

        $owned = $household->equipment->pluck('id');

        return $this->equipment->reject(fn (Equipment $e) => $owned->contains($e->id))->values();
    }
}
