<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class HouseholdMember extends Model
{
    /** Catégories (conservées pour l'affichage et les invités) et coefficient de portion par défaut. */
    public const CATEGORIES = [
        'adulte' => ['label' => 'Adulte', 'coefficient' => 1.0],
        'enfant' => ['label' => 'Enfant', 'coefficient' => 0.6],
        'tout-petit' => ['label' => 'Tout-petit', 'coefficient' => 0.0],
    ];

    /**
     * Coefficient de portion selon l'âge : [âge (en années) en dessous duquel la ligne s'applique, coefficient, libellé, note].
     * Le changement a lieu le jour de l'anniversaire.
     */
    public const AGE_GRID = [
        [1, 0.0, 'bébé', 'lait ou purées : pas compté dans les quantités'],
        [3, 0.3, 'tout-petit', 'plat simple à part'],
        [5, 0.5, 'petit', 'mange comme les adultes'],
        [12, 0.7, 'enfant', null],
        [15, 0.8, 'ado', null],
        [200, 1.0, 'adulte', null],
    ];

    protected $fillable = ['household_id', 'name', 'category', 'birth_date', 'portion_coefficient', 'coefficient_manual', 'user_id', 'position'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'portion_coefficient' => 'float',
            'coefficient_manual' => 'boolean',
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

    // ------------------------------------------------------------------ âge

    private static function day(?Carbon $date = null): Carbon
    {
        return ($date ?? now('Europe/Paris'))->copy()->startOfDay();
    }

    /** Âge en années entières à la date donnée (aujourd'hui par défaut) ; null sans date de naissance. */
    public function ageOn(?Carbon $date = null): ?int
    {
        if ($this->birth_date === null) {
            return null;
        }

        $on = self::day($date);
        $years = $on->year - $this->birth_date->year;
        if ([$on->month, $on->day] < [$this->birth_date->month, $this->birth_date->day]) {
            $years--;
        }

        return max(0, $years);
    }

    /** Âge en mois entiers à la date donnée. */
    public function monthsOn(?Carbon $date = null): ?int
    {
        if ($this->birth_date === null) {
            return null;
        }

        $on = self::day($date);
        $months = ($on->year - $this->birth_date->year) * 12 + $on->month - $this->birth_date->month;
        if ($on->day < $this->birth_date->day) {
            $months--;
        }

        return max(0, $months);
    }

    /** « 8 mois », « 1 an », « 14 ans » ; moins de 2 ans en mois. */
    public function ageLabel(?Carbon $date = null): ?string
    {
        $months = $this->monthsOn($date);
        if ($months === null) {
            return null;
        }
        if ($months < 24) {
            return $months.' mois';
        }

        $years = $this->ageOn($date);

        return $years.' ans';
    }

    /** Ligne de la grille pour un âge. @return array{0: int, 1: float, 2: string, 3: ?string} */
    public static function gridFor(int $years): array
    {
        foreach (self::AGE_GRID as $row) {
            if ($years < $row[0]) {
                return $row;
            }
        }

        return self::AGE_GRID[array_key_last(self::AGE_GRID)];
    }

    /** « moins de 1 an : 0 · de 1 à 3 ans : 0,3 · … · 15 ans et plus : 1 ». */
    public static function gridSummary(): string
    {
        $parts = [];
        $previous = 0;
        foreach (self::AGE_GRID as $i => [$limit, $coefficient]) {
            $value = str_replace('.', ',', (string) $coefficient);
            $parts[] = match (true) {
                $i === array_key_last(self::AGE_GRID) => $previous.' ans et plus : '.$value,
                $previous === 0 => 'moins de '.$limit.' an'.($limit > 1 ? 's' : '').' : '.$value,
                default => 'de '.$previous.' à '.$limit.' ans : '.$value,
            };
            $previous = $limit;
        }

        return implode(' · ', $parts);
    }

    /** Le coefficient suit l'âge (date de naissance connue et pas de réglage manuel). */
    public function isAutomatic(): bool
    {
        return $this->birth_date !== null && ! $this->coefficient_manual;
    }

    /** Coefficient de portion à la date d'un repas : selon l'âge, ou la valeur enregistrée (réglage manuel, ou pas de date de naissance). */
    public function coefficientOn(?Carbon $date = null): float
    {
        if (! $this->isAutomatic()) {
            return (float) $this->portion_coefficient;
        }

        return self::gridFor($this->ageOn($date))[1];
    }

    /** Catégorie d'après l'âge (tout-petit avant 3 ans, enfant avant 15 ans) ; celle enregistrée sans date de naissance. */
    public function categoryOn(?Carbon $date = null): string
    {
        $years = $this->ageOn($date);
        if ($years === null) {
            return $this->category;
        }

        return $years < 3 ? 'tout-petit' : ($years < 15 ? 'enfant' : 'adulte');
    }

    /** Note affichée à côté du coefficient automatique (« plat simple à part »). */
    public function ageNote(?Carbon $date = null): ?string
    {
        $years = $this->ageOn($date);

        return $years === null || ! $this->isAutomatic() ? null : self::gridFor($years)[3];
    }

    /**
     * Valeurs à enregistrer d'après le formulaire : date de naissance, coefficient (automatique ou à la main), catégorie.
     *
     * - Sans date de naissance : le coefficient saisi est conservé (1 par défaut).
     * - Avec date de naissance : automatique, sauf case « à la main » cochée avec un coefficient.
     *
     * @return array{birth_date: ?string, portion_coefficient: float, coefficient_manual: bool, category: string}
     */
    public static function attributesFromInput(?string $birthDate, mixed $coefficient, bool $manual, ?Carbon $today = null): array
    {
        $birth = $birthDate ? Carbon::createFromFormat('Y-m-d', $birthDate)->startOfDay() : null;
        $coefficient = $coefficient === null || $coefficient === '' ? null : round((float) $coefficient, 2);

        $probe = new self(['birth_date' => $birth, 'portion_coefficient' => $coefficient ?? 1.0, 'coefficient_manual' => false]);
        $years = $probe->ageOn($today);

        $category = $years === null ? 'adulte' : ($years < 3 ? 'tout-petit' : ($years < 15 ? 'enfant' : 'adulte'));

        if ($birth === null) {
            // Sans date de naissance, la catégorie se déduit du coefficient
            $value = $coefficient ?? 1.0;

            return ['birth_date' => null, 'portion_coefficient' => $value, 'coefficient_manual' => true, 'category' => $value <= 0.3 ? 'tout-petit' : ($value < 0.8 ? 'enfant' : 'adulte')];
        }

        if ($manual && $coefficient !== null) {
            return ['birth_date' => $birth->toDateString(), 'portion_coefficient' => $coefficient, 'coefficient_manual' => true, 'category' => $category];
        }

        return ['birth_date' => $birth->toDateString(), 'portion_coefficient' => self::gridFor($years)[1], 'coefficient_manual' => false, 'category' => $category];
    }
}
