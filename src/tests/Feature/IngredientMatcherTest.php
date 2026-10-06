<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Support\RecipeScan\IngredientMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** v0.15.2 : rapprochement des libellés lus sur les scans (couleurs, petites fautes de lecture). */
class IngredientMatcherTest extends TestCase
{
    use RefreshDatabase;

    private function matcher(): IngredientMatcher
    {
        $names = ['Courgette', 'Poivron', 'Oignon rouge', 'Oignon', 'Tomate', 'Aubergine', 'Chou rouge', 'Vin rouge', 'Carotte',
            'Haricots verts frais', 'Curry vert', 'Pâtes (spaghetti, penne…)'];

        return new IngredientMatcher(collect($names)->map(function (string $name, int $i) {
            $ingredient = new Ingredient;
            $ingredient->forceFill(['id' => $i + 1, 'name' => $name]);

            return $ingredient;
        }));
    }

    public function test_une_couleur_que_l_ingredient_ne_precise_pas_est_ignoree(): void
    {
        $m = $this->matcher();

        $this->assertSame('Poivron', $m->match('poivron vert')['ingredient']?->name);
        $this->assertSame('Poivron', $m->match('poivrons rouges')['ingredient']?->name);
        $this->assertSame('Oignon rouge', $m->match('oignon rouge')['ingredient']?->name, 'Le nom le plus précis l\'emporte');
        $this->assertSame('Oignon', $m->match('oignon blanc')['ingredient']?->name);
        $this->assertSame('Haricots verts frais', $m->match('haricots verts')['ingredient']?->name);
    }

    public function test_une_couleur_qui_contredit_l_ingredient_n_est_jamais_ignoree(): void
    {
        $m = $this->matcher();

        $this->assertNull($m->match('vin blanc')['ingredient'], 'Pas de vin rouge à la place du vin blanc');
        $this->assertNull($m->match('curry rouge')['ingredient']);
        $this->assertNotContains('Oignon rouge', $m->match('curry rouge')['candidates'], 'Une couleur commune ne suffit pas à proposer');
    }

    public function test_petite_faute_de_lecture_proposee_a_confirmer(): void
    {
        $m = $this->matcher();

        $courgette = $m->match('courgeties');
        $this->assertSame(['Courgette', 'approchant'], [$courgette['ingredient']?->name, $courgette['via']]);
        $this->assertSame('approchant', $m->match('Carote')['via']);
        $this->assertSame('nom', $m->match('courgettes')['via'], 'Un pluriel n\'est pas une faute');
        $this->assertNull($m->match('tt')['ingredient'], 'Trop court pour deviner');
        $this->assertNull($m->match('pâte à tartiner')['ingredient']);
    }
}
