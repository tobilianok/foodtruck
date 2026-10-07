<?php

namespace Tests\Feature;

use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\Price;
use App\Models\Receipt;
use App\Models\ReceiptLine;
use App\Models\User;
use App\Support\Receipts\ReceiptParser;
use App\Support\Receipts\ReceiptProcessor;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\PaperlessOcrReceiptTest;

/**
 * v0.5.2 : tickets réels venus de Paperless (Lidl par OCR, Leclerc Drive), création d'ingrédient
 * depuis une ligne, ticket qui reste à valider, relecture sans doublon de prix.
 */
class PaperlessReceiptFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        ReferenceImporter::import();
        $this->user = $this->householdUser();
    }

    private function ingest(string $fixture, string $correspondent): Receipt
    {
        $receipt = Receipt::create([
            'household_id' => $this->user->household_id, 'source' => 'paperless', 'correspondent' => $correspondent,
            'raw_text' => PaperlessOcrReceiptTest::fixture($fixture),
        ]);

        return (new ReceiptProcessor)->ingest($receipt)->load('lines.pack.ingredient', 'lines.ingredient');
    }

    private function line(Receipt $receipt, string $label): ReceiptLine
    {
        return $receipt->lines->firstWhere('raw_label', $label) ?? $this->fail("Ligne « {$label} » absente");
    }

    private function lineStatus(ReceiptLine $line): string
    {
        return $line->status.' '.($line->pack ? $line->pack->ingredient->slug.'/'.$line->pack->label : ($line->ingredient?->slug ?? '-'));
    }

    private function submit(Receipt $receipt, array $lines)
    {
        return $this->actingAs($this->user)->put("/tickets/{$receipt->id}", [
            'store_id' => $receipt->store_id,
            'purchased_on' => $receipt->purchased_on->toDateString(),
            'lines' => $lines,
        ]);
    }

    public function test_ticket_lidl_ocr_de_paperless(): void
    {
        $receipt = $this->ingest('lidl-2025-10-02', 'Lidl');

        $this->assertSame([10909, 32, null], [$receipt->total_cents, $receipt->expected_lines, $receipt->unread_lines]);
        $this->assertSame(10909, $receipt->linesTotalCents());
        $this->assertSame(Receipt::STATUS_TO_REVIEW, $receipt->status);

        foreach (['LPM calendula&coco', 'Sanytol désin. linge', 'Bordeaux Blanc AOP', 'Monbazillac AOP'] as $label) {
            $this->assertSame(ReceiptLine::STATUS_IGNORED, $this->line($receipt, $label)->status, $label);
        }

        $navet = $this->line($receipt, 'Navet');
        $this->assertSame([ReceiptLine::STATUS_IGNORED, ReceiptParser::NOTE_WEIGHT], [$navet->status, $navet->note]);
        $this->assertTrue($navet->hasUnknownWeight());
        $this->assertSame(ReceiptParser::NOTE_DEDUCED, $this->line($receipt, 'Pâté de campagne')->note);

        $page = $this->actingAs($this->user)->get("/tickets/{$receipt->id}")->assertOk();
        $page->assertSee('32 articles lus')->assertSee('sur 32 annoncés')->assertSee('poids illisible')->assertSee('montant déduit du total')
            ->assertSee('Vérifier la lecture')->assertSee("Choisir ou créer l'ingrédient")->assertSee('receipt-fix', false)->assertDontSee('Écart de');

        // Pesée illisible associée quand même : aucun prix enregistré, la ligne repasse en ignorée
        $this->submit($receipt, [$navet->id => ['choice' => 'Navet', 'action' => 'associer']])->assertSessionHasNoErrors();
        $this->assertSame(ReceiptLine::STATUS_IGNORED, $navet->fresh()->status);
        $this->assertNull($navet->fresh()->price_id);
    }

    public function test_bon_de_commande_leclerc_drive(): void
    {
        $receipt = $this->ingest('leclerc-drive-2026-09-28', 'E.Leclerc');

        $this->assertSame('leclerc-drive', $receipt->store->slug);
        $this->assertSame(['2026-09-28', 8977, 8977], [$receipt->purchased_on->toDateString(), $receipt->total_cents, $receipt->linesTotalCents()]);

        $this->assertSame('ignore -', $this->lineStatus($this->line($receipt, 'Mouchoirs Blancs 4 plis Caresse - 15 Etuis Pocket x9')), 'Rubrique hygiène');
        $this->assertSame('propose lait-entier/Bouteille 1 L', $this->lineStatus($this->line($receipt, 'Lait entier Délisse - UHT - Brique : 1L')));
        $this->assertSame('propose oeuf', $this->lineStatus($this->line($receipt, 'Oeufs Marque rèpere - De Nos Régions - x20')), '20 œufs : conditionnement créé à la validation');

        // Produits transformés : pas de faux rapprochement (compote ≠ pomme ni poireau, croûtons ≠ ail…)
        $this->assertSame('a_associer -', $this->lineStatus($this->line($receipt, 'Compote Andros - Pomme Poire - 8x100g')));
        $this->assertSame('a_associer -', $this->lineStatus($this->line($receipt, 'Compote Andros - Pomme Fraise - 8x100g')));
        $this->assertSame('a_associer -', $this->lineStatus($this->line($receipt, 'Salade Menu Fraîcheur - Concombre au fromage blanc')));
        $this->assertSame('a_associer -', $this->lineStatus($this->line($receipt, 'Pâte brisée Tablier Blanc - Prête à dérouler - 230g')));

        // Poivron à la pièce : jamais le prix du vrac au kilo
        $poivron = $this->line($receipt, 'Poivron doux rouge - 1p');
        $this->assertSame('propose poivron', $this->lineStatus($poivron));

        $oeufs = $this->line($receipt, 'Oeufs Marque rèpere - De Nos Régions - x20');
        $this->submit($receipt, [
            $poivron->id => ['choice' => 'Poivron', 'action' => 'associer'],
            $oeufs->id => ['choice' => 'Œuf', 'action' => 'associer'],
        ])->assertSessionHasNoErrors();

        $poivron->refresh()->load('pack');
        $this->assertSame(['Pièce (ticket)', false, 99], [$poivron->pack->label, $poivron->pack->is_bulk, $poivron->pack_price_cents]);
        $this->assertSame('20 pièces (ticket)', $oeufs->fresh()->pack->label);
        $this->assertSame(428, $oeufs->fresh()->pack_price_cents);

        // Compote avec remise de lot : prix promo
        $compote = $this->line($receipt, 'Compote Andros - Pomme Poire - 8x100g');
        $this->assertSame(79, $compote->discount_cents);
    }

    public function test_creer_un_ingredient_depuis_une_ligne(): void
    {
        $receipt = $this->ingest('leclerc-drive-2026-09-28', 'E.Leclerc');
        $suisses = $this->line($receipt, 'Petits Suisses Gervais - 9,5%MG - 12x60g');
        $compote = $this->line($receipt, 'Compote Andros - Pomme Poire - 8x100g');
        $cremerie = Aisle::firstWhere('slug', 'cremerie');

        $this->assertSame('cremerie', \App\Http\Controllers\ReceiptController::guessAisle($suisses), 'Rayon déduit de la rubrique « Laitier Oeufs Vegetal »');

        $this->submit($receipt, [
            $suisses->id => ['choice' => 'Petit-suisse nature', 'action' => 'creer', 'aisle_id' => $cremerie->id],
            $compote->id => ['choice' => 'Compote pomme poire', 'action' => 'creer'],
        ])->assertSessionHasNoErrors()->assertSessionHas('status', fn ($s) => str_contains($s, 'Petit-suisse nature') && str_contains($s, 'Compote pomme poire'));

        $ingredient = Ingredient::with('packs')->firstWhere('slug', 'petit-suisse-nature');
        $this->assertSame(['g', 'cremerie', $this->user->id, true], [$ingredient->base_unit, $ingredient->aisle->slug, $ingredient->created_by, $ingredient->is_fresh]);
        $this->assertSame(['720 g (ticket)', 720.0], [$ingredient->packs->first()->label, (float) $ingredient->packs->first()->quantity]);
        $this->assertSame(ReceiptLine::STATUS_APPLIED, $suisses->fresh()->status);

        $price = Price::where('ingredient_pack_id', $ingredient->packs->first()->id)->first();
        $this->assertSame([244, 'ticket', false], [$price->price_cents, $price->source, $price->is_promo]);

        $compoteIngredient = Ingredient::with('packs')->firstWhere('slug', 'compote-pomme-poire');
        // Rayon déduit de la rubrique Leclerc « Laitier » où sont rangées les compotes
        $this->assertSame(['cremerie', '800 g (ticket)'], [$compoteIngredient->aisle->slug, $compoteIngredient->packs->first()->label]);
        $this->assertTrue(Price::where('ingredient_pack_id', $compoteIngredient->packs->first()->id)->first()->is_promo, 'Remise de lot → promo');

        // Libellé mémorisé : le prochain bon de commande le reconnaît
        $next = $this->ingest('leclerc-drive-2026-09-28', 'E.Leclerc');
        $this->assertSame('reconnu petit-suisse-nature/720 g (ticket)', $this->lineStatus($this->line($next, 'Petits Suisses Gervais - 9,5%MG - 12x60g')));

        // Nom déjà pris : l'ingrédient existant est utilisé, rien n'est dupliqué
        $count = Ingredient::count();
        $this->submit($next, [$this->line($next, 'Riz Risotto Comptoir du Grain - 500g')->id => ['choice' => 'Riz long', 'action' => 'creer']])->assertSessionHasNoErrors();
        $this->assertSame($count, Ingredient::count());
    }

    public function test_association_incomprise_le_ticket_reste_a_valider(): void
    {
        $receipt = $this->ingest('lidl-2026-09-24', 'Lidl');

        // Tout est traité sauf une ligne où l'on tape un nom qui n'existe pas
        $lines = [];
        foreach ($receipt->lines as $line) {
            $lines[$line->id] = ['choice' => '', 'action' => 'ignorer'];
        }
        $boisson = $this->line($receipt, 'Boisson Trop Zéro');
        $lines[$boisson->id] = ['choice' => 'Boisson tropicale zéro', 'action' => 'associer'];

        $this->submit($receipt, $lines)->assertSessionHasErrors('lines');
        $this->assertSame(ReceiptLine::STATUS_UNKNOWN, $boisson->fresh()->status);
        $this->assertSame(Receipt::STATUS_TO_REVIEW, $receipt->fresh()->status, 'Pas « traité » tant qu\'une ligne reste à associer');

        $this->actingAs($this->user)->get("/tickets/{$receipt->id}")->assertSee("Choisir ou créer l'ingrédient");
    }

    public function test_relire_un_ticket_traite_sans_doubler_les_prix(): void
    {
        $receipt = $this->ingest('lidl-2026-09-24', 'Lidl');

        $lines = [];
        foreach ($receipt->lines as $line) {
            $lines[$line->id] = $line->pack || $line->ingredient
                ? ['choice' => $line->pack ? \App\Http\Controllers\ReceiptController::packChoiceLabel($line->pack) : $line->ingredient->name, 'action' => 'associer']
                : ['choice' => '', 'action' => 'ignorer_toujours'];
        }
        $this->submit($receipt, $lines)->assertSessionHasNoErrors();
        $this->assertSame(Receipt::STATUS_DONE, $receipt->fresh()->status);
        $prices = Price::where('source', 'ticket')->count();
        $this->assertGreaterThan(5, $prices);

        // Relecture (bouton ou foodtruck:reparse --tout) d'un ticket déjà validé : tout est reconnu, il reste traité,
        // prix mis à jour sans doublon
        $this->actingAs($this->user)->post("/tickets/{$receipt->id}/relire")->assertSessionHas('status');
        $this->assertSame(Receipt::STATUS_DONE, $receipt->fresh()->status);
        $this->assertSame($prices, Price::where('source', 'ticket')->count());

        $this->artisan('foodtruck:reparse', ['--tout' => true])->expectsOutputToContain('1 entièrement reconnu')->assertSuccessful();
        $this->assertSame($prices, Price::where('source', 'ticket')->count());
    }
}
