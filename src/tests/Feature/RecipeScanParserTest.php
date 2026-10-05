<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Support\RecipeScan\IngredientLineParser;
use App\Support\RecipeScan\IngredientMatcher;
use App\Support\RecipeScan\RecipeTextParser;
use App\Support\RecipeScan\ScanImporter;
use App\Support\RecipeScan\TextCleaner;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.12.0 : lecteur de fiches de recettes (texte Paperless) - exemple réel Julie Andrieu.
 */
class RecipeScanParserTest extends TestCase
{
    use RefreshDatabase;

    public static function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/paperless/'.$name.'.txt');
    }

    private function gratin(): array
    {
        return RecipeTextParser::parse(self::fixture('julie-andrieu-gratin-courge'), 'Gratin de courge butternut et mini macaronis');
    }

    public function test_nettoyage_pieds_de_page_adresses_et_ligatures(): void
    {
        $clean = TextCleaner::clean(self::fixture('julie-andrieu-gratin-courge'));

        $this->assertSame('Julie Andrieu', $clean['author']);
        $this->assertSame('julieandrieu.com', $clean['domain']);
        $this->assertStringNotContainsString('https://', $clean['text']);
        $this->assertStringNotContainsString('1 sur 2', $clean['text']);
        $this->assertStringNotContainsString('05/10/2026', $clean['text']);
        $this->assertStringNotContainsString("\u{FFFD}", $clean['text']);
        $this->assertStringContainsString('émincez finement l\'oignon', $clean['text']);
        $this->assertStringNotContainsString('© JA', $clean['text']);
    }

    public function test_titre_temps_et_couverts(): void
    {
        $recipe = $this->gratin();

        $this->assertSame('Gratin de courge butternut et mini macaronis', $recipe['title']);
        $this->assertSame([10, 40, null], [$recipe['prep_minutes'], $recipe['cook_minutes'], $recipe['rest_minutes']]);
        $this->assertEquals(4, $recipe['yield_quantity']);
        $this->assertSame('personnes', $recipe['yield_unit']);
        $this->assertSame('Julie Andrieu (julieandrieu.com)', $recipe['source']);
        $this->assertSame(['veggy'], $recipe['tags']);
        $this->assertSame('plat', $recipe['category']);
        $this->assertSame([], $recipe['issues']);
    }

    public function test_ingredients_du_gratin(): void
    {
        $rows = collect($this->gratin()['ingredients'])->map(fn ($i) => [$i['name'], $i['quantity'], $i['unit'], $i['note']])->all();

        $this->assertSame([
            ['pâtes courtes', 225.0, 'g', 'ici mezze maniche'],
            ['courge butternut', 0.5, 'piece', 'environ 700 g'],
            ['oignon', 1.0, 'piece', null],
            ['comté 24 mois râpé', 90.0, 'g', null],
            ['parmesan râpé', 50.0, 'g', null],
            ['lait fermenté', 10.0, 'cl', null],
            ['crème épaisse', 1.0, 'cas', 'bonne'],
            ['bouillon de volaille', 7.0, 'cl', 'ou de légumes'],
            ['sauge', 4.0, 'piece', 'feuilles, ou de romarin'],
            ["huile d'olive", 2.0, 'cas', null],
            ['sel', null, null, null],
            ['poivre', null, null, null],
        ], $rows);
    }

    public function test_etapes_deux_colonnes_et_conseil_separe(): void
    {
        $recipe = $this->gratin();

        $this->assertSame('middle', $recipe['layout']);
        $this->assertCount(6, $recipe['steps']);

        $bodies = array_column($recipe['steps'], 'body');
        $this->assertSame("Pelez la courge, coupez-la en morceaux et faites-les cuire à la vapeur 25 min environ. Au bout de 15 min, retirez 1/3 des morceaux de courge, réservez-les et prolongez la cuisson pour le reste.", $bodies[0]);
        $this->assertSame("Pelez et émincez finement l'oignon. Faites-le fondre dans une poêle à feu doux avec 1 cuiller à soupe d'huile d'olive, après l'avoir légèrement salé.", $bodies[1]);
        $this->assertSame("Égouttez la courge, mixez-la finement avec l'oignon bien cuit, le lait fermenté, la crème, le bouillon, la sauge et la moitié des fromages. Salez et poivrez.", $bodies[2]);
        $this->assertSame('Préchauffez le four à 200°C, chaleur ventilée.', $bodies[3]);
        $this->assertSame("Faites cuire les pâtes plus « al dente » qu'à l'accoutumée, égouttez-les et mélangez-les à la sauce. Ajoutez les cubes de courge réservés, préalablement coupés en plus petits morceaux.", $bodies[4]);
        $this->assertSame('Huilez un plat à gratin. Étalez les pâtes dans le plat, tassez bien et couvrez avec le reste des fromages. Laissez cuire 18 min.', $bodies[5]);

        foreach ($bodies as $body) {
            $this->assertStringNotContainsString('fermenté apporte', $body);
        }

        $this->assertSame([null, null, null, null, null, 18], array_column($recipe['steps'], 'timer'));
        $this->assertStringStartsWith('Le lait fermenté apporte de la légèreté et un peu gout acidulé', $recipe['tip']);
        $this->assertStringEndsWith('ou du lait végétal.', $recipe['tip']);
        $this->assertStringStartsWith('Conseil de Julie : ', $recipe['description']);
    }

    public function test_lignes_d_ingredients_courantes(): void
    {
        $parse = fn (string $line) => collect(IngredientLineParser::parseLine($line))->map(fn ($i) => [$i['name'], $i['quantity'], $i['unit'], $i['note']])->all();

        $this->assertSame([['farine', 250.0, 'g', null]], $parse('250 g de farine'));
        $this->assertSame([['oeufs', 3.0, 'piece', null]], $parse('3 oeufs'));
        $this->assertSame([["huile d'olive", 2.0, 'cas', null]], $parse("2 c. à soupe d'huile d'olive"));
        $this->assertSame([['sucre', 1.0, 'cac', null]], $parse('1 cuillère à café de sucre'));
        $this->assertSame([['ail', 2.0, 'piece', 'gousses, hachées']], $parse('2 gousses d\'ail, hachées'));
        $this->assertSame([['lait', 0.25, 'l', null]], $parse('1/4 l de lait'));
        $this->assertSame([['crème liquide', 1.5, 'dl', null]], $parse('1,5 dl de crème liquide'));
        $this->assertSame([['poivre', 1.0, 'pincee', null]], $parse('1 pincée de poivre'));
        $this->assertSame([['dessert', null, null, null]], $parse('dessert'));
        $this->assertSame([['carottes', 2.0, 'piece', '2 à 3']], $parse('2 à 3 carottes'));
        $this->assertSame([['persil', null, null, 'au goût']], $parse('persil au goût'));
    }

    public function test_groupes_et_lignes_coupees(): void
    {
        $items = IngredientLineParser::parseSection([
            'Pour la pâte :', '• 250 g de farine', '• 1 œuf', 'Pour la garniture :', '• 200 g de', 'jambon blanc', '• 1 oignon',
        ]);

        $this->assertSame(['Pour la pâte', 'Pour la pâte', 'Pour la garniture', 'Pour la garniture'], array_column($items, 'group'));
        $this->assertSame('jambon blanc', $items[2]['name']);
        $this->assertSame(200.0, $items[2]['quantity']);
    }

    public function test_etapes_numerotees_classiques(): void
    {
        $text = "Tarte rapide\nIngrédients\n- 1 pâte\n- 2 oeufs\nPréparation\n1. Préchauffez le four à 180°C.\n2. Étalez la pâte.\nPiquez-la.\n3) Faites cuire 25 minutes.\n";
        $recipe = RecipeTextParser::parse($text);

        $this->assertSame('numbered', $recipe['layout'] === 'prefixed' ? 'numbered' : $recipe['layout']);
        $this->assertSame(['Préchauffez le four à 180°C.', 'Étalez la pâte. Piquez-la.', 'Faites cuire 25 minutes.'], array_column($recipe['steps'], 'body'));
        $this->assertSame(25, $recipe['steps'][2]['timer']);
    }

    public function test_numero_seul_et_puces(): void
    {
        $alone = RecipeTextParser::parse("Soupe\nIngrédients\n• 1 poireau\nPréparation\n1\nLavez le poireau.\n\n2\nFaites-le cuire 20 min.\n");
        $this->assertSame(['Lavez le poireau.', 'Faites-le cuire 20 min.'], array_column($alone['steps'], 'body'));

        $bullets = RecipeTextParser::parse("Soupe\nIngrédients\n• 1 poireau\nPréparation\n• Lavez le poireau.\n• Faites-le cuire.\n");
        $this->assertSame(['Lavez le poireau.', 'Faites-le cuire.'], array_column($bullets['steps'], 'body'));
    }

    public function test_duree_et_fiche_incomplete(): void
    {
        $this->assertSame(90, RecipeTextParser::duration('1 h 30'));
        $this->assertSame(90, RecipeTextParser::duration('1h30'));
        $this->assertSame(45, RecipeTextParser::duration('45 minutes'));
        $this->assertSame(120, RecipeTextParser::duration('2 heures'));
        $this->assertNull(RecipeTextParser::duration('rapide'));

        $recipe = RecipeTextParser::parse("Une fiche\nrien d'utile ici");
        $this->assertContains('Liste d\'ingrédients introuvable.', $recipe['issues']);
        $this->assertContains('Étapes de préparation introuvables.', $recipe['issues']);
    }

    public function test_rapprochement_avec_le_referentiel(): void
    {
        ReferenceImporter::import();
        $matcher = new IngredientMatcher;

        $expected = [
            'pâtes courtes' => 'Pâtes (spaghetti, penne…)',
            'courge butternut' => 'Courge butternut',
            'oignon' => 'Oignon jaune',
            'comté 24 mois râpé' => 'Comté',
            'parmesan râpé' => 'Parmesan',
            'lait fermenté' => 'Lait fermenté (ribot)',
            'crème épaisse' => 'Crème fraîche épaisse',
            'bouillon de volaille' => 'Bouillon de volaille (cube)',
            'sauge' => 'Sauge fraîche',
            "huile d'olive" => "Huile d'olive",
            'sel' => 'Sel fin',
            'poivre' => 'Poivre noir moulu',
            'oeufs' => 'Œuf',
        ];

        foreach ($expected as $label => $name) {
            $this->assertSame($name, $matcher->match($label)['ingredient']?->name, $label);
        }

        $unknown = $matcher->match('pâte à tartiner spéculoos');
        $this->assertNull($unknown['ingredient']);
    }

    public function test_analyse_complete_du_gratin(): void
    {
        ReferenceImporter::import();
        $analysis = (new ScanImporter)->analyse(self::fixture('julie-andrieu-gratin-courge'), 'Gratin de courge butternut et mini macaronis');

        $this->assertTrue($analysis['complete'], json_encode(collect($analysis['rows'])->whereNotNull('problem')->values()));
        $this->assertSame([], $analysis['issues']);
        $this->assertSame([], $analysis['unresolved']);

        $bouillon = collect($analysis['rows'])->firstWhere('label', 'bouillon de volaille');
        $this->assertSame('Bouillon de volaille (cube)', $bouillon['name']);
        $this->assertSame([0.25, 'piece'], [$bouillon['quantity'], $bouillon['unit']]);
        $this->assertStringContainsString('7 cl de bouillon préparé', $bouillon['note']);
        $this->assertSame(Ingredient::where('name', 'Sauge fraîche')->value('id'), collect($analysis['rows'])->firstWhere('label', 'sauge')['ingredient_id']);
    }
}
