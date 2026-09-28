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

    public static function dimension(string $unit): string
    {
        self::assertExists($unit);

        return self::UNITS[$unit][1];
    }

    public static function label(string $unit): string
    {
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
