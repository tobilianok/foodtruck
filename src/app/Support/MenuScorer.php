<?php

namespace App\Support;

/**
 * Cœur du menu automatique : donne une note à chaque recette candidate pour un repas précis.
 *
 * Classe pure (aucune base de données) : elle reçoit des tableaux et rend un classement, ce qui la rend testable
 * et prévisible. Les règles, par ordre d'importance :
 *  - jamais deux fois la même protéine à la suite (et pas plus de deux fois dans la semaine) ;
 *  - le nombre de repas végétariens demandé est atteint avant la fin de la semaine ;
 *  - en semaine (lundi → vendredi), des plats prêts en 30 minutes ou moins ;
 *  - le budget : chaque repas vise sa part du budget restant ;
 *  - les produits à consommer vite du stock d'abord, puis ce que le stock couvre déjà ;
 *  - les recettes de saison ; les favoris ; pas de plat déjà cuisiné récemment.
 *
 * Candidat : id, title, protein (?string), veggy (bool), minutes (?int), season (?bool), difficulty (string),
 *   cost1 (?float : coût en centimes de la recette pour son rendement), yield (float : rendement en personnes),
 *   cost_complete (bool), expiring (int : produits à consommer vite utilisés), expiring_names (string[]),
 *   cover (float 0..1 : part des ingrédients déjà en stock), favorite (bool), last_cooked_days (?int).
 * Repas : date (Y-m-d), weekday (1 = lundi … 7), parts (parts d'un repas pour les convives), meals (nombre de repas du plat).
 * État : budget_cents (int, 0 = pas de budget), remaining_cents (float), slots_left (int, repas encore libres, celui-ci compris),
 *   veggy_min (int), veggy_count (int), protein_counts (array<string,int>), neighbours (array<int,?string> : protéines du plat
 *   d'avant et du plat d'après ce repas).
 */
class MenuScorer
{
    public const PROTEIN_NEIGHBOUR = -70;

    public const PROTEIN_REPEAT = -12;

    public const OVER_BUDGET = -60;

    public const COST_WEIGHT = 30;

    public const EXPIRING = 22;

    public const COVER = 14;

    public const SEASON_IN = 12;

    public const SEASON_OUT = -10;

    public const QUICK = 22;

    public const SLOW_WEEKDAY = -26;

    public const FAVORITE = 8;

    /** Part de repas qui seront de vrais plats (les autres sont des restes) : sert à estimer les plats encore à cuisiner. */
    private const DISHES_RATIO = 0.65;

    /** Coût en centimes du plat pour ce repas (null si la recette n'a pas de prix connu). */
    public static function cost(array $candidate, array $slot): ?float
    {
        if ($candidate['cost1'] === null || $candidate['yield'] <= 0) {
            return null;
        }

        return $candidate['cost1'] * $slot['parts'] * $slot['meals'] / $candidate['yield'];
    }

    /**
     * Note d'une recette pour ce repas.
     *
     * @return array{score: float, cost_cents: ?float, reasons: array<int, string>}
     */
    public static function score(array $candidate, array $slot, array $state, int $seed = 0): array
    {
        $score = 0.0;
        $reasons = [];
        $cost = self::cost($candidate, $slot);

        // Budget : chaque repas vise sa part du budget restant
        if ($cost === null) {
            $score -= 12;
        } elseif ($state['budget_cents'] > 0) {
            $fair = max(1.0, $state['remaining_cents'] / max(1, $state['slots_left']) * $slot['meals']);
            $score += self::COST_WEIGHT * max(-1.5, min(1.0, ($fair - $cost) / $fair));
            if ($cost > $state['remaining_cents']) {
                $score += self::OVER_BUDGET;
            }
            if (! $candidate['cost_complete']) {
                $score -= 10;
            }
        }

        // Stock : produits à consommer vite d'abord, puis ce qui est déjà couvert
        if ($candidate['expiring'] > 0) {
            $score += self::EXPIRING * min(2, $candidate['expiring']);
            $names = array_slice($candidate['expiring_names'], 0, 2);
            $reasons[] = $names !== [] ? 'utilise '.implode(' et ', array_map('mb_strtolower', $names)).' à consommer vite' : 'utilise un produit à consommer vite';
        }
        if ($candidate['cover'] > 0) {
            $score += self::COVER * $candidate['cover'];
            if ($candidate['cover'] >= 0.5 && $candidate['expiring'] === 0) {
                $reasons[] = 'déjà en grande partie dans vos placards';
            }
        }

        // Saison
        if ($candidate['season'] === true) {
            $score += self::SEASON_IN;
            $reasons[] = 'de saison';
        } elseif ($candidate['season'] === false) {
            $score += self::SEASON_OUT;
        }

        // Rapide en semaine
        if ($slot['weekday'] <= 5) {
            if ($candidate['minutes'] === null) {
                $score -= 6;
            } elseif ($candidate['minutes'] <= 30) {
                $score += self::QUICK;
                $reasons[] = 'prêt en '.$candidate['minutes'].' min';
            } elseif ($candidate['minutes'] > 45) {
                $score += self::SLOW_WEEKDAY;
            }
            if ($candidate['difficulty'] === 'difficile') {
                $score -= 10;
            }
        } elseif ($candidate['minutes'] !== null && $candidate['minutes'] > 45) {
            $score += 4;
        }

        // Variété des protéines
        $protein = in_array($candidate['protein'], [null, '', 'aucune'], true) ? null : $candidate['protein'];
        if ($protein !== null) {
            if (in_array($protein, $state['neighbours'], true)) {
                $score += self::PROTEIN_NEIGHBOUR;
            }
            $score += self::PROTEIN_REPEAT * max(0, ($state['protein_counts'][$protein] ?? 0) - 1);
        }

        // Repas végétariens : l'objectif doit être atteint avant la fin de la semaine
        $needed = max(0, $state['veggy_min'] - $state['veggy_count']);
        if ($needed > 0) {
            $dishesLeft = max(1, (int) ceil($state['slots_left'] * self::DISHES_RATIO));
            if ($candidate['veggy']) {
                $score += min(80.0, 40.0 * $needed / $dishesLeft);
                $reasons[] = 'végétarien';
            } elseif ($needed >= $dishesLeft) {
                $score -= 90;
            }
        }

        if ($candidate['favorite']) {
            $score += self::FAVORITE;
            $reasons[] = 'un de vos favoris';
        }

        // Pas de plat déjà cuisiné récemment
        $days = $candidate['last_cooked_days'];
        if ($days === null) {
            $score += 3;
        } elseif ($days <= 7) {
            $score -= 45;
        } elseif ($days <= 14) {
            $score -= 30;
        } elseif ($days <= 28) {
            $score -= 15;
        }

        // Petit aléa reproductible : deux propositions successives ne sont pas identiques
        $score += ((crc32($seed.'-'.$candidate['id'].'-'.$slot['date'].'-'.($slot['kind'] ?? '')) % 1000) / 1000 - 0.5) * 6;

        if ($cost !== null) {
            array_unshift($reasons, number_format($cost / 100, 2, ',', ' ').' € pour ce repas');
        }

        return ['score' => $score, 'cost_cents' => $cost, 'reasons' => $reasons];
    }

    /**
     * Classement des candidats pour ce repas, du meilleur au moins bon.
     *
     * @param  array<int, array>  $candidates
     * @return array<int, array{candidate: array, score: float, cost_cents: ?float, reasons: array<int, string>}>
     */
    public static function rank(array $candidates, array $slot, array $state, int $seed = 0): array
    {
        $ranked = [];
        foreach ($candidates as $candidate) {
            $ranked[] = ['candidate' => $candidate] + self::score($candidate, $slot, $state, $seed);
        }

        usort($ranked, fn ($a, $b) => [$b['score'], $a['candidate']['id']] <=> [$a['score'], $b['candidate']['id']]);

        return $ranked;
    }
}
