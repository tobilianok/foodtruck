<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\RecipeImport;
use App\Models\User;
use App\Support\RecipeScan\RecipeLayoutRunner;
use App\Support\RecipeScan\RecipeScanSync;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * v0.15.0 : fiches lues d'après le scan par le service foodtruck-ocr (Paperless et le service sont simulés).
 * Synchronisation → fiche « en cours de lecture » → lecture en arrière-plan → analyse ; texte de Paperless en secours.
 */
class RecipeScanLayoutTest extends TestCase
{
    use RefreshDatabase;

    private const PAPERLESS = 'http://paperless.test:8000';

    private const OCR = 'http://ocr.test';

    private User $user;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();
        ReferenceImporter::import();
        // Les crozets restent inconnus du référentiel : la fiche attend toujours sa relecture (pas de recette créée)
        \App\Models\Ingredient::where('name', 'like', '%crozet%')->delete();
        config(['foodtruck.ocr_url' => self::OCR]);
        $this->user = $this->householdUser();
        $this->household = $this->user->household;
        $this->household->forceFill(['paperless_url' => self::PAPERLESS, 'paperless_token' => str_repeat('a', 40)])->save();
    }

    private function fake(int $ocrStatus = 200): void
    {
        $paperlessText = file_get_contents(base_path('tests/Fixtures/paperless/leclerc-croziflette.txt'));
        $layout = json_decode(file_get_contents(base_path('tests/Fixtures/layout/leclerc-croziflette.json')), true);

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            self::PAPERLESS.'/api/documents/487/download/*' => Http::response('%PDF-1.4 scan', 200, ['Content-Type' => 'application/pdf']),
            self::PAPERLESS.'/api/tags/*' => Http::response(['count' => 1, 'results' => [['id' => 9, 'name' => 'recettes']]]),
            self::PAPERLESS.'/api/documents/*' => Http::response(['count' => 1, 'next' => null, 'results' => [[
                'id' => 487, 'title' => 'Croziflette', 'created_date' => '2026-10-05',
                'modified' => '2026-10-05T16:09:14+02:00', 'content' => $paperlessText,
            ]]]),
            self::OCR.'/lire' => $ocrStatus === 200 ? Http::response($layout) : Http::response(['erreur' => 'Tesseract arrêté'], $ocrStatus),
            self::OCR.'/sante' => Http::response(['ok' => true, 'tesseract' => '5.3.0', 'langues' => ['fra', 'osd']]),
        ]);
    }

    private function import(): RecipeImport
    {
        return RecipeImport::firstWhere('paperless_document_id', 487);
    }

    public function test_la_fiche_est_lue_d_apres_le_scan_en_arriere_plan(): void
    {
        $this->fake();

        $counts = (new RecipeScanSync)->run($this->household->fresh());
        $this->assertSame([1, 1], [$counts['new'], $counts['reading']]);
        $this->assertStringContainsString('en cours de lecture', RecipeScanSync::summary($counts));
        $this->assertTrue($this->import()->isReading());
        $this->assertNull($this->import()->parsed, 'Rien n\'est analysé avant la lecture du scan');

        $this->actingAs($this->user)->get('/recettes/importees')->assertOk()->assertSee('lecture en cours…')->assertSee('window.location.reload', false);
        $this->get('/recettes/importees/'.$this->import()->id)->assertRedirect('/recettes/importees');

        $result = (new RecipeLayoutRunner)->processPending();
        $this->assertSame([1, 0, 0], [$result['read'], $result['failed'], $result['left']]);

        $import = $this->import();
        $this->assertSame(RecipeImport::LAYOUT_DONE, $import->layout_status);
        $this->assertSame(['reblochon', 'oignon', 'crème fraîche', 'lardons', 'crozets', 'sel', 'poivre'], array_column($import->parsed['rows'], 'label'));
        $this->assertCount(5, $import->parsed['recipe']['steps']);
        $this->assertSame([4.0, 15], [(float) $import->parsed['recipe']['yield_quantity'], $import->parsed['recipe']['prep_minutes']]);
        foreach ($import->issues as $issue) {
            $this->assertStringNotContainsString('introuvable', $issue);
            $this->assertStringNotContainsString('Colonnes', $issue, 'Plus besoin de séparer les colonnes : le scan les garde');
        }

        Http::assertSent(fn (Request $r) => $r->url() === self::PAPERLESS.'/api/documents/487/download/' && $r->hasHeader('Authorization', 'Token '.str_repeat('a', 40)));
        Http::assertSent(fn (Request $r) => $r->url() === self::OCR.'/lire' && $r->method() === 'POST' && $r->body() === '%PDF-1.4 scan');

        // La relecture affiche le texte lu sur le scan
        $this->get('/recettes/importees/'.$import->id)->assertOk()->assertSee('Texte lu sur le scan par Foodtruck')->assertSee('Etape 5');
    }

    public function test_service_injoignable_le_texte_de_paperless_sert_et_la_lecture_se_relance(): void
    {
        $this->fake(500);
        (new RecipeScanSync)->run($this->household->fresh());

        $result = (new RecipeLayoutRunner)->processPending();
        $this->assertSame([0, 1], [$result['read'], $result['failed']]);

        $import = $this->import();
        $this->assertSame(RecipeImport::LAYOUT_FAILED, $import->layout_status);
        $this->assertStringContainsString('Tesseract arrêté', $import->layout_error);
        $this->assertCount(7, $import->parsed['rows'], 'Le texte de Paperless (colonnes séparées par les règles) sert en secours');
        $this->assertTrue(collect($import->issues)->contains(fn ($i) => str_contains($i, 'Lecture du scan impossible')));

        // « Relire la fiche » relance la lecture du scan
        $this->actingAs($this->user)->post('/recettes/importees/'.$import->id.'/relire')
            ->assertRedirect('/recettes/importees')
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Lecture du scan relancée'));
        $this->assertTrue($import->fresh()->isReading());

        $this->fake();
        (new RecipeLayoutRunner)->processPending();
        $this->assertSame(RecipeImport::LAYOUT_DONE, $this->import()->layout_status);
    }

    public function test_commande_lire_toutes_les_fiches_et_sante_du_service(): void
    {
        $this->fake();
        RecipeImport::create([
            'household_id' => $this->household->id, 'paperless_document_id' => 487, 'title' => 'Croziflette',
            'raw_text' => 'texte illisible', 'status' => RecipeImport::STATUS_TO_REVIEW, 'issues' => ['Liste d\'ingrédients introuvable.'],
        ]);

        $this->assertSame(0, Artisan::call('foodtruck:lire-fiches', ['--toutes' => true]));
        $this->assertStringContainsString('1 fiche(s) lue(s)', Artisan::output());
        $this->assertSame(RecipeImport::LAYOUT_DONE, $this->import()->layout_status);
        $this->assertCount(7, $this->import()->parsed['rows']);

        // Contrôle du service (utilisé par foodtruck:check)
        $this->assertSame(['ok' => true, 'tesseract' => '5.3.0', 'langues' => ['fra', 'osd']], \App\Support\RecipeScan\OcrClient::make()->health());
    }

    public function test_sans_service_la_fiche_est_lue_tout_de_suite_comme_avant(): void
    {
        config(['foodtruck.ocr_url' => null]);
        $this->fake();

        $counts = (new RecipeScanSync)->run($this->household->fresh());

        $this->assertSame([1, 0], [$counts['new'], $counts['reading']]);
        $this->assertNull($this->import()->layout_status);
        $this->assertCount(7, $this->import()->parsed['rows']);
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), self::OCR));
    }
}
