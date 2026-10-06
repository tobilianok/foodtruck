<?php

namespace App\Support;

use App\Models\Ingredient;
use InvalidArgumentException;

/**
 * Unités de mesure et conversions vers l'unité de base d'un ingrédient.
 *
 * Trois dimensions : masse (base g), volume (base ml), pièce (base pièce).
 * Le passage d'une dimension à l'autre utilise les caractéristiques de l'ingrédient :
 * densité (g/ml) et poids d'une pièce (g).
 */
class Units
{
    public const MASS = 'masse';

    public const VOLUME = 'volume';

    public const PIECE = 'piece';

    /** Unité propre à un ingrédient (« u:sachet ») : convertie par l'ingrédient lui-même (v0.16.0). */
    public const CUSTOM = 'propre';

    public const CUSTOM_PREFIX = 'u:';

    /** Unité de base de chaque dimension. */
    public const BASE = [
        self::MASS => 'g',
        self::VOLUME => 'ml',
        self::PIECE => 'piece',
    ];

    /** code => [libellé court, dimension, facteur vers la base de la dimension] */
    public const UNITS = [
        'g' => ['g', self::MASS, 1],
        'kg' => ['kg', self::MASS, 1000],
        'pincee' => ['pincée', self::MASS, 0.5],
        'ml' => ['ml', self::VOLUME, 1],
        'cl' => ['cl', self::VOLUME, 10],
        'dl' => ['dl', self::VOLUME, 100],
        'l' => ['L', self::VOLUME, 1000],
        'cac' => ['c. à café', self::VOLUME, 5],
        'cas' => ['c. à soupe', self::VOLUME, 15],
        'verre' => ['verre', self::VOLUME, 200],
        'piece' => ['pièce', self::PIECE, 1],
    ];

    /** Unités de base proposées pour un ingrédient. */
    public const BASE_CHOICES = [
        'g' => 'Au poids (g)',
        'ml' => 'Au volume (ml)',
        'piece' => 'À la pièce',
    ];

    public static function exists(string $unit): bool
    {
        return isset(self::UNITS[$unit]);
    }

    public static function isCustom(?string $unit): bool
    {
        return $unit !== null && str_starts_with($unit, self::CUSTOM_PREFIX);
    }

    public static function customSlug(string $unit): string
    {
        return substr($unit, strlen(self::CUSTOM_PREFIX));
    }

    /** Code de ligne de recette accepté : unité générale (g, cl, c. à soupe…) ou unité propre (« u:sachet »). */
    public static function validCode(?string $unit): bool
    {
        return $unit !== null && (self::exists($unit) || preg_match('/^u:[a-z0-9][a-z0-9-]{0,35}$/', $unit) === 1);
    }

    public static function dimension(string $unit): string
    {
        if (self::isCustom($unit)) {
            return self::CUSTOM;
        }
        self::assertExists($unit);

        return self::UNITS[$unit][1];
    }

    public static function label(string $unit, ?Ingredient $ingredient = null, float $quantity = 1): string
    {
        if (self::isCustom($unit)) {
            $own = $ingredient?->unitBySlug($unit);

            return $own ? $own->label($quantity) : str_replace('-', ' ', self::customSlug($unit));
        }
        self::assertExists($unit);

        return self::UNITS[$unit][0];
    }

    /**
     * Convertit une quantité exprimée dans $unit vers l'unité de base de l'ingrédient.
     *
     * @throws UnitConversionException si la conversion demande une donnée absente (densité, poids d'une pièce)
     */
    public static function toBase(float $quantity, string $unit, Ingredient $ingredient): float
    {
        if (self::isCustom($unit)) {
            $own = $ingredient->unitBySlug($unit);
            if (! $own) {
                throw new UnitConversionException('Unité « '.self::label($unit).' » inconnue pour « '.$ingredient->name.' ».');
            }

            return $quantity * $own->quantity;
        }

        $from = self::dimension($unit);
        $to = self::dimension($ingredient->base_unit);
        $value = $quantity * self::UNITS[$unit][2];

        if ($from === $to) {
            return $value;
        }

        // Passage par la masse (g) comme pivot.
        $grams = match ($from) {
            self::MASS => $value,
            self::VOLUME => $value * self::requireDensity($ingredient),
            self::PIECE => $value * self::requirePieceWeight($ingredient),
        };

        return match ($to) {
            self::MASS => $grams,
            self::VOLUME => $grams / self::requireDensity($ingredient),
            self::PIECE => $grams / self::requirePieceWeight($ingredient),
        };
    }

    /** Quantité saisie dans une recette : « 2 pièces », « 1 c. à soupe », « 20 cl ». */
    public static function quantityLabel(float $quantity, string $unit, ?Ingredient $ingredient = null): string
    {
        if (self::isCustom($unit)) {
            return self::fraction($quantity).' '.self::label($unit, $ingredient, $quantity);
        }

        $plurals = ['piece' => 'pièces', 'pincee' => 'pincées', 'verre' => 'verres'];
        $label = $quantity > 1 && isset($plurals[$unit]) ? $plurals[$unit] : self::label($unit);

        return self::number($quantity).' '.$label;
    }

    /**
     * Arrondi « pratique » d'une quantité recalculée (proratisation) : ce qu'on mesure vraiment en cuisine.
     * Pièces à l'entier (½ en dessous de 1), grammes et millilitres à 1, 5, 10 ou 50 près selon la quantité,
     * cuillères et verres au ½, pincées à l'unité. Jamais zéro.
     */
    public static function practical(float $quantity, string $unit): float
    {
        if ($quantity <= 0) {
            return 0.0;
        }

        $step = self::isCustom($unit) ? ($quantity < 2 ? 0.25 : 0.5) : match ($unit) {
            'piece' => $quantity < 1 ? 0.5 : 1.0,
            'g', 'ml' => match (true) {
                $quantity < 20 => 1.0,
                $quantity < 500 => 5.0,
                $quantity < 1000 => 10.0,
                default => 50.0,
            },
            'kg', 'l' => $quantity < 1 ? 0.05 : 0.1,
            'cl' => $quantity < 10 ? 0.5 : 1.0,
            'dl', 'cac', 'cas', 'verre' => 0.5,
            'pincee' => 1.0,
            default => 0.01,
        };

        return max(round(round($quantity / $step) * $step, 2), $step);
    }

    /**
     * Libellé d'une quantité recalculée : unité plus lisible au-delà d'un seuil (1 250 g → « 1,25 kg »)
     * et fractions pour ce qui se compte (« 1 ½ c. à soupe », « ½ pièce »).
     */
    public static function scaledLabel(float $quantity, string $unit, ?Ingredient $ingredient = null): string
    {
        if (self::isCustom($unit)) {
            return self::quantityLabel($quantity, $unit, $ingredient);
        }

        if ($unit === 'g' && $quantity >= 1000) {
            return self::number($quantity / 1000).' kg';
        }
        if ($unit === 'ml' && $quantity >= 1000) {
            return self::number($quantity / 1000).' L';
        }
        if ($unit === 'cl' && $quantity >= 100) {
            return self::number($quantity / 100).' L';
        }

        if (in_array($unit, ['piece', 'cac', 'cas', 'verre', 'dl'], true)) {
            $whole = (int) floor($quantity);
            $half = abs($quantity - $whole - 0.5) < 0.01;
            if ($half || abs($quantity - $whole) < 0.01) {
                $text = $half ? ($whole > 0 ? $whole.' ½' : '½') : (string) $whole;
                $plurals = ['piece' => 'pièces', 'verre' => 'verres'];
                $label = $quantity > 1 && isset($plurals[$unit]) ? $plurals[$unit] : self::label($unit);

                return $text.' '.$label;
            }
        }

        return self::quantityLabel($quantity, $unit);
    }

    /** Affichage lisible d'une quantité exprimée dans l'unité de base : 1500 g → « 1,5 kg ». */
    public static function format(float $quantity, string $baseUnit): string
    {
        return match ($baseUnit) {
            'g' => $quantity >= 1000 ? self::number($quantity / 1000).' kg' : self::number($quantity).' g',
            'ml' => match (true) {
                $quantity >= 1000 => self::number($quantity / 1000).' L',
                $quantity >= 100 && fmod($quantity, 10.0) == 0.0 => self::number($quantity / 10).' cl',
                default => self::number($quantity).' ml',
            },
            default => self::number($quantity).' '.($quantity > 1 ? 'pièces' : 'pièce'),
        };
    }

    /** Libellé du prix de référence : « €/kg », « €/L » ou « €/pièce ». */
    public static function referenceUnit(string $baseUnit): string
    {
        return match ($baseUnit) {
            'g' => 'kg',
            'ml' => 'L',
            default => 'pièce',
        };
    }

    /** Facteur entre l'unité de base et l'unité de référence du prix (1 kg = 1000 g). */
    public static function referenceFactor(string $baseUnit): int
    {
        return in_array($baseUnit, ['g', 'ml'], true) ? 1000 : 1;
    }

    /** Quantité qui se compte : « ½ », « 1 ¼ », « 3 » ; sinon le nombre (« 0,3 »). */
    public static function fraction(float $value): string
    {
        $whole = (int) floor($value + 0.001);
        $rest = $value - $whole;
        foreach (['¼' => 0.25, '½' => 0.5, '¾' => 0.75] as $glyph => $part) {
            if (abs($rest - $part) < 0.01) {
                return $whole > 0 ? $whole.' '.$glyph : $glyph;
            }
        }

        return abs($rest) < 0.01 || abs($rest - 1) < 0.01 ? (string) (int) round($value) : self::number($value);
    }

    public static function number(float $value, int $decimals = 2): string
    {
        $formatted = number_format($value, $decimals, ',', ' ');

        return str_contains($formatted, ',') ? rtrim(rtrim($formatted, '0'), ',') : $formatted;
    }

    private static function requireDensity(Ingredient $ingredient): float
    {
        if (! $ingredient->density) {
            throw new UnitConversionException("Densité inconnue pour « {$ingredient->name} » : impossible de passer du poids au volume.");
        }

        return (float) $ingredient->density;
    }

    private static function requirePieceWeight(Ingredient $ingredient): float
    {
        if (! $ingredient->piece_weight_g) {
            throw new UnitConversionException("Poids d'une pièce inconnu pour « {$ingredient->name} ».");
        }

        return (float) $ingredient->piece_weight_g;
    }

    private static function assertExists(string $unit): void
    {
        if (! self::exists($unit)) {
            throw new InvalidArgumentException("Unité inconnue : {$unit}");
        }
    }
}
