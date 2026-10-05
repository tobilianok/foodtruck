<?php

namespace App\Support;

use App\Models\Ingredient;
use App\Models\IngredientPack;
use App\Models\Price;

/**
 * Passe d'un besoin (60 cl de lait) à ce qu'on met dans le caddie (1 brique de 1 L).
 *
 * Dans un magasin donné, cherche la combinaison de conditionnements la moins chère qui couvre le besoin
 * (à l'ordre de grandeur près : un manque de 2 % ou 15 g/ml n'oblige pas à racheter un paquet).
 * Les produits vendus au poids (vrac) s'achètent à la quantité utile, arrondie au pas de pesée.
 *
 * Relations attendues : packs.prices
 */
class PackPlanner
{
    /** Manque toléré : 2 % du besoin, au plus 15 g ou ml (jamais sur des pièces). */
    private const TOLERANCE_RATIO = 0.02;

    private const TOLERANCE_MAX = 15.0;

    /** Au-delà, on ne teste plus que les paquets pris seuls (évite une explosion de combinaisons). */
    private const MAX_COMBINATIONS = 20000;

    /**
     * @return array{parts: array<int, array<string, mixed>>, cents: int, bought: float, surplus: float}|null
     *                                                                                                       null = aucun conditionnement n'a de prix dans ce magasin
     */
    public static function plan(Ingredient $ingredient, ?float $needed, int $storeId): ?array
    {
        $options = [];
        foreach ($ingredient->packs as $pack) {
            $pack->setRelation('ingredient', $ingredient);
            $price = $pack->currentPriceFor($storeId);
            if ($price !== null && $pack->quantity > 0) {
                $options[] = ['pack' => $pack, 'price' => $price];
            }
        }

        if ($options === []) {
            return null;
        }

        $needed = $needed !== null && $needed > 0.0001 ? $needed : null;
        $unit = $ingredient->base_unit;

        $bulk = array_values(array_filter($options, fn ($o) => $o['pack']->is_bulk));
        $fixed = array_values(array_filter($options, fn ($o) => ! $o['pack']->is_bulk));

        // Pas de besoin chiffré (« selon goût », unité non convertible) : un seul article, le moins cher
        if ($needed === null) {
            $cheapest = collect($fixed ?: $bulk)->sortBy(fn ($o) => $o['price']->price_cents)->first();

            return $cheapest['pack']->is_bulk
                ? self::result([self::bulkPart($cheapest, self::bulkStep($unit))], 0.0)
                : self::result([self::fixedPart($cheapest, 1)], 0.0);
        }

        $candidates = [];

        foreach ($bulk as $option) {
            $step = self::bulkStep($unit);
            $candidates[] = self::result([self::bulkPart($option, ceil($needed / $step - 1e-9) * $step)], $needed);
        }

        if ($fixed !== []) {
            $candidates[] = self::bestCombination($fixed, $needed, $unit);
        }

        $candidates = array_filter($candidates);

        return $candidates === [] ? null : collect($candidates)->sort(self::compare(...))->first();
    }

    /**
     * Magasin le moins cher pour ce besoin parmi ceux proposés.
     *
     * @param  array<int, int>  $storeIds
     * @return array{store_id: int, cents: int}|null
     */
    public static function cheapest(Ingredient $ingredient, ?float $needed, array $storeIds): ?array
    {
        $best = null;
        foreach ($storeIds as $storeId) {
            $plan = self::plan($ingredient, $needed, $storeId);
            if ($plan !== null && ($best === null || $plan['cents'] < $best['cents'])) {
                $best = ['store_id' => $storeId, 'cents' => $plan['cents']];
            }
        }

        return $best;
    }

    /** Coût de la quantité réellement utilisée, au meilleur prix au kg / L / pièce du magasin (centimes). */
    public static function usedCents(Ingredient $ingredient, ?float $needed, int $storeId): ?int
    {
        if ($needed === null || $needed <= 0) {
            return null;
        }

        $best = null;
        foreach ($ingredient->packs as $pack) {
            $pack->setRelation('ingredient', $ingredient);
            $price = $pack->currentPriceFor($storeId);
            if ($price === null || $pack->quantity <= 0) {
                continue;
            }
            $perReference = $pack->perReferenceCents($price);
            $best = $best === null ? $perReference : min($best, $perReference);
        }

        return $best === null ? null : (int) round($needed * $best / Units::referenceFactor($ingredient->base_unit));
    }

    /** Pas d'achat d'un produit au poids : 50 g, 50 ml, 1 pièce. */
    public static function bulkStep(string $baseUnit): float
    {
        return $baseUnit === 'piece' ? 1.0 : 50.0;
    }

    /** @param  array<int, array{pack: IngredientPack, price: Price}>  $options */
    private static function bestCombination(array $options, float $needed, string $unit): ?array
    {
        $tolerance = $unit === 'piece' ? 0.0 : min($needed * self::TOLERANCE_RATIO, self::TOLERANCE_MAX);
        $target = $needed - $tolerance;

        $packs = array_map(fn ($o) => ['option' => $o, 'qty' => (float) $o['pack']->quantity, 'cents' => (int) $o['price']->price_cents], $options);
        usort($packs, fn ($a, $b) => $b['qty'] <=> $a['qty']);

        $max = array_map(fn ($p) => max(1, (int) ceil($target / $p['qty'] - 1e-9)), $packs);
        $space = array_product(array_map(fn ($m) => $m + 1, $max));

        $best = null;
        $consider = function (array $counts, float $qty, int $cents) use (&$best, $packs, $needed) {
            $total = array_sum($counts);
            $surplus = $qty - $needed;
            if ($best === null
                || $cents < $best['cents']
                || ($cents === $best['cents'] && ($surplus < $best['surplus'] - 1e-6 || (abs($surplus - $best['surplus']) < 1e-6 && $total < $best['total'])))) {
                $best = ['counts' => $counts, 'cents' => $cents, 'surplus' => $surplus, 'total' => $total];
            }
        };

        if ($space > self::MAX_COMBINATIONS) {
            foreach ($packs as $i => $p) {
                $counts = array_fill(0, count($packs), 0);
                $counts[$i] = $max[$i];
                $consider($counts, $max[$i] * $p['qty'], $max[$i] * $p['cents']);
            }
        } else {
            $rec = function (int $i, array $counts, float $qty, int $cents) use (&$rec, &$best, $packs, $max, $target, $consider) {
                if ($best !== null && $cents > $best['cents']) {
                    return;
                }
                if ($qty >= $target - 1e-6) {
                    $consider($counts, $qty, $cents);

                    return;
                }
                if ($i >= count($packs)) {
                    return;
                }
                for ($c = 0; $c <= $max[$i]; $c++) {
                    $counts[$i] = $c;
                    $newQty = $qty + $c * $packs[$i]['qty'];
                    $rec($i + 1, $counts, $newQty, $cents + $c * $packs[$i]['cents']);
                    // Dès que ce paquet couvre le besoin, en ajouter d'autres ne fait que coûter plus cher
                    if ($newQty >= $target - 1e-6) {
                        break;
                    }
                }
            };
            $rec(0, array_fill(0, count($packs), 0), 0.0, 0);
        }

        if ($best === null) {
            return null;
        }

        $parts = [];
        foreach ($best['counts'] as $i => $count) {
            if ($count > 0) {
                $parts[] = self::fixedPart($packs[$i]['option'], $count);
            }
        }

        return self::result($parts, $needed);
    }

    /** @return array<string, mixed> */
    private static function fixedPart(array $option, int $count): array
    {
        /** @var IngredientPack $pack */
        $pack = $option['pack'];

        return [
            'pack_id' => $pack->id,
            'label' => $pack->label,
            'count' => $count,
            'quantity' => round($pack->quantity * $count, 3),
            'unit_cents' => (int) $option['price']->price_cents,
            'cents' => (int) $option['price']->price_cents * $count,
            'bulk' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function bulkPart(array $option, float $quantity): array
    {
        /** @var IngredientPack $pack */
        $pack = $option['pack'];

        return [
            'pack_id' => $pack->id,
            'label' => $pack->label,
            'count' => 1,
            'quantity' => round($quantity, 3),
            'unit_cents' => (int) $option['price']->price_cents,
            'cents' => (int) round($quantity / $pack->quantity * $option['price']->price_cents),
            'bulk' => true,
        ];
    }

    /** @param  array<int, array<string, mixed>>  $parts */
    private static function result(array $parts, float $needed): array
    {
        $bought = (float) array_sum(array_column($parts, 'quantity'));

        return [
            'parts' => $parts,
            'cents' => (int) array_sum(array_column($parts, 'cents')),
            'bought' => $bought,
            'surplus' => max(0.0, round($bought - $needed, 3)),
        ];
    }

    private static function compare(array $a, array $b): int
    {
        return [$a['cents'], $a['surplus']] <=> [$b['cents'], $b['surplus']];
    }
}
