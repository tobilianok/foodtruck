<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\IngredientPack;
use App\Models\Price;
use App\Models\Receipt;
use App\Models\ReceiptAlias;
use App\Models\ReceiptLine;
use App\Models\RecipeImport;
use App\Models\Store;
use App\Models\User;
use App\Support\Receipts\ReceiptParser;
use App\Support\Receipts\ReceiptSync;
use App\Support\Receipts\ReceiptVisionRunner;
use App\Support\ReceiptWipe;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * v0.19.0 : tickets lus par le modèle de vision (Ollama, foodtruck-pages et Paperless simulés), envoyés un par un par
 * Louis après une page de contrôle, interprétés et contrôlés par le code, toujours validés par Louis avec les
 * corrections faites dans la page ; suppression de tous les tickets pour repartir d'une base saine.
 */
class ReceiptVisionTest extends TestCase
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

    private static function stream(array $ticket): string
    {
        $json = json_encode($ticket, JSON_UNESCAPED_UNICODE);
        $lines = [];
        foreach (mb_str_split($json, 40) as $piece) {
            $lines[] = json_encode(['message' => ['role' => 'assistant', 'content' => $piece], 'done' => false], JSON_UNESCAPED_UNICODE);
        }
        $lines[] = json_encode(['message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 5525, 'eval_count' => 669]);

        return implode("\n", $lines)."\n";
    }

    private function fake(string $fixture = '473', bool $pcOff = false): void
    {
        $ticket = json_decode(file_get_contents(base_path('tests/Fixtures/vision/tickets/'.$fixture.'.json')), true);

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            self::PAPERLESS.'/api/documents/473/download/*' => Http::response('%PDF-1.4 ticket', 200, ['Content-Type' => 'application/pdf']),
            self::PAPERLESS.'/api/tags/*' => Http::response(['count' => 1, 'results' => [['id' => 4, 'name' => 'courses alimentaires']]]),
            self::PAPERLESS.'/api/correspondents/*' => Http::response(['count' => 1, 'results' => [['id' => 7, 'name' => 'Lidl']]]),
            self::PAPERLESS.'/api/documents/*' => Http::response(['count' => 1, 'next' => null, 'results' => [[
                'id' => 473, 'title' => '2026-09-19 Lidl', 'correspondent' => 7, 'created_date' => '2026-09-29',
                'modified' => '2026-09-29T10:00:00+02:00', 'content' => "LIDL\nHarry's Mie Nature 1,61\nA payer 9,88",
            ]]]),
            self::PAGES.'/pages' => Http::response(['pages' => [base64_encode('morceau 1'), base64_encode('morceau 2'), base64_encode('morceau 3'), base64_encode('morceau 4')]]),
            self::VISION.'/api/chat' => $pcOff
                ? Http::failedConnection('cURL error 7: Failed to connect to 192.168.1.29 port 11434 after 2 ms: Connection refused')
                : Http::response(self::stream($ticket), 200, ['Content-Type' => 'application/x-ndjson']),
        ]);
    }

    private function receipt(): Receipt
    {
        return Receipt::firstWhere('paperless_document_id', 473);
    }

    /** Synchronisation, envoi par Louis depuis la page de contrôle, puis passage du planificateur. */
    private function readByAi(string $fixture = '473'): Receipt
    {
        $this->fake($fixture);
        (new ReceiptSync)->run($this->household->fresh());
        $this->actingAs($this->user)->post('/tickets/'.$this->receipt()->id.'/ia')->assertRedirect('/tickets');
        (new ReceiptVisionRunner)->processPending();

        return $this->receipt()->load('lines.pack.ingredient', 'lines.ingredient');
    }

    public function test_rien_n_est_envoye_sans_le_bouton(): void
    {
        $this->fake();
        $counts = (new ReceiptSync)->run($this->household->fresh());
        $this->assertSame(1, $counts['to_send']);
        $this->assertStringContainsString('à envoyer à l\'IA', ReceiptSync::summary($counts));

        $receipt = $this->receipt();
        $this->assertSame([Receipt::VISION_TO_SEND, Receipt::STATUS_TO_REVIEW, 0], [$receipt->vision_status, $receipt->status, $receipt->lines()->count()]);
        $this->assertTrue($receipt->awaitsVision());

        // Le planificateur ne fait rien, le texte de Paperless n'est pas lu
        $this->assertSame(0, (new ReceiptVisionRunner)->processPending()['read']);
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), self::VISION));

        $this->actingAs($this->user)->get('/tickets')->assertOk()
            ->assertSee('à envoyer à l\'IA', false)->assertSee('Envoyer à l\'IA pour analyse')
            ->assertSee('/tickets/'.$receipt->id.'/ia', false);
        $this->get('/tickets/'.$receipt->id)->assertRedirect('/tickets/'.$receipt->id.'/ia');
    }

    public function test_page_de_controle_montre_les_morceaux_du_ticket(): void
    {
        $this->fake();
        (new ReceiptSync)->run($this->household->fresh());
        $receipt = $this->receipt();

        $this->actingAs($this->user)->get('/tickets/'.$receipt->id.'/ia')->assertOk()
            ->assertSee('Images envoyées')->assertSee('Morceau 4')->assertSee(self::VISION)->assertSee('qwen3-vl:8b-instruct-q8_0')
            ->assertSee('date_imprimee')->assertSee('Recopie-le exactement tel qu', false);

        // Mode ticket demandé à foodtruck-pages ; rien vers Ollama
        Http::assertSent(fn (Request $r) => $r->url() === self::PAGES.'/pages' && $r->header('X-Mode') === ['ticket']);
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), self::VISION));
    }

    public function test_lecture_par_l_ia_puis_validation_par_louis(): void
    {
        $receipt = $this->readByAi();

        // Une seule requête vers Ollama : les 4 morceaux, la consigne des tickets, réglages des tickets
        Http::assertSentCount(1 + 1 + 3 + 1); // téléchargement + pages + synchronisation (étiquette, correspondants, documents) + Ollama
        Http::assertSent(function (Request $r) {
            if ($r->url() !== self::VISION.'/api/chat') {
                return false;
            }
            $body = $r->data();

            return count($body['messages'][0]['images']) === 4 && $body['think'] === false && $body['options']['num_ctx'] === 24576 && $body['options']['num_predict'] === 8192 && ! isset($body['options']['progress'])
                && str_contains($body['messages'][0]['content'], 'ticket de caisse') && isset($body['format']['properties']['lignes']);
        });

        $this->assertSame([Receipt::VISION_DONE, null, null], [$receipt->vision_status, $receipt->vision_progress, $receipt->vision_error]);
        $this->assertSame('qwen3-vl:8b-instruct-q8_0', $receipt->vision['modele']);
        $this->assertSame(4, $receipt->vision['images']);
        $this->assertSame([988, '2026-09-19', 988], [$receipt->total_cents, $receipt->purchased_on->toDateString(), $receipt->linesTotalCents()]);
        $this->assertSame('lidl', $receipt->store->slug);
        $this->assertNotContains('Nombre de lignes: 4', $receipt->lines->pluck('raw_label')->all());
        $banane = $receipt->lines->firstWhere('raw_label', 'Banane vrac');
        $this->assertSame(['kg', 1.018, 149], [$banane->quantity_unit, $banane->quantity, $banane->unit_price_cents]);

        // Jamais traité automatiquement : Louis valide
        $this->assertSame(Receipt::STATUS_TO_REVIEW, $receipt->status);
        $this->assertSame(0, Price::where('source', 'ticket')->count());

        $page = $this->actingAs($this->user)->get('/tickets/'.$receipt->id)->assertOk();
        $page->assertSee('Lu par l\'IA (qwen3-vl:8b-instruct-q8_0)', false)->assertSee('Somme des lignes = total')
            ->assertSee('receipt-fix', false)->assertSee('Valider le ticket et enregistrer les prix')->assertSee('Ce que l\'IA a recopié', false);
    }

    public function test_corrections_faites_dans_la_fenetre(): void
    {
        $receipt = $this->readByAi('v2-478');
        $lpm = $receipt->lines->firstWhere('raw_label', 'LPM calendula&coco');
        $this->assertSame([599, ReceiptParser::NOTE_DEDUCED], [$lpm->total_cents, $lpm->note]);
        $this->actingAs($this->user)->get('/tickets/'.$receipt->id)->assertSee('Vérifier la lecture')->assertSee('montant déduit du total');

        // Fenêtre « Corriger » : lecture vérifiée (prix confirmé), ligne ignorée ; l'autre ligne associée à un ingrédient
        $navet = $receipt->lines->firstWhere('raw_label', 'Navet');
        $lines = [
            $lpm->id => ['action' => 'ignorer_toujours', 'quantity' => '1', 'unit' => 'piece', 'unit_price' => '5,99', 'price' => '5,99', 'checked' => '1'],
            $navet->id => ['action' => 'associer', 'choice' => 'Navet', 'quantity' => '0,616', 'unit' => 'kg', 'unit_price' => '1,99', 'price' => '1,23', 'checked' => '1'],
        ];
        $this->put('/tickets/'.$receipt->id, ['store_id' => $receipt->store_id, 'purchased_on' => '2025-10-02', 'total' => '109,09', 'lines' => $lines])
            ->assertSessionHasNoErrors();

        $lpm->refresh();
        $navet->refresh();
        $this->assertSame([ReceiptLine::STATUS_IGNORED, null], [$lpm->status, $lpm->note]);
        $this->assertTrue(ReceiptAlias::where('normalized_label', 'LPM CALENDULA&COCO')->orWhere('normalized_label', 'LPM CALENDULA COCO')->first()->is_ignored);
        $this->assertSame([ReceiptLine::STATUS_APPLIED, 0.616, 'kg', null], [$navet->status, $navet->quantity, $navet->quantity_unit, $navet->note]);
        $this->assertNotNull($navet->price_id);
        $this->assertSame(Receipt::STATUS_TO_REVIEW, $receipt->fresh()->status, 'Les autres lignes restent à valider');
    }

    public function test_champs_caches_sans_correction_ne_changent_rien(): void
    {
        $receipt = $this->readByAi('v2-478');
        $lpm = $receipt->lines->firstWhere('raw_label', 'LPM calendula&coco');
        $rosti = $receipt->lines->firstWhere('raw_label', 'Rösti');
        $rosti->forceFill(['unit_price_cents' => null])->save();

        // Formulaire envoyé sans passer par la fenêtre : champs cachés présents, checked=0
        $lines = [
            $lpm->id => ['action' => 'associer', 'choice' => '', 'quantity' => '1', 'unit' => 'piece', 'unit_price' => '', 'price' => '5,99', 'checked' => '0'],
            $rosti->id => ['action' => 'associer', 'choice' => '', 'quantity' => '1', 'unit' => 'piece', 'unit_price' => '', 'price' => '1,85', 'checked' => '0'],
        ];
        $this->actingAs($this->user)->put('/tickets/'.$receipt->id, ['store_id' => $receipt->store_id, 'purchased_on' => '2025-10-02', 'lines' => $lines]);

        $this->assertSame(ReceiptParser::NOTE_DEDUCED, $lpm->fresh()->note, 'La ligne reste « à vérifier »');
        $this->assertNull($rosti->fresh()->unit_price_cents);
    }

    public function test_analyse_ratee_sans_ligne_jamais_traitee(): void
    {
        $this->test_pc_eteint_erreur_affichee_sans_nouvel_essai();
        $receipt = $this->receipt();

        // Pas de page de validation vide : retour à la page de contrôle (« Renvoyer à l'IA »)
        $this->get('/tickets/'.$receipt->id)->assertRedirect('/tickets/'.$receipt->id.'/ia');
        $this->put('/tickets/'.$receipt->id, ['store_id' => Store::firstWhere('slug', 'lidl')->id, 'purchased_on' => '2026-09-19', 'lines' => []]);
        $this->assertSame(Receipt::STATUS_TO_REVIEW, $receipt->fresh()->status);
        $this->assertTrue($receipt->fresh()->canBeSent());
        $this->get('/tickets/'.$receipt->id.'/ia')->assertSee('Ignorer ce ticket');
    }

    public function test_annulation_d_un_ticket_lu_avant_la_v0_19_0(): void
    {
        $this->fake();
        (new ReceiptSync)->run($this->household->fresh());
        $receipt = $this->receipt();
        // Ticket lu depuis le texte avant la v0.19.0 : lignes présentes, pas d'état de lecture par l'IA
        $receipt->forceFill(['vision_status' => null])->save();
        $receipt->lines()->create(['position' => 10, 'kind' => 'produit', 'raw_label' => 'Banane', 'normalized_label' => 'BANANE', 'total_cents' => 152, 'status' => ReceiptLine::STATUS_UNKNOWN]);

        $this->actingAs($this->user)->get('/tickets/'.$receipt->id)->assertOk()->assertSee('Renvoyer à l\'IA', false);
        $this->post('/tickets/'.$receipt->id.'/ia')->assertRedirect('/tickets');
        $this->post('/tickets/'.$receipt->id.'/ia/annuler');
        $this->assertNull($receipt->fresh()->vision_status);
        $this->get('/tickets/'.$receipt->id)->assertOk();
    }

    public function test_un_document_a_la_fois_et_annulation(): void
    {
        $this->fake();
        (new ReceiptSync)->run($this->household->fresh());
        $receipt = $this->receipt();

        // Une fiche de recette en cours d'analyse bloque l'envoi d'un ticket
        $import = RecipeImport::create(['household_id' => $this->household->id, 'paperless_document_id' => 490, 'status' => RecipeImport::STATUS_TO_REVIEW,
            'layout_status' => RecipeImport::LAYOUT_PENDING, 'title' => 'Salade']);
        $this->actingAs($this->user)->get('/tickets/'.$receipt->id.'/ia')->assertSee('L\'IA est déjà occupée avec la fiche Paperless n° 490', false);
        $this->post('/tickets/'.$receipt->id.'/ia')->assertSessionHasErrors('ai');
        $this->assertSame(Receipt::VISION_TO_SEND, $receipt->fresh()->vision_status);

        // Puis l'inverse : un ticket en file bloque l'envoi d'une fiche
        $import->forceFill(['layout_status' => RecipeImport::LAYOUT_TO_SEND])->save();
        $this->post('/tickets/'.$receipt->id.'/ia')->assertRedirect('/tickets');
        $this->assertTrue($receipt->fresh()->isReading());
        $this->post('/recettes/importees/'.$import->id.'/ia', ['pages' => [0]])->assertSessionHasErrors('pages');
        $this->get('/tickets')->assertSee('Annuler l\'envoi', false)->assertSee('read-progress-url', false);
        $this->getJson('/tickets/avancement')->assertOk()->assertJsonPath('reading.0.id', $receipt->id);

        // Annulé avant le début de l'analyse : rien n'est parti
        $this->post('/tickets/'.$receipt->id.'/ia/annuler')->assertRedirect('/tickets');
        $this->assertSame(Receipt::VISION_TO_SEND, $receipt->fresh()->vision_status);
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), self::VISION));
    }

    public function test_pc_eteint_erreur_affichee_sans_nouvel_essai(): void
    {
        $this->fake('473', pcOff: true);
        (new ReceiptSync)->run($this->household->fresh());
        $this->actingAs($this->user)->post('/tickets/'.$this->receipt()->id.'/ia');

        $this->assertSame(1, (new ReceiptVisionRunner)->processPending()['failed']);
        $receipt = $this->receipt();
        $this->assertSame(Receipt::VISION_FAILED, $receipt->vision_status);
        $this->assertStringContainsString('PC éteint ou Ollama arrêté', $receipt->vision_error);
        $this->assertSame(0, (new ReceiptVisionRunner)->processPending()['read'] + (new ReceiptVisionRunner)->processPending()['failed'], 'Aucun nouvel essai automatique');

        $this->get('/tickets')->assertSee('échec de l\'analyse', false)->assertSee('Renvoyer à l\'IA');
        $this->get('/tickets/'.$receipt->id.'/ia')->assertOk()->assertSee('Dernier envoi');
    }

    public function test_relecture_sans_renvoyer_a_l_ia(): void
    {
        $receipt = $this->readByAi();
        $calls = fn () => collect(Http::recorded())->filter(fn ($pair) => str_starts_with($pair[0]->url(), self::VISION))->count();
        $this->assertSame(1, $calls());

        $this->actingAs($this->user)->post('/tickets/'.$receipt->id.'/relire')->assertSessionHas('status', fn ($s) => str_contains($s, 'rien n\'a été renvoyé'));
        $this->assertSame(988, $receipt->fresh()->linesTotalCents());
        $this->assertSame(1, $calls(), 'Une seule lecture par l\'IA');
    }

    public function test_vider_tous_les_tickets(): void
    {
        $receipt = $this->readByAi();
        $lidl = Store::firstWhere('slug', 'lidl');
        $pack = IngredientPack::first();
        Price::create(['ingredient_pack_id' => $pack->id, 'store_id' => $lidl->id, 'price_cents' => 199, 'source' => 'ticket', 'observed_on' => '2026-09-19']);
        Price::create(['ingredient_pack_id' => $pack->id, 'store_id' => $lidl->id, 'price_cents' => 249, 'source' => 'manuel', 'observed_on' => '2026-09-20']);
        ReceiptAlias::create(['store_id' => $lidl->id, 'normalized_label' => 'BANANE VRAC', 'ingredient_pack_id' => $pack->id, 'hits' => 3]);
        $manual = Price::where('source', 'manuel')->count();

        $this->assertSame(['Tickets' => 1, 'Lignes de tickets' => $receipt->lines->count(), 'Prix relevés sur les tickets' => 1, 'Libellés de tickets mémorisés' => 1], ReceiptWipe::counts());

        $this->artisan('foodtruck:vider-tickets', ['--apercu' => true])->expectsOutputToContain('Tickets')->assertSuccessful();
        $this->assertSame(1, Receipt::count());

        $this->artisan('foodtruck:vider-tickets')->expectsQuestion('Pour confirmer, tape EFFACER', 'non')->assertFailed();
        $this->assertSame(1, Receipt::count());

        // Effacé puis relu depuis Paperless : le ticket revient « à envoyer à l'IA », rien n'est envoyé
        $before = collect(Http::recorded())->filter(fn ($pair) => str_starts_with($pair[0]->url(), self::VISION))->count();
        $this->artisan('foodtruck:vider-tickets', ['--oui' => true])->expectsOutputToContain('Tous les tickets sont supprimés')
            ->expectsOutputToContain('à envoyer à l\'IA')->assertSuccessful();
        $this->assertSame([0, 0, $manual], [ReceiptLine::count(), ReceiptAlias::count(), Price::where('source', 'manuel')->count()]);
        $this->assertSame(0, Price::where('source', 'ticket')->count());
        $this->assertSame(1, Receipt::count());
        $this->assertSame(Receipt::VISION_TO_SEND, $this->receipt()->vision_status);
        $this->assertSame($before, collect(Http::recorded())->filter(fn ($pair) => str_starts_with($pair[0]->url(), self::VISION))->count());
    }
}
