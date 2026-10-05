<?php

namespace Tests\Unit;

use App\Support\RecipeScan\IngredientLineParser;
use App\Support\RecipeScan\RecipeTextParser;
use App\Support\RecipeScan\StepExtractor;
use App\Support\RecipeScan\TextCleaner;
use PHPUnit\Framework\TestCase;

/**
 * v0.12.2 : fiche imprimée Leclerc « Pâtes carbonara » (texte Paperless réel : puces lues « e » ou « ?? »,
 * « 227100 g » pour « ??100 g », bandeau « 4 pers 20 mn » et titre déplacés après la liste, étape 6 isolée).
 * Test sans base de données : le lecteur est une pure fonction du texte.
 */
class PrintedSheetParserTest extends TestCase
{
    private function carbonara(): array
    {
        $text = file_get_contents(__DIR__.'/../Fixtures/paperless/leclerc-pates-carbonara.txt');

        return RecipeTextParser::parse($text, 'Pâtes carbonara');
    }

    public function test_titre_personnes_temps_et_source(): void
    {
        $r = $this->carbonara();

        $this->assertSame('Pâtes carbonara', $r['title']);
        $this->assertSame([4.0, 20], [$r['yield_quantity'], $r['prep_minutes']]);
        $this->assertSame('mesrecettes.leclerc', $r['source']);
        $this->assertSame('prefixed', $r['layout']);
        $this->assertSame([], $r['issues']);
    }

    public function test_ingredients_sans_les_puces_ni_le_titre_ni_le_bandeau(): void
    {
        $lines = collect($this->carbonara()['ingredients']);

        $this->assertSame(
            ['spaghetti', "jaunes d'oeufs", 'parmesan rapé', 'lardons', "Huile d'olive", 'Sel', 'Poivre'],
            $lines->pluck('name')->all()
        );
        $this->assertSame([400.0, 'g'], [$lines[0]['quantity'], $lines[0]['unit']]);
        $this->assertSame([4.0, 'piece'], [$lines[1]['quantity'], $lines[1]['unit']]);
        $this->assertSame([150.0, 'g', 'ou de pancetta'], [$lines[3]['quantity'], $lines[3]['unit'], $lines[3]['note']]);
        $this->assertNull($lines[5]['quantity']);
    }

    public function test_quantite_demesuree_corrigee_et_signalee(): void
    {
        $parmesan = collect($this->carbonara()['ingredients'])->firstWhere('name', 'parmesan rapé');

        $this->assertSame([100.0, 'g'], [$parmesan['quantity'], $parmesan['unit']]);
        $this->assertStringContainsString('puce mal lue', $parmesan['check']);
        $this->assertArrayNotHasKey('check', collect($this->carbonara()['ingredients'])->firstWhere('name', 'spaghetti'));
    }

    public function test_quantites_normales_jamais_signalees_et_inhabituelles_gardees(): void
    {
        $this->assertArrayNotHasKey('check', IngredientLineParser::parseLine('1500 g de farine')[0]);
        $this->assertArrayNotHasKey('check', IngredientLineParser::parseLine('2 kg de pommes de terre')[0]);

        $kg = IngredientLineParser::parseLine('500 kg de riz')[0];
        $this->assertSame(500.0, $kg['quantity']);
        $this->assertStringContainsString('inhabituelle', $kg['check']);
    }

    public function test_six_etapes_dont_la_derniere_etiquette_isolee(): void
    {
        $steps = array_column($this->carbonara()['steps'], 'body');

        $this->assertCount(6, $steps);
        $this->assertSame("Battre les jaunes d'œufs en y ajoutant 1 pincée de sel, 2 pincées de poivre et le parmesan râpé.", $steps[0]);
        $this->assertSame('Mettre à chauffer une grande casserole d\'eau avec 1 pincée de sel.', $steps[1]);
        $this->assertSame("Incorporer ensuite la préparation des jaunes d'oeufs et saupoudrer de parmesan.", $steps[5]);
        $this->assertSame([], array_filter($steps, fn ($s) => str_contains($s, '??')));
    }

    public function test_etape_finale_apres_une_ligne_vide_et_texte_qui_suit(): void
    {
        $r = StepExtractor::extract(['Etape 1', 'Couper.', '', 'Etape 2', '', 'Cuire 10 min.', '', 'Bon appétit à tous']);

        $this->assertSame(['Couper.', 'Cuire 10 min.'], $r['steps']);
        $this->assertNotEmpty($r['issues']);
    }

    public function test_puces_mal_lues_dans_une_liste(): void
    {
        $items = IngredientLineParser::parseSection(['e 400 g de spaghetti', '', '4 jaunes d\'oeufs', '??Sel', 'e ??Poivre', 'e']);

        $this->assertSame(['spaghetti', "jaunes d'oeufs", 'Sel', 'Poivre'], array_column($items, 'name'));
    }

    public function test_pied_de_page_avec_adresse_devient_la_source(): void
    {
        $clean = TextCleaner::clean("Fin de la recette.\n\nRetrouvez toutes nos recettes sur [www.mesrecettes.leclerc](https://www.mesrecettes.leclerc)");

        $this->assertSame('Fin de la recette.', $clean['text']);
        $this->assertSame('mesrecettes.leclerc', $clean['domain']);
    }

    public function test_bandeau_personnes_et_temps_mais_pas_une_phrase(): void
    {
        $band = new \ReflectionMethod(RecipeTextParser::class, 'band');

        $this->assertSame(['yield' => 4.0, 'minutes' => 20], $band->invoke(null, '@ 4 pers 20 mn LS'));
        $this->assertSame(['yield' => 6.0, 'minutes' => null], $band->invoke(null, '6 personnes'));
        $this->assertNull($band->invoke(null, 'Pour 4 personnes, faire cuire les pâtes dans une grande casserole'));
    }
}
