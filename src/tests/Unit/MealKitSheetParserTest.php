<?php

namespace Tests\Unit;

use App\Support\RecipeScan\MealKitSheetParser;
use App\Support\RecipeScan\RecipeTextParser;
use App\Support\RecipeScan\TextCleaner;
use PHPUnit\Framework\TestCase;

/**
 * v0.12.1 : fiches de kits repas (HelloFresh) scannées - texte Paperless réel à colonnes entrelacées.
 * Test sans base de données : le lecteur est une pure fonction du texte.
 */
class MealKitSheetParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/paperless/'.$name.'.txt');
    }

    private function curry(): array
    {
        return RecipeTextParser::parse($this->fixture('hellofresh-curry-thai-crevettes'), 'Curry thaïléger aux crevettes & coco');
    }

    public function test_le_format_est_reconnu_et_pas_les_autres(): void
    {
        $this->assertTrue(MealKitSheetParser::detects(TextCleaner::clean($this->fixture('hellofresh-curry-thai-crevettes'))['text']));
        $this->assertFalse(MealKitSheetParser::detects(TextCleaner::clean($this->fixture('julie-andrieu-gratin-courge'))['text']));
        $this->assertFalse(MealKitSheetParser::detects("Tarte\nIngrédients pour 4 personnes\n- 1 pâte\nValeurs nutritionnelles\n"));
    }

    public function test_titre_personnes_temps_et_source(): void
    {
        $recipe = $this->curry();

        $this->assertSame('kit', $recipe['layout']);
        $this->assertSame('Curry thaïléger aux crevettes & coco', $recipe['title']);
        $this->assertEquals(2, $recipe['yield_quantity']);
        $this->assertSame(45, $recipe['prep_minutes']);
        $this->assertSame('HelloFresh (semaine 33, 2025)', $recipe['source']);
        $this->assertSame('plat', $recipe['category']);
        $this->assertStringStartsWith('Avec des herbes fraîches & du riz. À table en 35-45 min.', $recipe['description']);
        $this->assertStringContainsString('Ustensiles : Râpe, casserole avec couvercle', $recipe['description']);
    }

    public function test_ingredients_nom_puis_quantite(): void
    {
        $rows = collect($this->curry()['ingredients'])->map(fn ($i) => [$i['name'], $i['quantity'], $i['unit'], $i['note'], $i['group']])->all();

        $this->assertSame([
            ['Riz', 150.0, 'g', null, null],
            ['Échalote', 1.0, 'piece', null, null],
            ["Gousse d'ail", 1.0, 'piece', null, null],
            ['Gingembre frais', 1.0, 'piece', '1 cm', null],
            ['Carotte', 1.0, 'piece', null, null],
            ['Coriandre et basilic thaï', 0.5, 'piece', 'sachet', null],
            ['Citron', 0.5, 'piece', null, null],
            ['Crevettes', 1.0, 'piece', 'paquet', null],
            ['Curry vert', 1.0, 'piece', 'sachet', null],
            ['Sauce poisson', 0.5, 'piece', 'sachet', null],
            ['Huile de tournesol', 1.5, 'cas', null, 'À ajouter vous-même'],
            ['Poivre', null, null, 'selon votre goût', 'À ajouter vous-même'],
            ['sel', null, null, null, 'À ajouter vous-même'],
            ['Lait de coco', 1.0, 'piece', 'paquet', null],
        ], $rows);
    }

    public function test_fractions_perdues_signalees_a_la_relecture(): void
    {
        $byName = collect($this->curry()['ingredients'])->keyBy('name');

        // ½ lu « % », « # » ou « Z » ; « 1½ cs » lu « 12 cs » : valeur proposée, ligne à vérifier
        foreach (['Coriandre et basilic thaï', 'Citron', 'Sauce poisson', 'Huile de tournesol', 'Gingembre frais'] as $name) {
            $this->assertNotEmpty($byName[$name]['check'], $name);
        }
        $this->assertStringContainsString('« 12 cs »', $byName['Huile de tournesol']['check']);
        $this->assertEmpty($byName['Riz']['check']);
        $this->assertEmpty($byName['Crevettes']['check']);
    }

    public function test_ligne_du_tableau_absorbee_retrouvee_par_la_legende_et_les_etapes(): void
    {
        $milk = collect($this->curry()['ingredients'])->firstWhere('name', 'Lait de coco');

        $this->assertSame([1.0, 'piece', 'paquet'], [$milk['quantity'], $milk['unit'], $milk['note']]);
        $this->assertStringContainsString('retrouvé dans les étapes', $milk['check']);
    }

    public function test_six_etapes_dans_l_ordre_de_la_carte(): void
    {
        $steps = $this->curry()['steps'];
        $bodies = array_column($steps, 'body');

        $this->assertCount(6, $steps);
        $titles = array_map(fn ($b) => explode(' — ', $b)[0], $bodies);
        $this->assertSame(['Chop, chop, chop', 'Tout baigne', 'Crevettes au chaud', 'La cuisson, la suite', 'Dernier coup de poêle', 'Comment est votre curry ?'], $titles);

        $this->assertSame("Chop, chop, chop — Veillez à bien respecter les quantités indiquées à gauche pour préparer votre recette ! Portez une grande casserole d'eau salée à ébullition pour le riz. Ciselez l'échalote et l'ail. Râpez finement le gingembre (ça pique ! Dosez-le selon votre goût). Épluchez la carotte et coupez-la en très fines demi-lunes de 3 mm. Effeuillez et ciselez la coriandre et le basilic thaï. Coupez le citron en quartiers.", $bodies[0]);
        $this->assertSame("Tout baigne — Faites cuire le riz 12-14 min dans la casserole, ou jusqu'à ce qu'il soit tendre. Égouttez-le et réservez-le à couvert jusqu'au service.", $bodies[1]);
        $this->assertSame("Crevettes au chaud — Épongez les crevettes avec un essuie-tout. Faites chauffer un filet d'huile de tournesol dans un wok ou une sauteuse à feu moyen-vif. Faites-y revenir les crevettes 4-5 min avec ½ cc de curry par personne (ça pique ! Dosez-le selon votre goût), ou jusqu'à ce qu'elles soient bien dorées, puis réservez-les hors du wok.", $bodies[2]);
        $this->assertStringContainsString("Remettez le wok sur feu moyen avec un petit filet d'huile et faites-y revenir l'échalote, l'ail, le gingembre et", $bodies[3]);
        $this->assertStringContainsString("Ajoutez-y la carotte, 1 cs d'eau par personne, et faites-la cuire 8-10 min à couvert, en remuant de temps en temps.", $bodies[3]);
        $this->assertSame("Dernier coup de poêle — Secouez le paquet de lait de coco jusqu'à ce que les éventuels grumeaux se décomposent. Ajoutez-le, ainsi que ¼ sachet de sauce poisson par personne au wok. Mélangez, puis couvrez et laissez mijoter 5-6 min, ou jusqu'à ce que les carottes soient fondantes. Ajoutez les crevettes et prolongez la cuisson de 2 min. Retirez le wok du feu.", $bodies[4]);
        $this->assertSame("Comment est votre curry ? — Servez le riz et le curry thaï dans les assiettes. Saupoudrez le tout de coriandre et de basilic thaï. Pressez quelques gouttes de citron sur l'ensemble du plat (selon votre goût).", $bodies[5]);

        // Rien du tableau des ingrédients ni des valeurs nutritionnelles ne doit rester dans les étapes
        foreach ($bodies as $body) {
            $this->assertDoesNotMatchRegularExpression('/pièce\(s\)|sachet\(s\)|Énergie|Lipides|Glucides|Protéines|ASTUCE|ZOOM/u', $body);
        }
        $this->assertSame([null, 14, 5, null, null, null], array_column($steps, 'timer'));
    }

    public function test_encadres_conseil_a_part(): void
    {
        $tip = $this->curry()['tip'];

        $this->assertStringContainsString("L'astuce du chef : Si cela accroche, ajoutez un petit filet d'eau supplémentaire.", $tip);
        $this->assertStringContainsString('Zoom nutrition : Les crevettes dans cette recette sont source de vitamine B12', $tip);
    }

    public function test_une_fiche_de_ce_format_n_est_jamais_publiee_toute_seule(): void
    {
        $issues = $this->curry()['issues'];

        $this->assertStringContainsString('Fiche à colonnes (kit repas)', $issues[0]);
        $this->assertContains('Fraction illisible dans une étape (½ ou ¼ supposé) : à vérifier avec le PDF.', $issues);
        $this->assertNotEmpty(array_filter($issues, fn ($i) => str_contains($i, '« ½ » mal lu')));
    }

    public function test_mots_colles_par_la_reconnaissance_de_texte(): void
    {
        $method = new \ReflectionMethod(MealKitSheetParser::class, 'repair');
        $count = 0;

        $this->assertSame('Remettez avec un petit filet d\'huile.', $method->invokeArgs(null, ["Remettez avecunpetitfilet d'huile.", &$count]));
        $this->assertSame('Servez le riz et le curry thaï dans les assiettes.', $method->invokeArgs(null, ['Servezlerizet le currythaï dans les assiettes.', &$count]));
        $this->assertSame('Ajoutez-y la carotte', $method->invokeArgs(null, ['Ajoutez-yla carotte', &$count]));
        $this->assertSame('par personne 2 min.', $method->invokeArgs(null, ['par personne2 min.', &$count]));
        // un mot français ordinaire n'est jamais découpé
        $this->assertSame('ébullition casserole mijoter', $method->invokeArgs(null, ['ébullition casserole mijoter', &$count]));
        $this->assertSame(0, $count);

        $this->assertSame('Ajoutez ¼ sachet de sauce.', $method->invokeArgs(null, ['Ajoutez 4 sachet de sauce.', &$count]));
        $this->assertSame('avec ½ cc de curry', $method->invokeArgs(null, ['avec cc de curry', &$count]));
        $this->assertSame(2, $count);
    }
}
