<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeAlias;
use App\Models\RecipeImport;
use App\Models\User;
use App\Support\RecipeScan\RecipeScanSync;
use App\Support\RecipeScan\ScanImporter;
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

    public function test_fiche_entierement_reconnue_attend_la_validation(): void
    {
        $this->fakePaperless(RecipeScanParserTest::fixture('julie-andrieu-gratin-courge'));

        $counts = $this->sync();

        // v0.16.1 : plus de publication automatique, chaque fiche importée est validée par Louis
        $this->assertSame(['new' => 1, 'updated' => 0, 'published' => 0, 'drafts' => 0, 'ready' => 1, 'to_review' => 0, 'empty' => 0, 'reading' => 0, 'error' => null], $counts);
        $this->assertSame('1 fiche prête à valider.', RecipeScanSync::summary($counts));
        $this->assertSame(0, Recipe::where('title', 'Gratin de courge butternut et mini macaronis')->count());

        $import = RecipeImport::firstWhere('paperless_document_id', 480);
        $this->assertSame([RecipeImport::STATUS_TO_REVIEW, null, true], [$import->status, $import->recipe_id, ScanImporter::isReady($import)]);
        $this->actingAs($this->user)->get('/recettes/importees')->assertOk()->assertSee('à valider')->assertSee('il ne reste qu\'à valider', false);

        // « Valider » : le formulaire pré-rempli est envoyé tel quel
        $form = ScanImporter::formRows($import->parsed);
        $fields = $import->parsed['recipe'];
        $this->post('/recettes', [
            'import_id' => $import->id, 'title' => $fields['title'], 'category' => $fields['category'], 'yield_quantity' => (string) $fields['yield_quantity'],
            'yield_unit' => $fields['yield_unit'], 'prep_minutes' => $fields['prep_minutes'], 'cook_minutes' => $fields['cook_minutes'],
            'difficulty' => $fields['difficulty'], 'source' => $fields['source'], 'tags' => \App\Support\RecipeWriter::tagIds($fields['tags']),
            'ingredients' => collect($form['ingredients'])->map(fn ($r) => array_intersect_key($r, array_flip(['group', 'name', 'label', 'quantity', 'unit', 'note'])))->all(),
            'steps' => $form['steps'],
        ])->assertSessionHasNoErrors()->assertRedirect();

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

        $import->refresh();
        $this->assertSame([RecipeImport::STATUS_CREATED, $recipe->id, false], [$import->status, $import->recipe_id, $import->auto_published]);

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
            ->assertSee('Choisir ou créer l\'ingrédient')
            ->assertSee('data-kind="unknown"', false)
            ->assertSee('id="fix-dialog"', false)
            ->assertSee('Texte lu dans Paperless')
            ->assertSee('Gratin de courge butternut et mini macaronis')
            ->assertSee('Préchauffez le four à 200°C')
            ->assertSee('Pâtes (spaghetti, penne…)');
    }

    public function test_fiche_kit_repas_attend_la_relecture_avec_les_lignes_douteuses_en_rouge(): void
    {
        $this->fakePaperless(RecipeScanParserTest::fixture('hellofresh-curry-thai-crevettes'), 481, 'Curry thaïléger aux crevettes & coco');

        $counts = $this->sync();

        // Jamais publiée toute seule : fractions perdues par la reconnaissance de texte et étapes à colonnes mélangées
        $this->assertSame([1, 0, 0, 1], [$counts['new'], $counts['published'], $counts['drafts'], $counts['to_review']]);
        $this->assertSame(0, Recipe::where('title', 'Curry thaïléger aux crevettes & coco')->count());

        $import = RecipeImport::firstWhere('paperless_document_id', 481);
        $this->assertSame([RecipeImport::STATUS_TO_REVIEW, null], [$import->status, $import->recipe_id]);
        $this->assertNotEmpty(array_filter($import->issues, fn ($i) => str_contains($i, 'Fiche à colonnes (kit repas)')));

        $flagged = collect($import->parsed['rows'])->whereNotNull('problem');
        $this->assertGreaterThanOrEqual(6, $flagged->count());
        $this->assertCount(6, $import->parsed['recipe']['steps']);
        $this->assertSame('HelloFresh (semaine 33, 2025)', $import->parsed['recipe']['source']);

        $this->actingAs($this->user)->get("/recettes/importees/{$import->id}")->assertOk()
            ->assertSee('Relire la fiche Paperless n° 481')
            ->assertSee('Chop, chop, chop')
            ->assertSee('Tout baigne')
            ->assertSee('Texte lu dans Paperless');
    }

    public function test_fiche_imprimee_avec_quantite_demesuree_attend_la_relecture(): void
    {
        $this->fakePaperless(RecipeScanParserTest::fixture('leclerc-pates-carbonara'), 484, 'Pâtes carbonara');

        $counts = $this->sync();

        // « 227100 g de parmesan » : puce lue comme des chiffres, corrigée en 100 g mais à vérifier par une personne
        $this->assertSame([1, 0, 0, 1], [$counts['new'], $counts['published'], $counts['drafts'], $counts['to_review']]);
        $this->assertSame(0, Recipe::where('source', 'mesrecettes.leclerc')->count());

        $import = RecipeImport::firstWhere('paperless_document_id', 484);
        $this->assertSame(RecipeImport::STATUS_TO_REVIEW, $import->status);
        $this->assertCount(6, $import->parsed['recipe']['steps']);
        $this->assertSame('mesrecettes.leclerc', $import->parsed['recipe']['source']);
        $this->assertEquals(4, $import->parsed['recipe']['yield_quantity']);

        $parmesan = collect($import->parsed['rows'])->firstWhere('label', 'parmesan rapé');
        $this->assertEquals(100, $parmesan['quantity']);
        $this->assertNotNull($parmesan['problem']);

        $this->actingAs($this->user)->get("/recettes/importees/{$import->id}")->assertOk()
            ->assertSee('Relire la fiche Paperless n° 484')
            ->assertSee('Incorporer ensuite la préparation');
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
        $this->assertSame(1, $counts['ready'], 'Reconnue toute seule, prête à valider');
    }

    public function test_fiche_avec_reserve_signalee_a_la_validation(): void
    {
        // Une recette du même titre existe déjà : rien n'est créé, la réserve est affichée à la relecture
        Recipe::create(['title' => 'Gratin de courge butternut et mini macaronis', 'slug' => 'gratin-existant', 'category' => 'plat', 'yield_quantity' => 4, 'yield_unit' => 'personnes', 'difficulty' => 'facile', 'status' => 'publie', 'author_id' => $this->user->id]);
        $this->fakePaperless(RecipeScanParserTest::fixture('julie-andrieu-gratin-courge'));

        $this->sync();

        $this->assertSame(1, Recipe::where('title', 'Gratin de courge butternut et mini macaronis')->count(), 'Aucun doublon créé');
        $import = RecipeImport::firstWhere('paperless_document_id', 480);
        $this->assertContains('Une recette porte déjà ce titre.', $import->issues);
        $this->actingAs($this->user)->get("/recettes/importees/{$import->id}")->assertOk()->assertSee('Une recette porte déjà ce titre.');
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
        $this->assertNull($import->fresh()->recipe_id, 'Jamais créée sans validation');
        $this->assertTrue(ScanImporter::isReady($import->fresh()), 'Tout est reconnu : prête à valider');
    }

    public function test_supprimer_une_fiche_a_relire_l_efface_et_la_synchronisation_la_retraite(): void
    {
        $this->fakePaperless("Ticket de caisse\nTotal 12,50");
        $this->sync();
        $import = RecipeImport::firstWhere('paperless_document_id', 480);

        $this->actingAs($this->user)->get('/recettes/importees')->assertOk()
            ->assertSee('Supprimer')->assertDontSee('Tout supprimer', false);

        $this->actingAs($this->user)->post("/recettes/importees/{$import->id}/ignorer")
            ->assertRedirect('/recettes/importees')
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Fiche supprimée'));
        $this->assertSame(0, RecipeImport::count(), 'La fiche est effacée, pas mise de côté');

        // « Chercher dans Paperless » : le document porte toujours l'étiquette, il est relu depuis zéro
        $this->fakePaperless("Ticket de caisse\nTotal 12,50");
        $counts = $this->sync();
        $this->assertSame([1, 0], [$counts['new'], $counts['updated']]);

        $again = RecipeImport::firstWhere('paperless_document_id', 480);
        $this->assertNotNull($again);
        $this->assertSame(RecipeImport::STATUS_TO_REVIEW, $again->status);
        $this->assertSame(1, RecipeImport::count(), 'Aucun doublon');
    }

    public function test_supprimer_une_fiche_avec_brouillon_supprime_le_brouillon(): void
    {
        $draft = Recipe::create(['title' => 'Brouillon scanné', 'slug' => 'brouillon-scanne', 'category' => 'plat', 'yield_quantity' => 4, 'yield_unit' => 'personnes', 'difficulty' => 'facile', 'status' => Recipe::STATUS_DRAFT, 'author_id' => $this->user->id]);
        $import = RecipeImport::create(['household_id' => $this->household->id, 'paperless_document_id' => 490, 'title' => 'Brouillon scanné', 'raw_text' => 'texte', 'status' => RecipeImport::STATUS_CREATED, 'recipe_id' => $draft->id]);

        $this->actingAs($this->user)->get('/recettes/importees')->assertOk()->assertSee('Brouillon scanné')->assertSee('son brouillon de recette sera supprimé', false);
        $this->actingAs($this->user)->post("/recettes/importees/{$import->id}/ignorer")->assertRedirect('/recettes/importees');

        $this->assertNull(Recipe::find($draft->id));
        $this->assertNull(RecipeImport::find($import->id));
    }

    public function test_un_brouillon_au_planning_ou_une_recette_publiee_ne_sont_pas_supprimes(): void
    {
        $planned = Recipe::create(['title' => 'Brouillon planifié', 'slug' => 'brouillon-planifie', 'category' => 'plat', 'yield_quantity' => 4, 'yield_unit' => 'personnes', 'difficulty' => 'facile', 'status' => Recipe::STATUS_DRAFT, 'author_id' => $this->user->id]);
        $this->household->mealPlanEntries()->create(['date' => '2026-10-06', 'slot' => 'diner', 'kind' => 'recette', 'recipe_id' => $planned->id, 'meals' => 1, 'created_by' => $this->user->id]);
        $a = RecipeImport::create(['household_id' => $this->household->id, 'paperless_document_id' => 491, 'title' => 'A', 'raw_text' => 'texte', 'status' => RecipeImport::STATUS_CREATED, 'recipe_id' => $planned->id]);

        $published = Recipe::create(['title' => 'Déjà publiée', 'slug' => 'deja-publiee', 'category' => 'plat', 'yield_quantity' => 4, 'yield_unit' => 'personnes', 'difficulty' => 'facile', 'status' => Recipe::STATUS_PUBLISHED, 'author_id' => $this->user->id]);
        $b = RecipeImport::create(['household_id' => $this->household->id, 'paperless_document_id' => 492, 'title' => 'B', 'raw_text' => 'texte', 'status' => RecipeImport::STATUS_CREATED, 'recipe_id' => $published->id]);

        $this->actingAs($this->user)->post("/recettes/importees/{$a->id}/ignorer")->assertSessionHasErrors('paperless');
        $this->actingAs($this->user)->post("/recettes/importees/{$b->id}/ignorer")->assertSessionHasErrors('paperless');

        $this->assertNotNull(Recipe::find($planned->id));
        $this->assertNotNull(Recipe::find($published->id));
        $this->assertNotNull(RecipeImport::find($a->id));
        $this->assertNotNull(RecipeImport::find($b->id));
    }

    public function test_tout_supprimer_vide_la_liste_a_relire_sans_toucher_aux_recettes_publiees(): void
    {
        foreach ([493, 494] as $id) {
            RecipeImport::create(['household_id' => $this->household->id, 'paperless_document_id' => $id, 'title' => 'Fiche '.$id, 'raw_text' => 'texte', 'status' => RecipeImport::STATUS_TO_REVIEW]);
        }
        $published = Recipe::create(['title' => 'Publiée auto', 'slug' => 'publiee-auto', 'category' => 'plat', 'yield_quantity' => 4, 'yield_unit' => 'personnes', 'difficulty' => 'facile', 'status' => Recipe::STATUS_PUBLISHED, 'author_id' => $this->user->id]);
        $done = RecipeImport::create(['household_id' => $this->household->id, 'paperless_document_id' => 495, 'title' => 'Publiée auto', 'raw_text' => 'texte', 'status' => RecipeImport::STATUS_CREATED, 'recipe_id' => $published->id]);

        $this->actingAs($this->user)->get('/recettes/importees')->assertOk()->assertSee('Tout supprimer');
        $this->post('/recettes/importees/tout-supprimer')
            ->assertRedirect('/recettes/importees')
            ->assertSessionHas('status', fn ($s) => str_contains($s, '2 fiches supprimées'));

        $this->assertSame(0, RecipeImport::where('status', RecipeImport::STATUS_TO_REVIEW)->count());
        $this->assertNotNull(RecipeImport::find($done->id));
        $this->assertNotNull(Recipe::find($published->id));

        // Un autre foyer ne supprime rien chez nous
        $other = RecipeImport::create(['household_id' => $this->household->id, 'paperless_document_id' => 496, 'title' => 'Encore une', 'raw_text' => 'texte', 'status' => RecipeImport::STATUS_TO_REVIEW]);
        $stranger = $this->householdUser();
        $this->actingAs($stranger)->post('/recettes/importees/tout-supprimer')->assertRedirect('/recettes/importees');
        $this->assertNotNull(RecipeImport::find($other->id));
    }

    public function test_la_migration_efface_les_fiches_deja_mises_de_cote(): void
    {
        $old = RecipeImport::create(['household_id' => $this->household->id, 'paperless_document_id' => 497, 'title' => 'Ancienne', 'raw_text' => 'texte', 'status' => 'ignoree']);
        $keep = RecipeImport::create(['household_id' => $this->household->id, 'paperless_document_id' => 498, 'title' => 'A relire', 'raw_text' => 'texte', 'status' => RecipeImport::STATUS_TO_REVIEW]);

        (include database_path('migrations/2026_10_05_980001_forget_ignored_recipe_imports.php'))->up();

        $this->assertNull(RecipeImport::find($old->id));
        $this->assertNotNull(RecipeImport::find($keep->id));
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
        $this->artisan('foodtruck:recettes')->expectsOutputToContain('1 fiche prête à valider')->assertSuccessful();
    }

    public function test_relecture_en_masse_par_commande(): void
    {
        $text = str_replace('4 FEUILLES DE SAUGE (OU DE ROMARIN)', '1 BOTTE DE CERFEUIL', RecipeScanParserTest::fixture('julie-andrieu-gratin-courge'));
        $this->fakePaperless($text);
        $this->sync();

        $this->artisan('foodtruck:relire-recettes')->expectsOutputToContain('1 fiche(s) relue(s) : 0 prête(s) à valider, 1 à compléter.')->assertSuccessful();
    }
}
