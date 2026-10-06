<?php

namespace Tests\Unit;

use App\Support\RecipeScan\RecipeTextParser;
use App\Support\RecipeScan\VisionComposer;
use PHPUnit\Framework\TestCase;

/**
 * v0.18.0 : recette rendue par le modèle de vision (JSON) remise en texte pour le lecteur habituel.
 * Fixture : réponse réelle de qwen3-vl:8b-instruct-q8_0 (RX 6800, 1 min) pour la Salade façon piémontaise au jambon
 * (HelloFresh, Paperless n° 490) : unités « sachet(s) », puces, encadrés « L'astuce du chef » laissés dans les étapes.
 */
class VisionComposerTest extends TestCase
{
    private function recipe(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/vision/hellofresh-piemontaise.json'), true);
    }

    public function test_piemontaise_lue_par_le_modele(): void
    {
        $text = VisionComposer::compose($this->recipe());
        $r = RecipeTextParser::parse($text, null);

        $this->assertStringStartsWith('Salade façon piémontaise au jambon', $r['title']);
        $this->assertSame(2.0, (float) $r['yield_quantity']);

        $rows = array_map(fn ($i) => [$i['group'], $i['quantity'], $i['unit'], $i['name'], $i['note']], $r['ingredients']);
        $this->assertContains([null, 0.5, 'piece', 'Ciboulette', 'sachet'], $rows, '½ sachet');
        $this->assertContains([null, 4.0, 'piece', 'Jambon blanc', 'tranche'], $rows);
        $this->assertContains([null, 1.0, 'piece', 'Mayonnaise', 'sachet'], $rows, 'La ligne lue « EPENNENES CEÉIEREN » par Tesseract');
        $this->assertContains(['À ajouter vous-même', 1.0, 'cac', 'Moutarde', null], $rows, 'La ligne perdue par Tesseract');
        $this->assertContains(['À ajouter vous-même', 1.0, 'cac', 'Vinaigre de vin rouge', 'ou de cidre'], $rows);
        $this->assertContains(['À ajouter vous-même', 2.0, 'piece', 'Œuf', null], $rows);
        $this->assertSame(['Poivre', 'sel'], array_slice(array_column($r['ingredients'], 'name'), -2));
        $this->assertCount(13, $r['ingredients']);

        $this->assertTrue(collect($r['ingredients'])->every(fn ($i) => ! isset($i['check'])), 'Rien de douteux sur cette fiche');

        // Étapes dans l'ordre des numéros (la grille 3 × 2 de la carte), titres conservés
        $this->assertCount(6, $r['steps']);
        $this->assertStringStartsWith('Coup d\'envoi gourmand : Épluchez', $r['steps'][0]['body']);
        $this->assertStringStartsWith('Les œufs prennent leurs bain : ', $r['steps'][2]['body']);
        $this->assertStringStartsWith('Sauce qui peut ! : ', $r['steps'][3]['body']);
        $this->assertStringContainsString('½ cc de moutarde', $r['steps'][3]['body']);
        // Puces retirées, consigne générale écartée, encadrés « L'astuce du chef » déplacés dans le conseil
        $all = implode(' ', array_column($r['steps'], 'body'));
        $this->assertStringNotContainsString('•', $all);
        $this->assertStringNotContainsString('Veillez à bien respecter', $all);
        $this->assertStringNotContainsString('ASTUCE DU CHEF', $all);
        $this->assertStringContainsString('premières feuilles de votre sucrine', (string) $r['tip']);
        $this->assertStringContainsString('cuisson mollet', (string) $r['tip']);
    }

    public function test_ligne_douteuse_titre_temps_et_site(): void
    {
        $recipe = $this->recipe();
        $recipe['ingredients'][6]['doute'] = true;
        $recipe += ['temps_minutes' => 40, 'site' => 'www.hellofresh.fr'];
        $r = RecipeTextParser::parse(VisionComposer::compose($recipe), null);

        $this->assertSame([40, 'hellofresh.fr'], [$r['prep_minutes'], $r['source']]);
        $this->assertSame(VisionComposer::DOUBT, collect($r['ingredients'])->firstWhere('name', 'Mayonnaise')['check'], 'Ligne difficile à lire : en rouge');
        $this->assertArrayNotHasKey('check', collect($r['ingredients'])->firstWhere('name', 'Moutarde'));
    }

    public function test_quantites_et_lignes_incompletes(): void
    {
        $text = VisionComposer::compose(['titre' => 'Test', 'personnes' => null, 'ingredients' => [
            ['nom' => 'Huile de tournesol*', 'quantite' => 1.5, 'unite' => 'cs', 'precision' => null, 'groupe' => null, 'doute' => false],
            ['nom' => 'Citron', 'quantite' => 0.667, 'unite' => 'pièce', 'precision' => null, 'groupe' => null, 'doute' => false],
            ['nom' => 'Riz', 'quantite' => 0.2, 'unite' => 'kg', 'precision' => null, 'groupe' => null, 'doute' => false],
            ['nom' => '   ', 'quantite' => 1, 'unite' => 'g', 'precision' => null, 'groupe' => null, 'doute' => false],
        ], 'etapes' => [['titre' => null, 'texte' => "Cuire\n le riz."], ['titre' => 'Vide', 'texte' => '']], 'conseil' => null, 'site' => 'pas un site']);

        $this->assertStringContainsString('- 1½ cs Huile de tournesol', $text);
        $this->assertStringContainsString('- ⅔ Citron', $text);
        $this->assertStringContainsString('- 0,2 kg Riz', $text);
        $this->assertStringContainsString("Etape 1\nCuire le riz.", $text);
        $this->assertStringNotContainsString('Etape 2', $text, 'Étape vide écartée');
        $this->assertStringNotContainsString('Retrouvez', $text);
        $this->assertStringNotContainsString('personnes', $text);
    }
}
