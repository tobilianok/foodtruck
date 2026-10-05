<?php

namespace Tests\Unit;

use App\Support\RecipeScan\LayoutComposer;
use App\Support\RecipeScan\RecipeTextParser;
use PHPUnit\Framework\TestCase;

/**
 * v0.15.0 : fiches lues par foodtruck-ocr (blocs avec leur position) puis remises en forme par LayoutComposer.
 * Les fichiers tests/Fixtures/layout/*.json sont les vraies réponses du service sur les scans de Louis
 * (Croziflette n° 487, Orzo HelloFresh n° 483, Curry thaï HelloFresh n° 481) et sur une page générique
 * (une colonne, étapes numérotées).
 * Test sans base de données.
 */
class LayoutComposerTest extends TestCase
{
    private function read(string $fixture, string $title): array
    {
        $layout = json_decode(file_get_contents(__DIR__.'/../Fixtures/layout/'.$fixture.'.json'), true);

        return RecipeTextParser::parse(LayoutComposer::compose($layout), $title);
    }

    public function test_croziflette_colonnes_separees_par_la_position(): void
    {
        $r = $this->read('leclerc-croziflette', 'Croziflette');

        $this->assertSame([4.0, 15], [$r['yield_quantity'], $r['prep_minutes']]);
        $this->assertSame('mesrecettes.leclerc', $r['source']);
        $this->assertSame([], $r['issues'], 'Lecture sans réserve : plus de colonnes mélangées');

        $lines = collect($r['ingredients']);
        $this->assertSame(['reblochon', 'oignon', 'crème fraîche', 'lardons', 'crozets', 'sel', 'poivre'], $lines->pluck('name')->all());
        $this->assertSame([20.0, 'cl'], [$lines[2]['quantity'], $lines[2]['unit']], '« 20 cl » bien lu (Paperless lisait « 20 ci »)');

        $steps = array_column($r['steps'], 'body');
        $this->assertCount(5, $steps, '« Etapez » (étape 2 mal lue) reconnu comme repère');
        $this->assertSame("Couper l'oignon et le faire revenir dans une poêle en y ajoutant les lardons puis la crème fraîche.", $steps[1]);
        $this->assertSame('Faire cuire au four pendant 20 minutes à 200°C.', $steps[4]);
    }

    public function test_orzo_hellofresh_sans_publicite_ni_legendes_ni_valeurs_nutritionnelles(): void
    {
        $r = $this->read('hellofresh-orzo', 'Orzo aux crevettes sauce ail & persil');

        $this->assertSame([4.0, 35], [$r['yield_quantity'], $r['prep_minutes']]);
        $this->assertSame('hellofresh.fr', $r['source']);

        $names = collect($r['ingredients'])->pluck('name')->all();
        $this->assertSame(['ail', 'Poivron', 'Citron', 'Crevettes', 'Orzo', 'Épices italiennes', 'Persil', 'Crème épaisse',
            "Fromage râpé à l'italienne", 'Salade', 'Bouillon de légumes', "Huile d'olive", 'Beurre', 'Poivre', 'sel', 'Vinaigre balsamique blanc'], $names);
        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression('/QR|savoir|bord de mer|manquant|sucres|Allerg/u', $name);
        }

        $byName = collect($r['ingredients'])->keyBy('name');
        $this->assertSame([3.0, 'piece', 'gousse'], [$byName['ail']['quantity'], $byName['ail']['unit'], $byName['ail']['note']]);
        $this->assertSame([300.0, 'g'], [$byName['Orzo']['quantity'], $byName['Orzo']['unit']]);
        $this->assertSame('À ajouter vous-même', $byName['Bouillon de légumes']['group']);
        $this->assertSame([2.0, 'cac'], [$byName['Vinaigre balsamique blanc']['quantity'], $byName['Vinaigre balsamique blanc']['unit']]);
    }

    public function test_orzo_quantites_mal_lues_reparees_et_signalees(): void
    {
        $byName = collect($this->read('hellofresh-orzo', 'Orzo')['ingredients'])->keyBy('name');

        $this->assertSame([320.0, 'g'], [$byName['Crevettes']['quantity'], $byName['Crevettes']['unit']]);
        $this->assertStringContainsString('« 3208 »', $byName['Crevettes']['check']);
        $this->assertSame(1.25, $byName['Épices italiennes']['quantity']);
        $this->assertStringContainsString('fraction perdue', $byName['Épices italiennes']['check']);
        $this->assertSame(2.5, $byName['Beurre']['quantity']);
        $this->assertArrayNotHasKey('check', $byName['Orzo'], 'Une quantité bien lue n\'est pas signalée');
    }

    public function test_orzo_etapes_titrees_dans_l_ordre_et_conseil(): void
    {
        $r = $this->read('hellofresh-orzo', 'Orzo');
        $steps = array_column($r['steps'], 'body');

        $this->assertCount(4, $steps);
        $this->assertStringStartsWith('Préparer : Préparez le bouillon', $steps[0]);
        $this->assertStringStartsWith('Faire mijoter : Faites fondre le beurre', $steps[1]);
        $this->assertStringContainsString('Ôtez le couvercle', $steps[1], 'Les paragraphes de la colonne restent dans leur étape');
        $this->assertStringStartsWith('Cuire les crevettes : Pendant ce temps', $steps[2]);
        $this->assertStringStartsWith('Servir : Servez la salade', $steps[3]);
        $this->assertStringContainsString('servir la salade séparément', $r['tip']);
        $this->assertSame([], $r['issues']);
    }

    public function test_curry_hellofresh_trois_colonnes_d_etapes_a_gouttieres_etroites(): void
    {
        // v0.15.1 : gouttières de 30 px traversées par quelques mots ; tableau d'ingrédients poursuivi plus bas dans sa colonne
        $r = $this->read('hellofresh-curry-thai', 'Curry thaï léger aux crevettes & coco');

        $this->assertSame([2.0, 45], [$r['yield_quantity'], $r['prep_minutes']]);
        $this->assertSame([], $r['issues']);

        $steps = array_column($r['steps'], 'body');
        $this->assertCount(6, $steps, 'Six étapes, plus une seule grande étape mélangée');
        $titles = array_map(fn ($s) => explode(' : ', $s, 2)[0], $steps);
        $this->assertSame(['Chop, chop, chop', 'Tout baigne', 'Revettes au chaud', 'La cuisson, la suite', 'Dernier coup de poêle', 'Comment est votre curry ?'], $titles);
        $this->assertStringContainsString('Faites cuire le riz 12-14 min', $steps[1]);
        $this->assertStringContainsString('jusqu\'au service.', $steps[1], 'Le bout de ligne « jusqu\'au » reste dans sa colonne');
        $this->assertStringNotContainsString('Veillez', $steps[0], 'Consigne générale de la carte écartée');
        $this->assertStringContainsString('vitamine B12', $r['tip'], 'Encadré « ZOOM NUTRITION » en conseil');
        $this->assertStringContainsString('Si cela accroche', $r['tip'], 'Encadré « L\'ASTUCE DU CHEF » en conseil');
    }

    public function test_curry_ingredients_dont_la_suite_du_tableau_et_quantites_illisibles(): void
    {
        $byName = collect($this->read('hellofresh-curry-thai', 'Curry')['ingredients'])->keyBy('name');

        $this->assertSame(['Riz', 'Échalote', 'ail', 'Gingembre frais', 'Carotte', 'Coriandre et basilic thaï', 'Citron', 'Crevettes', 'Curry vert',
            'Lait de coco', 'Sauce poisson', 'Huile de tournesol', 'Poivre', 'sel'], $byName->keys()->all());
        $this->assertSame([1.0, 'piece', 'paquet'], [$byName['Crevettes']['quantity'], $byName['Crevettes']['unit'], $byName['Crevettes']['note']]);
        $this->assertSame([0.5, 'sachet'], [$byName['Coriandre et basilic thaï']['quantity'], $byName['Coriandre et basilic thaï']['note']]);
        $this->assertStringContainsString('illisible', $byName['Coriandre et basilic thaï']['check']);
        $this->assertSame(1.5, $byName['Huile de tournesol']['quantity'], '« 1% cs » lu pour 1½ cs');
        $this->assertStringContainsString('« 1% »', $byName['Huile de tournesol']['check']);
        $this->assertStringContainsString('centimètres', $byName['Gingembre frais']['check']);
    }

    public function test_page_generique_une_colonne_etapes_numerotees(): void
    {
        $r = $this->read('generique-veloute', 'Velouté de potimarron');

        $this->assertSame([4.0, 15, 30], [$r['yield_quantity'], $r['prep_minutes'], $r['cook_minutes']]);
        $this->assertSame(['potimarron', 'oignons', 'crème liquide', 'bouillon de légumes', 'sel', 'poivre'], collect($r['ingredients'])->pluck('name')->all());
        $this->assertCount(3, $r['steps']);
        $this->assertStringStartsWith('Éplucher le potimarron', $r['steps'][0]['body']);
        $this->assertStringEndsWith('servir bien chaud.', $r['steps'][2]['body']);
        $this->assertStringStartsWith('Ajoutez quelques graines', $r['tip']);
    }

    public function test_mise_en_page_vide(): void
    {
        $this->assertSame('', LayoutComposer::compose([]));
        $this->assertSame('', LayoutComposer::compose(['pages' => [['width' => 100, 'height' => 100, 'blocks' => []]]]));
    }
}
