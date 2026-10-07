<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Ingredient;
use App\Models\IngredientPack;
use App\Models\Price;
use App\Models\Receipt;
use App\Models\ReceiptAlias;
use App\Models\ReceiptLine;
use App\Models\Store;
use App\Models\User;
use App\Support\Receipts\ReceiptMatcher;
use App\Support\Receipts\ReceiptProcessor;
use App\Support\Receipts\ReceiptSync;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use RefreshDatabase;

    private const PAPERLESS = 'http://paperless.test:8000';

    private User $louis;

    private Store $leclerc;

    private const TICKET = <<<'TXT'
        E.LECLERC VICHY
        28/09/2026 18:42
        LAIT 1/2 ECR UHT MDD 1L          1,05 €  A
        LAIT 1/2 ECR UHT MDD 6X1L        5,94 €  A
        BEURRE DOUX 250G                 2,69 €  A
        CAROTTES VRAC
           0,856 kg x 1,69 EUR/kg         1,45 €  A
        OEUFS PLEIN AIR X12              3,99 €  A
        REMISE IMMEDIATE                -0,50 €
        SAC CABAS                        0,10 €  B
        TOTAL                            14,72 €
        TXT;

    protected function setUp(): void
    {
        parent::setUp();
        ReferenceImporter::import();
        $this->louis = $this->householdUser();
        $this->leclerc = Store::firstWhere('slug', 'leclerc-drive');
    }

    private function pack(string $ingredient, ?string $label = null): IngredientPack
    {
        return IngredientPack::whereHas('ingredient', fn ($q) => $q->where('slug', $ingredient))
            ->when($label, fn ($q) => $q->where('label', $label))->firstOrFail();
    }

    public function test_rapprochement_par_ressemblance(): void
    {
        $matcher = new ReceiptMatcher;

        $this->assertSame('lait-demi-ecreme', $matcher->bestIngredient('LAIT 1/2 ECR UHT MDD 1L')->slug);
        $this->assertSame('carotte', $matcher->bestIngredient('CAROTTES VRAC')->slug);
        $this->assertSame('beurre-doux', $matcher->bestIngredient('BEURRE DOUX 250G')->slug);
        $this->assertSame('oeuf', $matcher->bestIngredient('OEUFS PLEIN AIR X12')->slug);
        $this->assertNull($matcher->bestIngredient('SAC CABAS'));

        $lait = Ingredient::with('packs')->firstWhere('slug', 'lait-demi-ecreme');
        $this->assertSame('Pack 6 × 1 L', $matcher->choosePack($lait, 'LAIT 1/2 ECR 6X1L', false)->label);
        $this->assertSame('Bouteille 1 L', $matcher->choosePack($lait, 'LAIT 1/2 ECR 1L', false)->label);
        $oeuf = Ingredient::with('packs')->firstWhere('slug', 'oeuf');
        $this->assertSame('Boîte de 12', $matcher->choosePack($oeuf, 'OEUFS PLEIN AIR X12', false)->label);
    }

    public function test_saisie_manuelle_rapprochement_et_prix(): void
    {
        $this->actingAs($this->louis)->post('/tickets', [
            'store_id' => $this->leclerc->id,
            'purchased_on' => '2026-09-28',
            'raw_text' => self::TICKET,
        ])->assertRedirect();

        $receipt = Receipt::with('lines')->first();
        $this->assertSame('a_valider', $receipt->status);
        $this->assertSame(1472, $receipt->total_cents);
        $this->assertSame(1472, $receipt->linesTotalCents());

        $lines = $receipt->lines->keyBy('raw_label');
        $this->assertSame('propose', $lines['CAROTTES VRAC']->status);
        $this->assertSame('a_associer', $lines['SAC CABAS']->status);

        $this->get("/tickets/{$receipt->id}")->assertOk()->assertSee('LAIT 1/2 ECR UHT MDD 6X1L')->assertSee('Lait demi-écrémé — Pack 6 × 1 L', false);

        // Validation : propositions acceptées telles quelles, sac ignoré définitivement
        $payload = ['store_id' => $this->leclerc->id, 'purchased_on' => '2026-09-28', 'remember' => '1', 'lines' => []];
        foreach ($receipt->lines as $line) {
            $payload['lines'][$line->id] = $line->raw_label === 'SAC CABAS'
                ? ['action' => 'ignorer_toujours', 'choice' => '']
                : ['action' => 'associer', 'choice' => $line->pack ? $line->pack->ingredient->name.' — '.$line->pack->label : ''];
        }
        $this->put("/tickets/{$receipt->id}", $payload)->assertRedirect("/tickets/{$receipt->id}");

        $receipt->refresh();
        $this->assertSame('traite', $receipt->status);

        $prices = Price::where('source', 'ticket')->get();
        $this->assertCount(5, $prices);
        $this->assertTrue($prices->every(fn ($p) => $p->observed_on->toDateString() === '2026-09-28' && $p->store_id === $this->leclerc->id));

        // Pack 6 × 1 L à 5,94 € ; carottes 1,69 €/kg → vrac au kg ; œufs 3,99 - 0,50 de remise = 3,49 € en promo
        $this->assertSame(594, $this->pack('lait-demi-ecreme', 'Pack 6 × 1 L')->prices()->where('source', 'ticket')->value('price_cents'));
        $this->assertSame(169, $this->pack('carotte')->prices()->where('source', 'ticket')->value('price_cents'));
        $oeufs = $this->pack('oeuf', 'Boîte de 12')->prices()->where('source', 'ticket')->first();
        $this->assertSame([349, true], [$oeufs->price_cents, $oeufs->is_promo]);

        // Le prix du ticket devient le prix courant
        $this->assertSame(105, $this->pack('lait-demi-ecreme', 'Bouteille 1 L')->fresh('prices')->currentPriceFor($this->leclerc->id)->price_cents);

        $this->assertSame(6, ReceiptAlias::where('store_id', $this->leclerc->id)->count());
        $this->assertTrue(ReceiptAlias::firstWhere('normalized_label', 'SAC CABAS')->is_ignored);
    }

    /** v0.19.0 : les libellés mémorisés sont reconnus, mais le ticket attend toujours la validation de Louis. */
    public function test_le_ticket_suivant_est_reconnu_et_valide_en_un_clic(): void
    {
        $processor = new ReceiptProcessor;
        $first = Receipt::create(['household_id' => $this->louis->household_id, 'source' => 'manuel', 'store_id' => $this->leclerc->id, 'purchased_on' => '2026-09-21', 'raw_text' => self::TICKET]);
        $processor->ingest($first);
        foreach ($first->lines as $line) {
            $line->raw_label === 'SAC CABAS'
                ? $processor->remember($this->leclerc->id, $line->normalized_label, null, true, $this->louis)
                : $line->forceFill(['status' => ReceiptLine::STATUS_SUGGESTED])->save();
        }
        $processor->apply($first->fresh(), $this->louis);

        $second = Receipt::create([
            'household_id' => $this->louis->household_id, 'source' => 'manuel', 'store_id' => $this->leclerc->id, 'purchased_on' => '2026-09-28',
            'raw_text' => str_replace(['1,05 €', '14,72 €'], ['1,09 €', '14,76 €'], self::TICKET),
        ]);
        $processor->ingest($second);

        $second->refresh();
        $this->assertSame('a_valider', $second->status);
        $this->assertFalse($second->auto_applied);
        $this->assertTrue($processor->isFullyKnown($second->load('lines')));
        $this->assertSame(105, $this->pack('lait-demi-ecreme', 'Bouteille 1 L')->fresh('prices')->currentPriceFor($this->leclerc->id)->price_cents, 'Rien n\'est enregistré avant la validation');

        $this->actingAs($this->louis)->get("/tickets/{$second->id}")->assertOk()->assertSee('Tout est vérifié');
        $lines = [];
        foreach ($second->lines as $line) {
            $lines[$line->id] = $line->pack ? ['choice' => \App\Http\Controllers\ReceiptController::packChoiceLabel($line->pack), 'action' => 'associer'] : ['action' => 'ignorer'];
        }
        $this->put("/tickets/{$second->id}", ['store_id' => $this->leclerc->id, 'purchased_on' => '2026-09-28', 'lines' => $lines])->assertSessionHasNoErrors();
        $this->assertSame('traite', $second->fresh()->status);
        $this->assertSame(109, $this->pack('lait-demi-ecreme', 'Bouteille 1 L')->fresh('prices')->currentPriceFor($this->leclerc->id)->price_cents);
    }

    public function test_association_par_nom_d_ingredient_et_creation_de_conditionnement(): void
    {
        $receipt = Receipt::create([
            'household_id' => $this->louis->household_id, 'source' => 'manuel', 'store_id' => $this->leclerc->id, 'purchased_on' => '2026-09-28',
            'raw_text' => "CREME FLEURETTE 30% 50CL   2,10\nTOTAL 2,10",
        ]);
        (new ReceiptProcessor)->ingest($receipt);
        $line = $receipt->lines()->first();

        $this->actingAs($this->louis)->put("/tickets/{$receipt->id}", [
            'store_id' => $this->leclerc->id, 'purchased_on' => '2026-09-28',
            'lines' => [$line->id => ['action' => 'associer', 'choice' => 'crème liquide entière']],
        ])->assertRedirect();

        // Aucun conditionnement de 50 cl : il est créé depuis le ticket
        $pack = IngredientPack::where('label', '50 cl (ticket)')->firstOrFail();
        $this->assertSame(500.0, $pack->quantity);
        $this->assertSame(210, $pack->prices()->value('price_cents'));

        // Choix incompréhensible : erreur explicite, rien d'enregistré
        $other = Receipt::create(['household_id' => $this->louis->household_id, 'source' => 'manuel', 'store_id' => $this->leclerc->id, 'purchased_on' => '2026-09-28', 'raw_text' => "TRUC BIZARRE 1,00\nTOTAL 1,00"]);
        (new ReceiptProcessor)->ingest($other);
        $this->put("/tickets/{$other->id}", [
            'store_id' => $this->leclerc->id, 'purchased_on' => '2026-09-28',
            'lines' => [$other->lines()->first()->id => ['action' => 'associer', 'choice' => 'Truffe blanche']],
        ])->assertSessionHasErrors('lines');
        $this->assertSame('a_valider', $other->fresh()->status);
    }

    public function test_synchronisation_paperless(): void
    {
        $household = $this->louis->household;
        $household->forceFill(['paperless_url' => self::PAPERLESS, 'paperless_token' => 'jeton-secret', 'paperless_tag' => 'courses alimentaires'])->save();
        $this->assertNotSame('jeton-secret', \DB::table('households')->where('id', $household->id)->value('paperless_token'), 'Le jeton est chiffré en base');

        Http::fake([
            self::PAPERLESS.'/api/tags/*' => Http::response(['count' => 1, 'results' => [['id' => 7, 'name' => 'courses alimentaires']]]),
            self::PAPERLESS.'/api/correspondents/*' => Http::response(['results' => [['id' => 3, 'name' => 'E.Leclerc Drive Vichy']]]),
            self::PAPERLESS.'/api/documents/*' => Http::response(['count' => 1, 'next' => null, 'results' => [[
                'id' => 42, 'title' => 'Ticket Leclerc', 'correspondent' => 3, 'created' => '2026-09-28',
                'modified' => '2026-09-28T19:00:00+02:00', 'content' => self::TICKET,
            ]]]),
        ]);

        $counts = (new ReceiptSync)->run($household);
        $this->assertSame(['new' => 1, 'updated' => 0, 'auto' => 0, 'to_send' => 0, 'visible' => 1, 'tag' => 'courses alimentaires', 'error' => null], $counts);

        // v0.21.0 : rien de nouveau → le résumé dit combien de documents le compte voit et où donner le droit
        $again = (new ReceiptSync)->run($household);
        $this->assertSame(0, $again['new']);
        $this->assertStringContainsString('1 document visible avec l\'étiquette « courses alimentaires »', ReceiptSync::summary($again));
        $this->assertStringContainsString('ajoute l\'utilisateur foodtruck dans « Afficher »', ReceiptSync::summary($again));

        $receipt = Receipt::firstWhere('paperless_document_id', 42);
        $this->assertSame($this->leclerc->id, $receipt->store_id, 'Magasin déduit du correspondant');
        $this->assertSame('2026-09-28', $receipt->purchased_on->toDateString());
        $this->assertCount(6, $receipt->lines);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Token jeton-secret'));

        // Deuxième passage : rien de nouveau
        $this->assertSame(0, (new ReceiptSync)->run($household->fresh())['new']);

        $this->actingAs($this->louis)->get('/tickets')->assertOk()->assertSee('Leclerc Drive')->assertSee('Synchroniser Paperless');
    }

    public function test_erreurs_paperless(): void
    {
        $household = $this->louis->household;
        $household->forceFill(['paperless_url' => self::PAPERLESS, 'paperless_token' => 'mauvais'])->save();

        Http::fake([self::PAPERLESS.'/*' => Http::response(['detail' => 'Invalid token'], 401)]);

        $counts = (new ReceiptSync)->run($household);
        $this->assertStringContainsString('refuse le jeton', $counts['error']);
        $this->assertStringContainsString('refuse le jeton', $household->fresh()->paperless_last_error);

        // Droit manquant : le message dit lequel
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([self::PAPERLESS.'/api/tags/*' => Http::response(['detail' => 'Forbidden'], 403)]);
        $counts = (new ReceiptSync)->run($household->fresh());
        $this->assertStringContainsString('afficher les étiquettes', $counts['error']);
    }

    public function test_reglages_paperless_reserves_aux_admins(): void
    {
        Http::fake([
            self::PAPERLESS.'/api/tags/*' => Http::response(['count' => 1, 'results' => [['id' => 7, 'name' => 'courses alimentaires']]]),
            self::PAPERLESS.'/api/documents/*' => Http::response(['count' => 12, 'results' => []]),
        ]);

        $member = $this->householdUser(User::HOUSEHOLD_MEMBER, $this->louis->household);
        $this->actingAs($member)->put('/foyer/paperless', ['paperless_url' => self::PAPERLESS, 'paperless_token' => 'x'])->assertForbidden();

        $token = 'deda9b44c51e72feccfb990422957a7485373625';

        // Mot de passe rempli par un gestionnaire de mots de passe : refusé avant tout appel
        $this->actingAs($this->louis)->put('/foyer/paperless', [
            'paperless_url' => self::PAPERLESS, 'paperless_token' => 'MonMotDePasse!2026', 'paperless_tag' => '',
        ])->assertSessionHasErrors('paperless_token');
        $this->assertNull($this->louis->household->fresh()->paperless_token);

        // Jeton collé avec « Token » devant et un retour à la ligne : nettoyé
        $this->put('/foyer/paperless', [
            'paperless_url' => self::PAPERLESS.'/', 'paperless_token' => "Token {$token}\n", 'paperless_tag' => '',
        ])->assertRedirect()->assertSessionHas('status');

        $household = $this->louis->household->fresh();
        $this->assertSame(self::PAPERLESS, $household->paperless_url);
        $this->assertSame($token, $household->paperless_token);
        $this->assertSame('courses alimentaires', $household->paperlessTag());

        // Jeton laissé vide : l'ancien est conservé
        $this->put('/foyer/paperless', ['paperless_url' => self::PAPERLESS, 'paperless_token' => '', 'paperless_tag' => 'courses alimentaires'])->assertSessionHasNoErrors();
        $this->assertSame($token, $household->fresh()->paperless_token);

        // Le jeton n'est jamais réaffiché : seule sa fin l'est, et le champ n'est pas un mot de passe
        $this->get('/foyer')->assertOk()->assertDontSee($token)->assertSee('…373625', false)->assertDontSee('type="password"', false);
    }

    public function test_tickets_d_un_autre_foyer_invisibles(): void
    {
        $other = $this->householdUser(User::HOUSEHOLD_ADMIN, Household::create(['name' => 'Voisins']));
        $receipt = Receipt::create(['household_id' => $this->louis->household_id, 'source' => 'manuel', 'store_id' => $this->leclerc->id, 'purchased_on' => '2026-09-28', 'raw_text' => self::TICKET]);

        $this->actingAs($other)->get("/tickets/{$receipt->id}")->assertNotFound();
        $this->actingAs($other)->get('/tickets')->assertOk()->assertDontSee('Leclerc Drive</strong>', false);
    }
}
