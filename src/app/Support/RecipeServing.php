<?php

namespace App\Support;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Recipe;
use Illuminate\Support\Collection;

/**
 * Pour combien cuisiner : proratisation d'une recette selon le foyer.
 *
 * Recette « pour N personnes » (mode parts) :
 *   parts d'un repas = coefficients des membres qui mangent + invités (adulte 1, enfant 0,6), ou réglage libre ;
 *   total = parts d'un repas × nombre de repas (ce soir + demain midi, une part à congeler…).
 * Recette en pots, pièces, parts de gâteau ou grammes (mode fournée) : quantité à préparer choisie
 * (8 pots → 16 pots), indépendante du nombre de personnes.
 *
 * Les réglages viennent de l'adresse de la page (lien partageable) :
 *   ?qui[]=1&qui[]=2&adultes=1&enfants=0&repas=2&parts=3   ou   ?quantite=16
 */
class RecipeServing
{
    public const MODE_PORTIONS = 'parts';

    public const MODE_BATCH = 'fournee';

    public const MAX_MEALS = 4;

    public const MAX_GUESTS = 20;

    /** @var Collection<int, HouseholdMember> */
    public Collection $members;

    /** @var array<int, int> identifiants des membres qui mangent */
    public array $eaters = [];

    public int $adults = 0;

    public int $children = 0;

    public int $meals = 1;

    /** Parts d'un repas saisies à la main (remplace le calcul par les membres). */
    public ?float $manual = null;

    public float $perMeal;

    public float $total;

    public float $factor;

    /** @var array<int, string> */
    public array $warnings = [];

    private function __construct(public readonly Recipe $recipe, public readonly string $mode)
    {
        $this->members = collect();
    }

    public static function for(Recipe $recipe, ?Household $household, array $input = []): self
    {
        $base = max((float) $recipe->yield_quantity, 0.0001);

        if ($recipe->yield_unit !== 'personnes') {
            $serving = new self($recipe, self::MODE_BATCH);
            $target = self::number($input['quantite'] ?? null);
            $max = $base * 20;
            $serving->total = $target !== null && $target > 0 ? min($target, $max) : $base;
            $serving->perMeal = $serving->total;
            $serving->factor = $serving->total / $base;

            return $serving;
        }

        $serving = new self($recipe, self::MODE_PORTIONS);
        $serving->members = $household?->members ?? collect();
        $adjusted = ! empty($input['ajuste']);

        $ids = $serving->members->pluck('id')->map(fn ($id) => (int) $id)->all();
        $serving->eaters = $adjusted
            ? array_values(array_intersect($ids, array_map('intval', (array) ($input['qui'] ?? []))))
            : $ids;
        $serving->adults = self::count($input['adultes'] ?? 0, self::MAX_GUESTS);
        $serving->children = self::count($input['enfants'] ?? 0, self::MAX_GUESTS);
        $serving->meals = max(1, self::count($input['repas'] ?? 1, self::MAX_MEALS));

        $manual = self::number($input['parts'] ?? null);
        if ($manual !== null && $manual > 0) {
            $serving->manual = min(round($manual * 2) / 2 ?: 0.5, 50);
        }

        $computed = $serving->members->whereIn('id', $serving->eaters)->sum('portion_coefficient')
            + $serving->adults * HouseholdMember::defaultCoefficient('adulte')
            + $serving->children * HouseholdMember::defaultCoefficient('enfant');

        $serving->perMeal = $serving->manual ?? round($computed, 2);

        if ($serving->perMeal <= 0) {
            // Foyer sans membre ou personne de coché : on affiche la recette telle qu'écrite
            $serving->warnings[] = $serving->members->isEmpty()
                ? 'Aucun membre dans le foyer : recette affichée pour '.self::partsLabel($base).'.'
                : 'Personne n\'est coché : recette affichée pour '.self::partsLabel($base).'.';
            $serving->perMeal = $base;
        }

        $serving->total = $serving->perMeal * $serving->meals;
        $serving->factor = $serving->total / $base;

        if ($serving->factor >= 2 && $recipe->cook_minutes) {
            $serving->warnings[] = 'Quantités multipliées par '.Units::number($serving->factor, 1).' : prévois un plat plus grand ou deux fournées, et surveille la cuisson.';
        }

        return $serving;
    }

    public function isPortions(): bool
    {
        return $this->mode === self::MODE_PORTIONS;
    }

    public function isScaled(): bool
    {
        return abs($this->factor - 1) >= 0.0001;
    }

    /** « 2,5 parts », « 16 pots », « 1 kg ». */
    public function totalLabel(): string
    {
        return $this->isPortions() ? self::partsLabel($this->total) : $this->recipe->yieldLabel($this->total);
    }

    /** Détail des convives : « Tobilianok, Marina + 1 adulte invité · 2 repas ». */
    public function detailLabel(): string
    {
        if (! $this->isPortions()) {
            return $this->isScaled() ? 'recette d\'origine : '.$this->recipe->yieldLabel() : '';
        }

        $parts = [];
        if ($this->manual !== null) {
            $parts[] = 'réglage libre';
        } else {
            $names = $this->members->whereIn('id', $this->eaters)->pluck('name')->all();
            $guests = array_filter([
                $this->adults ? $this->adults.' adulte'.($this->adults > 1 ? 's' : '') : null,
                $this->children ? $this->children.' enfant'.($this->children > 1 ? 's' : '') : null,
            ]);
            $who = implode(', ', $names);
            if ($guests) {
                $who .= ($who !== '' ? ' + ' : '').implode(' et ', $guests).' invité'.(($this->adults + $this->children) > 1 ? 's' : '');
            }
            if ($who !== '') {
                $parts[] = $who;
            }
        }

        if ($this->meals > 1) {
            $parts[] = $this->meals.' repas de '.self::partsLabel($this->perMeal);
        }

        return implode(' · ', $parts);
    }

    /** Paramètres d'adresse reproduisant ces réglages, avec des modifications. */
    public function query(array $override = []): array
    {
        if (! $this->isPortions()) {
            return array_merge(['quantite' => Units::number($this->total)], $override);
        }

        return array_merge(array_filter([
            'ajuste' => 1,
            'qui' => $this->eaters,
            'adultes' => $this->adults ?: null,
            'enfants' => $this->children ?: null,
            'repas' => $this->meals > 1 ? $this->meals : null,
            'parts' => $this->manual !== null ? Units::number($this->manual) : null,
        ], fn ($v) => $v !== null), $override);
    }

    /** Quantités proposées en un clic pour une fournée : ×½, ×1, ×2, ×3. @return array<string, float> */
    public function batchChoices(): array
    {
        $base = (float) $this->recipe->yield_quantity;
        $choices = [];
        foreach ([0.5, 1, 2, 3] as $multiplier) {
            $quantity = $base * $multiplier;
            // Une demi-fournée n'a de sens que si elle reste entière (8 pots → 4 pots)
            if ($multiplier === 0.5 && fmod($quantity, 1.0) !== 0.0 && $this->recipe->yield_unit !== 'grammes') {
                continue;
            }
            $choices[($multiplier === 0.5 ? '½' : (string) $multiplier)] = $quantity;
        }

        return $choices;
    }

    public static function partsLabel(float $parts): string
    {
        return Units::number($parts).' part'.($parts > 1 ? 's' : '');
    }

    private static function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        $value = str_replace(',', '.', trim((string) $value));

        return is_numeric($value) ? (float) $value : null;
    }

    private static function count(mixed $value, int $max): int
    {
        $number = self::number($value);

        return $number === null ? 0 : (int) max(0, min($max, floor($number)));
    }
}
