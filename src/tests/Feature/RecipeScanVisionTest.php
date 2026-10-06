<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\RecipeImport;
use App\Models\User;
use App\Support\RecipeScan\RecipeLayoutRunner;
use App\Support\RecipeScan\RecipeScanSync;
use App\Support\RecipeScan\VisionComposer;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * v0.18.0 / v0.18.1 : fiches lues par le modèle de vision (Ollama et foodtruck-pages simulés), envoyées une par une
 * par Louis après une page de contrôle (pages cochées), avancement affiché, aucune autre reconnaissance de texte,
 * aucun nouvel essai automatique.
 */
class RecipeScanVisionTest extends TestCase
{
    use RefreshDatabase;

    private const PAPERLESS = 'http://paperless.test:8000';

    private const PAGES = 'http://pages.test';

    private const VISION = 'http://192.168.1.29:11434';

    private User $user;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();
        ReferenceImporter::import();
        config(['foodtruck.pages_url' => self::PAGES, 'foodtruck.vision_url' => self::VISION, 'foodtruck.vision_model' => 'qwen3-vl:8b-instruct-q8_0']);
        $this->user = $this->householdUser();
        $this->household = $this->user->household;
        $this->household->forceFill(['paperless_url' => self::PAPERLESS, 'paperless_token' => str_repeat('a', 40)])->save();
    }

    /** Réponse d'Ollama en flux : le JSON de la recette découpé en morceaux, puis la ligne finale avec les compteurs. */
    private static function stream(array $recipe): string
    {
        $json = json_encode($recipe, JSON_UNESCAPED_UNICODE);
        $lines = [];
        foreach (mb_str_split($json, 40) as $piece) {
            $lines[] = json_encode(['model' => 'qwen3-vl:8b-instruct-q8_0', 'message' => ['role' => 'assistant', 'content' => $piece], 'done' => false], JSON_UNESCAPED_UNICODE);
        }
        $lines[] = json_encode(['model' => 'qwen3-vl:8b-instruct-q8_0', 'message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'prompt_eval_count' => 7012, 'eval_count' => 912]);

        return implode("\n", $lines)."\n";
    }

    private function fake(int $visionStatus = 200, bool $pcOff = false, ?array $photo = null, ?array $pages = null): void
    {
        $recipe = json_decode(file_get_contents(base_path('tests/Fixtures/vision/hellofresh-piemontaise.json')), true);
        $recipe['ingredients'][6]['doute'] = true;   // la mayonnaise, signalée difficile à lire
        $recipe['photo'] = $photo;

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            self::PAPERLESS.'/api/documents/490/download/*' => Http::response('%PDF-1.4 scan', 200, ['Content-Type' => 'application/pdf']),
            self::PAPERLESS.'/api/tags/*' => Http::response(['count' => 1, 'results' => [['id' => 9, 'name' => 'recettes']]]),
            self::PAPERLESS.'/api/documents/*' => Http::response(['count' => 1, 'next' => null, 'results' => [[
                'id' => 490, 'title' => 'Salade façon piémontaise au jambon', 'created_date' => '2026-10-06',
                'modified' => '2026-10-06T15:00:00+02:00', 'content' => "Salade façon piémontaise\nEPENNENES CEÉIEREN",
            ]]]),
            self::PAGES.'/pages' => Http::response(['pages' => $pages ?? [base64_encode('page 1'), base64_encode('page 2')]]),
            self::PAGES.'/sante' => Http::response(['ok' => true, 'poppler' => '22.12.0']),
            self::VISION.'/api/chat' => $pcOff ? Http::failedConnection('cURL error 7: Failed to connect to 192.168.1.29 port 11434 after 2 ms: Connection refused') : ($visionStatus === 200
                ? Http::response(self::stream($recipe), 200, ['Content-Type' => 'application/x-ndjson'])
                : Http::response(json_encode(['error' => 'model "qwen3-vl:8b-instruct-q8_0" not found']), $visionStatus)),
            self::VISION.'/api/version' => $pcOff ? Http::failedConnection() : Http::response(['version' => '0.13.1']),
            self::VISION.'/api/tags' => $pcOff ? Http::failedConnection() : Http::response(['models' => [['name' => 'qwen3-vl:8b-instruct-q8_0'], ['name' => 'qwen3-vl:4b']]]),
        ]);
    }

    private function import(): RecipeImport
    {
        return RecipeImport::firstWhere('paperless_document_id', 490);
    }

    /** Synchronisation, puis envoi de la fiche par Louis avec les pages cochées. */
    private function send(array $pages = [0, 1]): RecipeImport
    {
        (new RecipeScanSync)->run($this->household->fresh());
        $import = $this->import();
        $this->actingAs($this->user)->post('/recettes/importees/'.$import->id.'/ia', ['pages' => $pages])->assertRedirect('/recettes/importees');

        return $this->import();
    }

    public function test_rien_n_est_envoye_sans_le_bouton(): void
    {
        $this->fake();
        $counts = (new RecipeScanSync)->run($this->household->fresh());
        $this->assertStringContainsString('à envoyer à l\'IA', RecipeScanSync::summary($counts));

        $import = $this->import();
        $this->assertSame(RecipeImport::LAYOUT_TO_SEND, $import->layout_status);
        $this->assertTrue($import->canBeSent());
        $this->assertFalse($import->isReading());

        // Le planificateur ne fait rien : aucune requête vers Ollama
        $this->assertSame([0, 0], array_values(array_intersect_key((new RecipeLayoutRunner)->processPending(), ['read' => 0, 'failed' => 0])));
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), self::VISION));

        $this->actingAs($this->user)->get('/recettes/importees')->assertOk()
            ->assertSee('à envoyer à l\'IA', false)->assertSee('Envoyer à l\'IA pour analyse')
            ->assertSee('/recettes/importees/'.$import->id.'/ia', false);
        $this->get('/recettes/importees/'.$import->id)->assertRedirect('/recettes/importees/'.$import->id.'/ia');
    }

    public function test_page_de_controle_montre_exactement_ce_qui_part(): void
    {
        $this->fake();
        (new RecipeScanSync)->run($this->household->fresh());
        $import = $this->import();

        $this->actingAs($this->user)->get('/recettes/importees/'.$import->id.'/ia')->assertOk()
            ->assertSee('http://192.168.1.29:11434')
            ->assertSee('qwen3-vl:8b-instruct-q8_0')
            ->assertSee('name="pages[]" value="0"', false)->assertSee('name="pages[]" value="1"', false)
            ->assertSee('data:image/png;base64,'.base64_encode('page 1'), false)
            ->assertSee('Recopie la recette exactement')
            ->assertSee('Envoyer à l\'IA');

        // L'aperçu ne contacte pas Ollama ; les pages sont demandées en 100 dpi
        Http::assertSent(fn (Request $r) => $r->url() === self::PAGES.'/pages' && $r->header('X-Dpi') === ['100']);
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), self::VISION));

        // Au moins une page
        $this->post('/recettes/importees/'.$import->id.'/ia', ['pages' => []])->assertSessionHasErrors('pages');
        $this->assertSame(RecipeImport::LAYOUT_TO_SEND, $this->import()->layout_status);
    }

    public function test_fiche_envoyee_puis_lue_par_le_modele_seulement_la_page_choisie(): void
    {
        $this->fake();
        $import = $this->send([1]);
        $this->assertSame([RecipeImport::LAYOUT_PENDING, [1]], [$import->layout_status, $import->layout_pages]);
        $this->assertTrue($import->isReading());

        $result = (new RecipeLayoutRunner)->processPending();
        $this->assertSame([1, 0], [$result['read'], $result['failed']]);

        $import = $this->import();
        $this->assertSame([RecipeImport::LAYOUT_DONE, 'vision', 'qwen3-vl:8b-instruct-q8_0', [1]], [$import->layout_status, $import->layout['source'], $import->layout['modele'], $import->layout['pages_envoyees']]);
        $this->assertSame([7012, 912], [$import->layout['jetons_lus'], $import->layout['jetons_ecrits']]);
        $this->assertNull($import->layout_progress, 'Barre de progression effacée à la fin');

        $rows = collect($import->parsed['rows']);
        $this->assertStringStartsWith('Salade façon piémontaise au jambon', $import->parsed['recipe']['title']);
        $this->assertCount(13, $rows);
        $this->assertSame(VisionComposer::DOUBT, $rows->firstWhere('label', 'Mayonnaise')['problem'], 'Ligne signalée difficile à lire par le modèle : en rouge');
        $this->assertCount(6, $import->parsed['recipe']['steps']);

        // Une seule image envoyée (la page 2), en 200 dpi, avec le JSON imposé et les réglages validés
        Http::assertSent(fn (Request $r) => $r->url() === self::PAGES.'/pages' && $r->header('X-Dpi') === ['200']);
        Http::assertSent(fn (Request $r) => $r->url() === self::VISION.'/api/chat'
            && $r['model'] === 'qwen3-vl:8b-instruct-q8_0' && $r['stream'] === true && $r['think'] === false && $r['options']['temperature'] === 0.2 && $r['options']['num_predict'] === 4096
            && $r['messages'][0]['images'] === [base64_encode('page 2')] && isset($r['format']['properties']['photo']));

        $this->get('/recettes/importees/'.$import->id)->assertOk()
            ->assertSee('Recette lue sur le scan par le modèle qwen3-vl:8b-instruct-q8_0')
            ->assertSee('Renvoyer à l\'IA pour analyse', false)
            ->assertSee('Choisir ou créer l\'ingrédient');

        // « Relire la fiche » reprend la réponse reçue, sans rien envoyer
        $chats = collect(Http::recorded())->filter(fn ($pair) => $pair[0]->url() === self::VISION.'/api/chat')->count();
        $this->post('/recettes/importees/'.$import->id.'/relire')->assertRedirect('/recettes/importees/'.$import->id);
        $this->assertSame($chats, collect(Http::recorded())->filter(fn ($pair) => $pair[0]->url() === self::VISION.'/api/chat')->count());
    }

    public function test_photo_du_plat_decoupee_proposee_puis_gardee(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        // Page 1 : fond blanc, photo (rectangle coloré) de 100,100 à 700,500 sur 1000 × 700 ; page 2 : texte
        $page = imagecreatetruecolor(1000, 700);
        imagefill($page, 0, 0, imagecolorallocate($page, 255, 255, 255));
        imagefilledrectangle($page, 100, 100, 699, 499, imagecolorallocate($page, 120, 160, 90));
        ob_start();
        imagepng($page);
        $png = base64_encode((string) ob_get_clean());
        // Cadre du modèle un peu trop large (relatif 0-1000) : resserré sur la photo
        $this->fake(photo: ['page' => 1, 'x1' => 80, 'y1' => 120, 'x2' => 720, 'y2' => 740], pages: [$png, base64_encode('page 2')]);

        $this->send([0, 1]);
        (new RecipeLayoutRunner)->processPending();
        $import = $this->import();
        $path = $import->layout['photo']['chemin'];
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($path);
        [$w, $h] = getimagesizefromstring(\Illuminate\Support\Facades\Storage::disk('public')->get($path));
        $this->assertEqualsWithDelta(600, $w, 4, 'Bandes blanches retirées');
        $this->assertEqualsWithDelta(400, $h, 4);

        $this->get('/recettes/importees/'.$import->id)->assertOk()->assertSee('Utiliser la photo de la fiche (découpée par l\'IA)', false)->assertSee('name="import_photo"', false);

        // Validation avec la photo cochée : elle devient la photo de la recette, le fichier provisoire disparaît
        $ingredient = \App\Models\Ingredient::where('base_unit', 'g')->first();
        $this->post('/recettes', [
            'title' => 'Salade façon piémontaise au jambon', 'category' => 'plat', 'yield_quantity' => 2, 'yield_unit' => 'personnes', 'difficulty' => 'facile',
            'ingredients' => [['name' => $ingredient->name, 'quantity' => '100', 'unit' => 'g']],
            'steps' => [['body' => 'Mélanger.']],
            'import_id' => $import->id, 'import_photo' => '1',
        ])->assertRedirect();
        $recipe = \App\Models\Recipe::firstWhere('title', 'Salade façon piémontaise au jambon');
        $this->assertNotNull($recipe->photo_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($recipe->photo_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($path);
    }

    public function test_une_fiche_a_la_fois_et_annulation(): void
    {
        $this->fake();
        $first = $this->send([0, 1]);
        $second = RecipeImport::create(['household_id' => $this->household->id, 'paperless_document_id' => 491, 'status' => RecipeImport::STATUS_TO_REVIEW,
            'layout_status' => RecipeImport::LAYOUT_TO_SEND, 'title' => 'Salade de grenailles']);

        $this->get('/recettes/importees/'.$second->id.'/ia')->assertOk()->assertSee('Une autre fiche est en cours d\'analyse (Paperless n° 490)', false);
        $this->post('/recettes/importees/'.$second->id.'/ia', ['pages' => [0]])->assertSessionHasErrors('pages');
        $this->assertSame(RecipeImport::LAYOUT_TO_SEND, $second->fresh()->layout_status);
        $this->get('/recettes/importees')->assertSee('aria-disabled="true"', false)->assertSee('Annuler l\'envoi', false);

        // Annulation avant le début de l'analyse : rien n'est parti
        $this->post('/recettes/importees/'.$first->id.'/ia/annuler')->assertRedirect('/recettes/importees');
        $this->assertSame(RecipeImport::LAYOUT_TO_SEND, $this->import()->layout_status);
        $this->assertSame(0, (new RecipeLayoutRunner)->processPending()['read']);
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), self::VISION));

        // Analyse commencée : plus d'annulation
        $this->send([0]);
        $this->import()->forceFill(['layout_progress' => 30])->save();
        $this->post('/recettes/importees/'.$first->id.'/ia/annuler')->assertSessionHasErrors('paperless');
        $this->assertSame(RecipeImport::LAYOUT_PENDING, $this->import()->layout_status);
    }

    public function test_pc_eteint_erreur_affichee_et_renvoi_a_la_main(): void
    {
        $this->fake(pcOff: true);
        $this->send();

        $result = (new RecipeLayoutRunner)->processPending();
        $this->assertSame([0, 1], [$result['read'], $result['failed']]);
        $import = $this->import();
        $this->assertSame(RecipeImport::LAYOUT_FAILED, $import->layout_status);
        $this->assertStringContainsString('PC éteint ou Ollama arrêté', $import->layout_error);
        $this->assertSame([], $import->parsed['rows'], 'Rien n\'est deviné, aucun texte d\'OCR');
        $this->assertStringContainsString('« Renvoyer à l\'IA » pour réessayer', implode(' ', $import->issues));

        // Aucun nouvel essai automatique
        $this->assertSame(0, (new RecipeLayoutRunner)->processPending()['failed']);

        $this->get('/recettes/importees')->assertSee('échec de l\'analyse', false)->assertSee('Renvoyer à l\'IA');
        $this->get('/recettes/importees/'.$import->id)->assertOk()->assertSee('PC éteint ou Ollama arrêté');
        $this->get('/recettes/importees/'.$import->id.'/ia')->assertOk()->assertSee('Dernier envoi : Ollama injoignable');
        $this->artisan('foodtruck:check')->expectsOutputToContain('PC éteint ou Ollama arrêté : les fiches attendent');
    }

    public function test_reponse_illisible_erreur_affichee(): void
    {
        $this->fake(404);
        $this->send();
        (new RecipeLayoutRunner)->processPending();
        $import = $this->import();
        $this->assertSame(RecipeImport::LAYOUT_FAILED, $import->layout_status);
        $this->assertStringContainsString('not found', $import->layout_error);
    }

    public function test_avancement_de_l_analyse(): void
    {
        $this->fake();
        $import = $this->send();
        $this->get('/recettes/importees')->assertSee('envoyée, en file')->assertSee('id="read-progress-url"', false);
        $this->assertSame('Envoyée : l\'analyse démarre dans moins d\'une minute', $this->getJson('/recettes/importees/progression')->json('reading.0.step'));

        $import->forceFill(['layout_progress' => 42, 'layout_step' => 'Lecture des images par le modèle (2 pages)', 'layout_started_at' => now()->subSeconds(95)])->save();
        $this->get('/recettes/importees')->assertOk()->assertSee('data-read-progress="'.$import->id.'"', false)->assertSee('width: 42%', false);
        $json = $this->getJson('/recettes/importees/progression')->assertOk()->json('reading');
        $this->assertSame([$import->id, 42, 'Lecture des images par le modèle (2 pages)'], [$json[0]['id'], $json[0]['progress'], $json[0]['step']]);
        $this->assertGreaterThanOrEqual(95, $json[0]['since']);
    }

    public function test_sante_et_fiches_remises_a_envoyer(): void
    {
        $this->fake();
        $this->artisan('foodtruck:check')
            ->expectsOutputToContain('Préparation des pages (foodtruck-pages) : Poppler 22.12.0')
            ->expectsOutputToContain('Lecture par le modèle de vision : qwen3-vl:8b-instruct-q8_0 (Ollama 0.13.1, http://192.168.1.29:11434)');

        // Fiche lue avant la v0.18 (Tesseract) : « --toutes » la remet « à envoyer », sans rien envoyer
        (new RecipeScanSync)->run($this->household->fresh());
        $this->import()->forceFill(['layout_status' => RecipeImport::LAYOUT_DONE, 'layout' => ['version' => 1, 'pages' => []]])->save();
        $this->artisan('foodtruck:lire-fiches --toutes')->expectsOutputToContain('1 fiche(s) « à envoyer à l\'IA »')->assertExitCode(0);
        $this->assertSame(RecipeImport::LAYOUT_TO_SEND, $this->import()->layout_status);
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), self::VISION.'/api/chat'));
    }
}
