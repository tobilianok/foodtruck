<?php

namespace Tests\Unit;

use App\Support\MenuScorer;
use PHPUnit\Framework\TestCase;

/**
 * v0.13.0 : règles du menu automatique. Classe pure, testée sans base de données.
 */
class MenuScorerTest extends TestCase
{
    private function recipe(int $id, array $override = []): array
    {
        return $override + [
            'id' => $id, 'title' => 'Recette '.$id, 'protein' => 'volaille', 'veggy' => false, 'minutes' => 40, 'season' => null,
            'difficulty' => 'facile', 'cost1' => 800.0, 'yield' => 4.0, 'cost_complete' => true, 'expiring' => 0,
            'expiring_names' => [], 'cover' => 0.0, 'favorite' => false, 'last_cooked_days' => null,
        ];
    }

    private function slot(array $override = []): array
    {
        return $override + ['date' => '2026-10-07', 'weekday' => 3, 'kind' => 'diner', 'parts' => 2.5, 'meals' => 1];
    }

    private function state(array $override = []): array
    {
        return $override + [
            'budget_cents' => 10000, 'remaining_cents' => 7000.0, 'slots_left' => 10, 'veggy_min' => 0, 'veggy_count' => 0,
            'protein_counts' => [], 'neighbours' => [],
        ];
    }

    private function best(array $candidates, array $slot = [], array $state = [], int $seed = 1): array
    {
        return MenuScorer::rank($candidates, $this->slot($slot), $this->state($state), $seed)[0];
    }

    public function test_cout_du_plat_pour_ce_repas(): void
    {
        // 800 centimes pour 4 personnes, 2,5 parts, 2 repas : 800 × 2,5 × 2 / 4 = 1000 centimes
        $this->assertEqualsWithDelta(1000.0, MenuScorer::cost($this->recipe(1), $this->slot(['meals' => 2])), 0.001);
        $this->assertNull(MenuScorer::cost($this->recipe(1, ['cost1' => null]), $this->slot()));
    }

    public function test_budget_serre_choisit_le_moins_cher(): void
    {
        $cheap = $this->recipe(1, ['cost1' => 400.0, 'protein' => 'oeufs']);
        $dear = $this->recipe(2, ['cost1' => 2400.0, 'protein' => 'boeuf']);

        $this->assertSame(1, $this->best([$dear, $cheap], [], ['remaining_cents' => 3000.0, 'slots_left' => 8])['candidate']['id']);
    }

    public function test_plat_hors_budget_restant_est_tres_penalise(): void
    {
        $dear = MenuScorer::score($this->recipe(1, ['cost1' => 4000.0]), $this->slot(), $this->state(['remaining_cents' => 500.0]));
        $fine = MenuScorer::score($this->recipe(1, ['cost1' => 400.0]), $this->slot(), $this->state(['remaining_cents' => 500.0]));

        $this->assertLessThan($fine['score'] - 50, $dear['score']);
    }

    public function test_produit_a_consommer_vite_passe_avant(): void
    {
        $plain = $this->recipe(1, ['cost1' => 600.0, 'protein' => 'porc']);
        $stock = $this->recipe(2, ['cost1' => 900.0, 'protein' => 'poisson', 'expiring' => 1, 'expiring_names' => ['Poireaux'], 'cover' => 0.5]);

        $best = $this->best([$plain, $stock]);

        $this->assertSame(2, $best['candidate']['id']);
        $this->assertContains('utilise poireaux à consommer vite', $best['reasons']);
    }

    public function test_jamais_la_meme_proteine_a_la_suite(): void
    {
        $chicken = $this->recipe(1, ['protein' => 'volaille', 'cost1' => 500.0]);
        $fish = $this->recipe(2, ['protein' => 'poisson', 'cost1' => 900.0]);

        $this->assertSame(2, $this->best([$chicken, $fish], [], ['neighbours' => ['volaille', null]])['candidate']['id']);
        $this->assertSame(1, $this->best([$chicken, $fish], [], ['neighbours' => []])['candidate']['id']);
    }

    public function test_pas_plus_de_deux_fois_la_meme_proteine_dans_la_semaine(): void
    {
        $chicken = MenuScorer::score($this->recipe(1, ['protein' => 'volaille']), $this->slot(), $this->state(['protein_counts' => ['volaille' => 3]]));
        $none = MenuScorer::score($this->recipe(1, ['protein' => 'aucune']), $this->slot(), $this->state(['protein_counts' => ['volaille' => 3]]));

        $this->assertLessThan($none['score'] - 20, $chicken['score']);
    }

    public function test_objectif_vegetarien_atteint_avant_la_fin_de_la_semaine(): void
    {
        $meat = $this->recipe(1, ['protein' => 'boeuf', 'cost1' => 500.0]);
        $veggy = $this->recipe(2, ['protein' => 'legumineuses', 'veggy' => true, 'cost1' => 900.0]);

        // 2 repas végétariens voulus, aucun encore, 2 repas libres : le plat végétarien s'impose
        $this->assertSame(2, $this->best([$meat, $veggy], [], ['veggy_min' => 2, 'veggy_count' => 0, 'slots_left' => 2])['candidate']['id']);

        // Objectif déjà atteint : plus de bonus pour le plat végétarien
        $reached = MenuScorer::score($veggy, $this->slot(), $this->state(['veggy_min' => 2, 'veggy_count' => 2, 'slots_left' => 2]), 1);
        $free = MenuScorer::score($veggy, $this->slot(), $this->state(['veggy_min' => 0, 'veggy_count' => 0, 'slots_left' => 2]), 1);
        $this->assertEqualsWithDelta($free['score'], $reached['score'], 0.0001);
    }

    public function test_plats_rapides_en_semaine_et_longs_le_week_end(): void
    {
        $quick = $this->recipe(1, ['minutes' => 20, 'protein' => 'porc']);
        $slow = $this->recipe(2, ['minutes' => 120, 'protein' => 'boeuf']);

        $weekday = MenuScorer::rank([$slow, $quick], $this->slot(['weekday' => 2]), $this->state(), 1);
        $weekend = MenuScorer::rank([$slow, $quick], $this->slot(['weekday' => 6]), $this->state(), 1);

        $this->assertSame(1, $weekday[0]['candidate']['id']);
        $this->assertContains('prêt en 20 min', $weekday[0]['reasons']);
        // Le week end, plus de pénalité sur le plat long : l'écart se resserre
        $this->assertLessThan(abs($weekday[0]['score'] - $weekday[1]['score']), abs($weekend[0]['score'] - $weekend[1]['score']));
    }

    public function test_saison_favoris_et_plats_recents(): void
    {
        $season = MenuScorer::score($this->recipe(1, ['season' => true]), $this->slot(), $this->state(), 1);
        $out = MenuScorer::score($this->recipe(1, ['season' => false]), $this->slot(), $this->state(), 1);
        $this->assertGreaterThan($out['score'] + 20, $season['score']);
        $this->assertContains('de saison', $season['reasons']);

        $fresh = MenuScorer::score($this->recipe(1), $this->slot(), $this->state(), 1);
        $recent = MenuScorer::score($this->recipe(1, ['last_cooked_days' => 5]), $this->slot(), $this->state(), 1);
        $this->assertGreaterThan($recent['score'] + 40, $fresh['score']);

        $fav = MenuScorer::score($this->recipe(1, ['favorite' => true]), $this->slot(), $this->state(), 1);
        $this->assertContains('un de vos favoris', $fav['reasons']);
    }

    public function test_classement_reproductible_et_variable_selon_la_graine(): void
    {
        $list = array_map(fn ($i) => $this->recipe($i, ['protein' => 'p'.$i]), range(1, 12));

        $a = array_column(array_column(MenuScorer::rank($list, $this->slot(), $this->state(), 7), 'candidate'), 'id');
        $b = array_column(array_column(MenuScorer::rank($list, $this->slot(), $this->state(), 7), 'candidate'), 'id');
        $c = array_column(array_column(MenuScorer::rank($list, $this->slot(), $this->state(), 8), 'candidate'), 'id');

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    public function test_la_raison_annonce_le_cout_du_repas(): void
    {
        $r = MenuScorer::score($this->recipe(1), $this->slot(), $this->state(), 1);

        $this->assertSame('5,00 € pour ce repas', $r['reasons'][0]);
    }
}
