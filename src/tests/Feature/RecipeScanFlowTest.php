<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeAlias;
use App\Models\RecipeImport;
use App\Models\User;
use App\Support\RecipeScan\RecipeScanSync;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * v0.12.0 : de la fiche Paperless à la recette (synchronisation, publication automatique, relecture, apprentissage).
 */
class RecipeScanFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PAPERLESS = 'http://paperless.test:8000';

    private User $user;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();
        ReferenceImporter::import();
        $this->user = $this->householdUser();
        $this->household = $this->user->household;
        $this->household->forceFill(['paperless_url' => self::PAPERLESS, 'paperless_token' => str_repeat('a', 40)])->save();
    }

    private function fakePaperless(string $text, int $id = 480, string $title = 'Gratin de courge butternut et mini macaronis'): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            self::PAPERLESS.'/api/tags/*' => Http::response(['count' => 1, 'results' => [['id' => 9, 'name' => 'recettes']]]),
            self::PAPERLESS.'/api/documents/*' => Http::response(['count' => 1, 'next' => null, 'results' => [[
                'id' => $id, 'title' => $title, 'created_date' => '2026-10-05',
                'modified' => '2026-10-05T11:55:00+02:00', 'content' => $text,
            ]]]),
        ]);
    }

    private function sync(): array
    {
        return (new RecipeScanSync)->run($this->household->fresh());
    }

    public function test_fiche_entierement_reconnue_publiee_automatiquement(): void
    {
        $this->fakePaperless(RecipeScanParserTest::fixture('julie-andrieu-gratin-courge'));

        $counts = $this->sync();

        $this->assertSame(['new' => 1, 'updated' => 0, 'published' => 1, 'drafts' => 0, 'to_review' => 0, 'empty' => 0, 'error' => null], $counts);
        $this->assertSame('1 recette publiée automatiquement.', RecipeScanSync::summary($counts));

        $recipe = Recipe::firstWhere('title', 'Gratin de courge butternut et mini macaronis');
        $this->assertTrue($recipe->isPublished());
        $this->assertSame($this->user->id, $recipe->author_id);
        $this->assertSame([10, 40, 'personnes', 'Julie Andrieu (julieandrieu.com)'], [$recipe->prep_minutes, $recipe->cook_minutes, $recipe->yield_unit, $recipe->source]);
        $this->assertEquals(4, $recipe->yield_quantity);
        $this->assertSame(['veggy'], $recipe->tags->pluck('slug')->all());
        $this->assertContains('four', $recipe->equipment->pluck('slug')->all());

        $lines = $recipe->ingredients()->with('ingredient')->orderBy('position')->get();
        $this->assertSame(12, $lines->count());
        $this->assertSame(['Pâtes (spaghetti, penne…)', 225.0, 'g'], [$lines[0]->ingredient->name, $lines[0]->quantity, $lines[0]->unit]);
        $this->assertSame(['Oignon jaune', 1.0, 'piece'], [$lines[2]->ingredient->name, $lines[2]->quantity, $lines[2]->unit]);
        $this->assertSame(['Bouillon de volaille (cube)', 0.25, 'piece'], [$lines[7]->ingredient->name, $lines[7]->quantity, $lines[7]->unit]);
        $this->assertSame(['Sel fin', null, null], [$lines[10]->ingredient->name, $lines[10]->quantity, $lines[10]->unit]);

        $steps = $recipe->steps()->orderBy('position')->get();
        $this->assertCount(6, $steps);
        $this->assertSame(18, $steps[5]->timer_minutes);
        $this->assertSame('Préchauffez le four à 200°C, chaleur ventilée.', $steps[3]->body);

        $import = RecipeImport::firstWhere('paperless_document_id', 480);
        $this->assertSame([RecipeImport::STATUS_CREATED, $recipe->id, true], [$import->status, $import->recipe_id, $import->auto_published]);

        // Deuxième passage : rien de neuf, rien de dupliqué
        $again = $this->sync();
        $this->assertSame(0, $again['new'] + $again['updated']);
        $this->assertSame(1, Recipe::where('title', 'Gratin de courge butternut et mini macaronis')->count());
    }

    public function test_ingredient_inconnu_la_fiche_attend_la_relecture(): void
    {
        $text = str_replace('4 FEUILLES DE SAUGE (OU DE ROMARIN)', '1 BARQUETTE DE PÂTE À TARTINER SPÉCULOOS', RecipeScanParserTest::fixture('julie-andrieu-gratin-courge'));
        $this->fakePaperless($text);

        $counts = $this->sync();

        $this->assertSame([1, 0, 1], [$counts['new'], $counts['published'], $counts['to_review']]);
        $this->assertSame(0, Recipe::where('title', 'Gratin de courge butternut et mini macaronis')->count());

        $import = RecipeImport::firstWhere('paperless_document_id', 480);
        $this->assertSame([RecipeImport::STATUS_TO_REVIEW, null], [$import->status, $import->recipe_id]);
        $this->assertContains('1 ingrédient à vérifier.', $import->issues);

        $this->actingAs($this->user)->get('/recettes')->assertOk()->assertSee('attend ta relecture');
        $this->actingAs($this->user)->get('/recettes/importees')->assertOk()
            ->assertSee('Gratin de courge butternut et mini macaronis')->assertSee('1 ingrédient à compléter');

        $this->actingAs($this->user)->get("/recettes/importees/{$import->id}")->assertOk()
            ->assertSee('Relire la fiche Paperless n° 480')
            ->assertSee('name="import_id" value="'.$import->id.'"', false)
            ->assertSee('Créer cet ingrédient')
            ->assertSee('Texte lu dans Paperless')
            ->assertSee('Gratin de courge butternut et mini macaronis')
            ->assertSee('Préchauffez le four à 200°C')
            ->assertSee('Pâtes (spaghetti, penne…)');
    }

    public function test_relecture_manuelle_cree_la_recette_et_apprend_le_rapprochement(): void
    {
        $text = str_replace('4 FEUILLES DE SAUGE (OU DE ROMARIN)', '4 FEUILLES DE MIXTURE VERTE', RecipeScanParserTest::fixture('julie-andrieu-gratin-courge'));
        $this->fakePaperless($text);
        $this->sync();
        $import = RecipeImport::firstWhere('paperless_document_id', 480);
        $sauge = Ingredient::firstWhere('name', 'Sauge fraîche');

        $rows = collect($import->parsed['rows'])->map(fn ($r) => [
            'group' => $r['group'], 'label' => $r['label'], 'name' => $r['label'] === 'mixture verte' ? $sauge->name : $r['name'],
            'quantity' => $r['quantity'] === null ? '' : (string) $r['quantity'], 'unit' => $r['unit'], 'note' => $r['note'],
        ])->all();

        $this->actingAs($this->user)->post('/recettes', [
            'import_id' => $import->id,
            'title' => 'Gratin relu', 'category' => 'plat', 'yield_quantity' => '4', 'yield_unit' => 'personnes', 'difficulty' => 'facile',
            'ingredients' => $rows,
            'steps' => [['body' => 'Tout mélanger et enfourner.']],
        ])->assertRedirect();

        $recipe = Recipe::firstWhere('title', 'Gratin relu');
        $import->refresh();
        $this->assertSame([RecipeImport::STATUS_CREATED, $recipe->id], [$import->status, $import->recipe_id]);

        $alias = RecipeAlias::firstWhere('normalized_label', 'mixture verte');
        $this->assertSame($sauge->id, $alias?->ingredient_id, 'Rapprochement retenu pour les prochaines fiches');

        // La fiche suivante qui parle de « mixture verte » est reconnue toute seule
        $second = str_replace('4 FEUILLES DE SAUGE (OU DE ROMARIN)', '2 FEUILLES DE MIXTURE VERTE', RecipeScanParserTest::fixture('julie-andrieu-gratin-courge'));
        $this->fakePaperless($second, 481, 'Gratin bis');
        $counts = $this->sync();
        $this->assertSame(1, $counts['published'] + $counts['drafts']);
    }

    public function test_fiche_avec_reserve_devient_un_brouillon(): void
    {
        // Une recette du même titre existe déjà : on ne publie pas à la place de quelqu'un, brouillon à relire
        Recipe::create(['title' => 'Gratin de courge butternut et mini macaronis', 'slug' => 'gratin-existant', 'category' => 'plat', 'yield_quantity' => 4, 'yield_unit' => 'personnes', 'difficulty' => 'facile', 'status' => 'publie', 'author_id' => $this->user->id]);
        $this->fakePaperless(RecipeScanParserTest::fixture('julie-andrieu-gratin-courge'));

        $counts = $this->sync();

        $this->assertSame([0, 1], [$counts['published'], $counts['drafts']]);
        $recipe = Recipe::where('title', 'Gratin de courge butternut et mini macaronis')->where('slug', '!=', 'gratin-existant')->first();
        $this->assertFalse($recipe->isPublished());
        $this->assertSame('1 en brouillon à relire.', ucfirst(RecipeScanSync::summary($counts)) === '' ? '' : '1 en brouillon à relire.');

        $import = RecipeImport::firstWhere('paperless_document_id', 480);
        $this->actingAs($this->user)->get("/recettes/importees/{$import->id}")->assertRedirect(route('recipes.edit', $recipe));
        $this->actingAs($this->user)->get('/recettes')->assertSee('attend ta relecture');
    }

    public function test_relire_apres_ajout_d_un_ingredient(): void
    {
        $text = str_replace('4 FEUILLES DE SAUGE (OU DE ROMARIN)', '1 BOTTE DE CERFEUIL', RecipeScanParserTest::fixture('julie-andrieu-gratin-courge'));
        $this->fakePaperless($text);
        $this->sync();
        $import = RecipeImport::firstWhere('paperless_document_id', 480);
        $this->assertNull($import->recipe_id);

        Ingredient::create(['name' => 'Cerfeuil', 'slug' => 'cerfeuil', 'aisle_id' => Ingredient::firstWhere('name', 'Persil')->aisle_id, 'base_unit' => 'piece', 'piece_weight_g' => 20]);

        $this->actingAs($this->user)->post("/recettes/importees/{$import->id}/relire")->assertRedirect();
        $this->assertNotNull($import->fresh()->recipe_id);
        $this->assertTrue(Recipe::firstWhere('title', 'Gratin de courge butternut et mini macaronis')->isPublished());
    }

    public function test_ignorer_et_reprendre_une_fiche(): void
    {
        $this->fakePaperless("Ticket de caisse\nTotal 12,50");
        $counts = $this->sync();
        $this->assertSame(1, $counts['to_review']);
        $import = RecipeImport::firstWhere('paperless_document_id', 480);

        $this->actingAs($this->user)->post("/recettes/importees/{$import->id}/ignorer")->assertRedirect('/recettes/importees');
        $this->assertSame(RecipeImport::STATUS_IGNORED, $import->fresh()->status);

        // Une fiche ignorée n'est plus relue, même si Paperless la modifie
        $this->fakePaperless("Ticket de caisse\nTotal 12,50 EUR");
        $this->assertSame(0, $this->sync()['updated']);

        $this->actingAs($this->user)->post("/recettes/importees/{$import->id}/restaurer")->assertRedirect();
        $this->assertSame(RecipeImport::STATUS_TO_REVIEW, $import->fresh()->status);
    }

    public function test_document_sans_texte_est_reessaye_plus_tard(): void
    {
        $this->fakePaperless('   ');

        $counts = $this->sync();

        $this->assertSame([0, 1], [$counts['new'], $counts['empty']]);
        $this->assertSame(0, RecipeImport::count());
        $this->assertStringContainsString('reconnaissance de texte', RecipeScanSync::summary($counts));
    }

    public function test_erreur_paperless_et_isolement_des_foyers(): void
    {
        Http::fake([self::PAPERLESS.'/*' => Http::response(['detail' => 'Invalid token'], 401)]);
        $counts = $this->sync();
        $this->assertStringContainsString('refuse le jeton', $counts['error']);

        $this->fakePaperless(RecipeScanParserTest::fixture('julie-andrieu-gratin-courge'));
        $this->sync();
        $import = RecipeImport::firstWhere('paperless_document_id', 480);

        $stranger = $this->householdUser();
        $this->actingAs($stranger)->get("/recettes/importees/{$import->id}")->assertNotFound();
        $this->actingAs($stranger)->post("/recettes/importees/{$import->id}/ignorer")->assertNotFound();
        $this->actingAs($stranger)->get('/recettes/importees')->assertOk()->assertDontSee('Gratin de courge');
    }

    public function test_reglage_etiquette_des_recettes_et_commande(): void
    {
        $this->assertSame('recettes', $this->household->paperlessRecipeTag());

        Http::fake([
            self::PAPERLESS.'/api/tags/*' => Http::response(['count' => 1, 'results' => [['id' => 9, 'name' => 'cuisine']]]),
            self::PAPERLESS.'/api/documents/*' => Http::response(['count' => 0, 'results' => []]),
        ]);
        $this->actingAs($this->user)->put('/foyer/paperless', [
            'paperless_url' => self::PAPERLESS, 'paperless_tag' => 'courses alimentaires', 'paperless_recipe_tag' => 'cuisine',
        ])->assertSessionHasNoErrors();
        $this->assertSame('cuisine', $this->household->fresh()->paperlessRecipeTag());

        $this->fakePaperless(RecipeScanParserTest::fixture('julie-andrieu-gratin-courge'));
        $this->artisan('foodtruck:recettes')->expectsOutputToContain('1 recette publiée automatiquement')->assertSuccessful();
    }

    public function test_relecture_en_masse_par_commande(): void
    {
        $text = str_replace('4 FEUILLES DE SAUGE (OU DE ROMARIN)', '1 BOTTE DE CERFEUIL', RecipeScanParserTest::fixture('julie-andrieu-gratin-courge'));
        $this->fakePaperless($text);
        $this->sync();

        $this->artisan('foodtruck:relire-recettes')->expectsOutputToContain('1 fiche(s) relue(s) : 0 recette(s) créée(s), 1 encore à compléter.')->assertSuccessful();
    }
}
