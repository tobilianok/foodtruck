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
 * v0.18.0 : fiches lues par le modèle de vision de srv-nas (Ollama et foodtruck-pages simulés), avancement de la
 * lecture affiché dans « Fiches Paperless », aucune autre reconnaissance de texte (échec : la lecture est retentée).
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

    private function fake(int $visionStatus = 200, bool $pcOff = false): void
    {
        $recipe = json_decode(file_get_contents(base_path('tests/Fixtures/vision/hellofresh-piemontaise.json')), true);
        $recipe['ingredients'][6]['doute'] = true;   // la mayonnaise, signalée difficile à lire

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            self::PAPERLESS.'/api/documents/490/download/*' => Http::response('%PDF-1.4 scan', 200, ['Content-Type' => 'application/pdf']),
            self::PAPERLESS.'/api/tags/*' => Http::response(['count' => 1, 'results' => [['id' => 9, 'name' => 'recettes']]]),
            self::PAPERLESS.'/api/documents/*' => Http::response(['count' => 1, 'next' => null, 'results' => [[
                'id' => 490, 'title' => 'Salade façon piémontaise au jambon', 'created_date' => '2026-10-06',
                'modified' => '2026-10-06T15:00:00+02:00', 'content' => "Salade façon piémontaise\nEPENNENES CEÉIEREN",
            ]]]),
            self::PAGES.'/pages' => Http::response(['pages' => [base64_encode('page 1'), base64_encode('page 2')]]),
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

    public function test_fiche_lue_par_le_modele_de_vision(): void
    {
        $this->fake();
        (new RecipeScanSync)->run($this->household->fresh());
        $this->assertTrue($this->import()->isReading());

        $result = (new RecipeLayoutRunner)->processPending();
        $this->assertSame([1, 0], [$result['read'], $result['failed']]);

        $import = $this->import();
        $this->assertSame([RecipeImport::LAYOUT_DONE, 'vision', 'qwen3-vl:8b-instruct-q8_0'], [$import->layout_status, $import->layout['source'], $import->layout['modele']]);
        $this->assertSame([7012, 912], [$import->layout['jetons_lus'], $import->layout['jetons_ecrits']]);
        $this->assertNull($import->layout_progress, 'Barre de progression effacée à la fin');
        $this->assertNull($import->layout_step);

        $rows = collect($import->parsed['rows']);
        $this->assertStringStartsWith('Salade façon piémontaise au jambon', $import->parsed['recipe']['title']);
        $this->assertCount(13, $rows);
        $this->assertSame(['Mayonnaise', 'Moutarde'], $rows->whereIn('label', ['Mayonnaise', 'Moutarde'])->pluck('label')->values()->all());
        $mayo = $rows->firstWhere('label', 'Mayonnaise');
        $this->assertSame(VisionComposer::DOUBT, $mayo['problem'], 'Ligne signalée difficile à lire par le modèle : en rouge');
        $this->assertCount(6, $import->parsed['recipe']['steps']);

        // Le modèle reçoit les pages préparées par foodtruck-pages, avec le JSON imposé et les réglages validés
        Http::assertSent(fn (Request $r) => $r->url() === self::PAGES.'/pages' && $r->header('X-Dpi') === ['200'] && $r->body() === '%PDF-1.4 scan');
        Http::assertSent(fn (Request $r) => $r->url() === self::VISION.'/api/chat'
            && $r['model'] === 'qwen3-vl:8b-instruct-q8_0' && $r['stream'] === true && $r['think'] === false && $r['options']['temperature'] === 0.2 && $r['options']['num_predict'] === 4096
            && count($r['messages'][0]['images']) === 2 && isset($r['format']['properties']['ingredients']));

        $this->actingAs($this->user)->get('/recettes/importees/'.$import->id)->assertOk()
            ->assertSee('Recette lue sur le scan par le modèle qwen3-vl:8b-instruct-q8_0')
            ->assertSee('Choisir ou créer l\'ingrédient');
    }

    public function test_srv_nas_indisponible_la_lecture_est_retentee_sans_autre_ocr(): void
    {
        $this->fake(404);
        (new RecipeScanSync)->run($this->household->fresh());

        $result = (new RecipeLayoutRunner)->processPending();
        $this->assertSame([0, 1], [$result['read'], $result['failed']]);
        $import = $this->import();
        $this->assertSame([RecipeImport::LAYOUT_PENDING, 1], [$import->layout_status, $import->layout_attempts]);
        $this->assertStringContainsString('not found', $import->layout_error);
        $this->assertNull($import->parsed, 'Rien n\'est deviné : la fiche attend sa lecture');
        $this->assertTrue($import->layout_retry_at->between(now()->addMinutes(4), now()->addMinutes(6)), 'Nouvel essai 5 minutes plus tard');

        // Pas encore l'heure : la fiche n'est pas relue
        $this->assertSame(0, (new RecipeLayoutRunner)->processPending()['failed']);

        // Après 6 essais : la fiche attend « Relire la fiche », l'erreur est affichée, aucun texte d'OCR n'est utilisé
        $import->forceFill(['layout_attempts' => RecipeLayoutRunner::MAX_ATTEMPTS - 1, 'layout_retry_at' => now()->subMinute()])->save();
        (new RecipeLayoutRunner)->processPending();
        $import = $this->import();
        $this->assertSame(RecipeImport::LAYOUT_FAILED, $import->layout_status);
        $this->assertSame([], $import->parsed['rows']);
        $this->assertStringContainsString('impossible après 6 essais', implode(' ', $import->issues));
        $this->assertStringNotContainsString('EPENNENES', json_encode($import->parsed, JSON_UNESCAPED_UNICODE), 'Le texte OCR de Paperless ne sert plus');

        // « Relire la fiche » remet la fiche en lecture, compteur remis à zéro
        $this->actingAs($this->user)->post('/recettes/importees/'.$import->id.'/relire')->assertRedirect('/recettes/importees');
        $this->assertSame([RecipeImport::LAYOUT_PENDING, 0], [$this->import()->layout_status, $this->import()->layout_attempts]);
    }

    public function test_pc_eteint_la_fiche_attend_sans_limite_de_tentatives(): void
    {
        $this->fake(pcOff: true);
        (new RecipeScanSync)->run($this->household->fresh());

        for ($i = 0; $i < RecipeLayoutRunner::MAX_ATTEMPTS + 2; $i++) {
            $this->import()->forceFill(['layout_retry_at' => null])->save();
            $this->assertSame(1, (new RecipeLayoutRunner)->processPending()['failed']);
        }

        $import = $this->import();
        $this->assertSame([RecipeImport::LAYOUT_PENDING, 0], [$import->layout_status, $import->layout_attempts], 'PC éteint : jamais compté comme un échec');
        $this->assertStringContainsString('PC éteint ou Ollama arrêté', $import->layout_error);
        $this->assertTrue($import->layout_retry_at->between(now()->addMinutes(14), now()->addMinutes(16)));

        $step = $this->actingAs($this->user)->getJson('/recettes/importees/progression')->json('reading.0.step');
        $this->assertStringContainsString('En attente : Ollama injoignable (http://192.168.1.29:11434)', $step);
        $this->assertStringContainsString('nouvel essai vers', $step);
        $this->artisan('foodtruck:check')->expectsOutputToContain('PC éteint ou Ollama arrêté : les fiches attendent');
    }

    public function test_avancement_de_la_lecture_et_relecture_par_le_modele(): void
    {
        $this->fake();
        (new RecipeScanSync)->run($this->household->fresh());
        $import = $this->import();
        $import->forceFill(['layout_progress' => 42, 'layout_step' => 'Lecture des images par le modèle (2 pages)', 'layout_started_at' => now()->subSeconds(95)])->save();

        $this->actingAs($this->user)->get('/recettes/importees')->assertOk()
            ->assertSee('data-read-progress="'.$import->id.'"', false)
            ->assertSee('width: 42%', false)
            ->assertSee('id="read-progress-url"', false);

        $json = $this->getJson('/recettes/importees/progression')->assertOk()->json('reading');
        $this->assertSame([$import->id, 42, 'Lecture des images par le modèle (2 pages)'], [$json[0]['id'], $json[0]['progress'], $json[0]['step']]);
        $this->assertGreaterThanOrEqual(95, $json[0]['since']);

        // Fiche déjà lue par Tesseract : « Relire la fiche » la fait relire par le modèle
        $import->forceFill(['layout_status' => RecipeImport::LAYOUT_DONE, 'layout' => ['version' => 1, 'pages' => []], 'layout_progress' => null])->save();
        $this->post('/recettes/importees/'.$import->id.'/relire')->assertRedirect('/recettes/importees');
        $this->assertSame(RecipeImport::LAYOUT_PENDING, $this->import()->layout_status);
        $waiting = $this->getJson('/recettes/importees/progression')->json('reading');
        $this->assertSame([$import->id, null, 'En attente de lecture (une fiche à la fois)'], [$waiting[0]['id'], $waiting[0]['progress'], $waiting[0]['step']], 'Fiche en attente : listée sans avancement');
    }

    public function test_sante_du_modele_et_remise_en_lecture_sans_attendre(): void
    {
        $this->fake();
        $this->artisan('foodtruck:check')
            ->expectsOutputToContain('Préparation des pages (foodtruck-pages) : Poppler 22.12.0')
            ->expectsOutputToContain('Lecture par le modèle de vision : qwen3-vl:8b-instruct-q8_0 (Ollama 0.13.1, http://192.168.1.29:11434)');

        (new RecipeScanSync)->run($this->household->fresh());
        (new RecipeLayoutRunner)->processPending();
        $this->artisan('foodtruck:lire-fiches --toutes --en-attente')->expectsOutputToContain('1 fiche(s) remise(s) en lecture')->assertExitCode(0);
        $this->assertSame(RecipeImport::LAYOUT_PENDING, $this->import()->layout_status);
    }
}
