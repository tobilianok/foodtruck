<?php

namespace Tests\Unit;

use App\Support\RecipeScan\ColumnSplitter;
use App\Support\RecipeScan\RecipeTextParser;
use PHPUnit\Framework\TestCase;

/**
 * v0.14.0 : fiche Leclerc « Croziflette » (document Paperless n° 487) dont la reconnaissance de texte a mélangé
 * les deux colonnes ligne par ligne (« Les ingrédients La recette », « e 1 reblochon Etape 1 »).
 * Test sans base de données : le lecteur est une pure fonction du texte.
 */
class ColumnSplitterTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/paperless/'.$name.'.txt');
    }

    private function croziflette(): array
    {
        return RecipeTextParser::parse($this->fixture('leclerc-croziflette'), 'Croziflette');
    }

    public function test_titre_personnes_temps_source_et_relecture_demandee(): void
    {
        $r = $this->croziflette();

        $this->assertSame('Croziflette', $r['title']);
        $this->assertSame([4.0, 15], [$r['yield_quantity'], $r['prep_minutes']]);
        $this->assertSame('mesrecettes.leclerc', $r['source']);
        $this->assertCount(1, $r['issues'], 'Seule réserve : la séparation des colonnes, à vérifier');
        $this->assertStringContainsString('Colonnes ingrédients / recette mélangées', $r['issues'][0]);
    }

    public function test_ingredients_separes_des_etapes(): void
    {
        $lines = collect($this->croziflette()['ingredients']);

        $this->assertSame(['reblochon', 'oignon', 'crème fraîche', 'lardons', 'crozets', 'sol', 'poivre'], $lines->pluck('name')->all());
        $this->assertSame([1.0, 'piece'], [$lines[0]['quantity'], $lines[0]['unit']]);
        $this->assertSame([200.0, 'g'], [$lines[3]['quantity'], $lines[3]['unit']]);
        $this->assertSame([300.0, 'g'], [$lines[4]['quantity'], $lines[4]['unit']]);
        $this->assertNull($lines[6]['quantity'], 'Poivre : selon goût');
    }

    public function test_ci_lu_cl_est_corrige_et_signale(): void
    {
        $cream = collect($this->croziflette()['ingredients'])->firstWhere('name', 'crème fraîche');

        $this->assertSame([20.0, 'cl'], [$cream['quantity'], $cream['unit']]);
        $this->assertStringContainsString('« ci » lu comme « cl »', $cream['check']);
    }

    public function test_cinq_etapes_completes_dans_l_ordre(): void
    {
        $steps = array_column($this->croziflette()['steps'], 'body');

        $this->assertSame([
            "Faire cuire les crozets dans l'eau bouillante salée pendant 20 minutes.",
            "Couper l'oignon et le faire revenir dans une poêle en y ajoutant les lardons puis la crème fraîche.",
            'Égoutter les crozets et mettre une couche de ces dernières dans un plat à gratin. Ajouter une couche de crème/lardons/oignons et recommencer l\'opération.',
            'Remplir le plat de cette manière et mettre au-dessus le reblochon coupé en 2.',
            'Faire cuire au four pendant 20 minutes à 200°C.',
        ], $steps);
        $this->assertSame(20, $this->croziflette()['steps'][0]['timer'], 'Minuteur de 20 minutes');
    }

    public function test_repere_d_etape_illisible_devine_a_sa_place(): void
    {
        $text = "Les ingrédients La recette\ne 1 oignon Etape 1\nÉmincer l'oignon.\ne 2 carottes Biepeaz\nÉplucher les carottes et les couper en rondelles.\ne sel Etape 3\nCuire 10 minutes.";

        $rebuilt = ColumnSplitter::split($text);

        $this->assertStringContainsString("Etape 2\nÉplucher les carottes", $rebuilt);
        $this->assertStringContainsString("- 2 carottes\n", $rebuilt);
        $this->assertCount(3, RecipeTextParser::parse($text, 'Test')['steps']);
    }

    public function test_les_autres_fiches_ne_sont_pas_touchees(): void
    {
        foreach (['leclerc-pates-carbonara', 'julie-andrieu-gratin-courge', 'hellofresh-curry-thai-crevettes'] as $name) {
            $this->assertNull(ColumnSplitter::split($this->fixture($name)), $name);
        }
        $this->assertFalse(ColumnSplitter::isDoubleHeading('Les ingrédients'));
        $this->assertTrue(ColumnSplitter::isDoubleHeading('Les ingrédients   La recette'));
        $this->assertTrue(ColumnSplitter::isDoubleHeading('Ingrédients Préparation'));
    }
}
