<?php

namespace Tests\Feature;

use App\Models\IngredientPack;
use App\Models\Receipt;
use App\Models\ReceiptLine;
use App\Models\Store;
use App\Support\Receipts\ReceiptProcessor;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\LidlReceiptTest;

class LidlReceiptFlowTest extends TestCase
{
    use RefreshDatabase;

    private function ingest(string $text, string $correspondent = 'Lidl'): Receipt
    {
        $user = $this->householdUser();
        $receipt = Receipt::create([
            'household_id' => $user->household_id, 'source' => 'paperless', 'correspondent' => $correspondent, 'raw_text' => $text,
        ]);

        return (new ReceiptProcessor)->ingest($receipt)->load('lines.pack.ingredient', 'lines.ingredient');
    }

    private function statuses(Receipt $receipt): array
    {
        return $receipt->lines->mapWithKeys(fn (ReceiptLine $l) => [
            $l->raw_label => $l->status.' '.($l->pack ? $l->pack->ingredient->slug.'/'.$l->pack->label : ($l->ingredient?->slug ?? '-')),
        ])->all();
    }

    public function test_rapprochement_du_grand_ticket_lidl(): void
    {
        ReferenceImporter::import();
        $receipt = $this->ingest(LidlReceiptTest::CHARMEIL_02_10_25);

        $this->assertSame(Store::firstWhere('slug', 'lidl')->id, $receipt->store_id);
        $this->assertSame('2025-10-02', $receipt->purchased_on->toDateString());
        $this->assertSame(10909, $receipt->total_cents);

        $s = $this->statuses($receipt);

        // Reconnus par ressemblance
        $this->assertSame('propose potimarron/Vrac au kg', $s['Potimarron vrac']);
        $this->assertSame('propose cuisse-de-poulet/Barquette 1 kg', $s['Hauts de cuisse poul']);
        $this->assertSame('propose beurre-doux/Plaquette 250 g', $s['Beurre doux extra fi']);
        $this->assertSame('propose chevre-buche/Bûche 180 g', $s['Chèvre bûchette 45%']);
        $this->assertSame('propose saucisse-de-toulouse/Barquette 6 (600 g)', $s['Saucisse de Toulouse']);
        $this->assertSame('propose lardons-fumes/2 × 100 g', $s['Lardons fumés']);
        $this->assertSame('propose pomme-de-terre/Vrac au kg', $s['Pomme de terre 1 kg']);
        $this->assertSame('propose tomates-pelees-en-conserve/Boîte 400 g', $s['Tomates pelées']);
        $this->assertStringStartsWith('propose huile-dolive/', $s['Huile d\'olive vierge']);
        $this->assertSame('propose levure-chimique/Boîte de 6 sachets', $s['Levure chimique']);
        $this->assertSame('propose sucre-vanille/Boîte de 10 sachets', $s['Sucre vanillé']);
        $this->assertSame('propose navet/Vrac au kg', $s['Navet']);
        $this->assertSame('propose poivron/Vrac au kg', $s['Poivron rouge vrac']);
        $this->assertSame('propose carotte/Vrac au kg', $s['Carottes sachet 1 kg']);
        $this->assertSame('propose patate-douce/Vrac au kg', $s['Patate douce']);

        // TVA 20 % : ignorés d'office (entretien, hygiène, vin)
        foreach (['LPM calendula&coco', 'Sanytol désin. linge', 'Bordeaux Blanc AOP', 'Bordeaux élevé Fût', 'Monbazillac AOP'] as $label) {
            $this->assertSame('ignore -', $s[$label], $label);
        }

        // Pâté ≠ pâtes ; produits absents du référentiel : à associer
        $this->assertSame('a_associer -', $s['Pâté de campagne']);
        foreach (['Yoplait Pat Patrouil', 'Rösti', 'Filet mignon', 'Camembert caractère', 'Double rôti de porc', 'Bouquets garnis', 'Capsules Lungo UTZ'] as $label) {
            $this->assertSame('a_associer -', $s[$label], $label);
        }
    }

    public function test_quantite_absente_du_referentiel_et_prix(): void
    {
        ReferenceImporter::import();
        $receipt = $this->ingest("Oeufs x30 Cage Impor   4,94  1    4,94 A T\nA payer 4,94\n  A   5,5%   4,94  0,26  4,68\n 29.08.26  11:48:47");
        $line = $receipt->lines->first();

        $this->assertSame(ReceiptLine::STATUS_SUGGESTED, $line->status);
        $this->assertNull($line->pack);
        $this->assertSame('oeuf', $line->ingredient->slug);

        $user = $this->householdUser(household: $receipt->household);
        $this->actingAs($user)->get("/tickets/{$receipt->id}")->assertOk()->assertSee('value="Œuf"', false)->assertSee('le conditionnement sera créé');

        $this->put("/tickets/{$receipt->id}", [
            'store_id' => $receipt->store_id, 'purchased_on' => '2026-08-29',
            'lines' => [$line->id => ['action' => 'associer', 'choice' => 'Œuf']],
        ])->assertRedirect();

        $pack = IngredientPack::where('label', '30 pièces (ticket)')->firstOrFail();
        $this->assertSame(494, $pack->prices()->value('price_cents'));
        $this->assertSame('traite', $receipt->fresh()->status);
    }

    public function test_pesee_prix_au_kilo(): void
    {
        ReferenceImporter::import();
        $receipt = $this->ingest(LidlReceiptTest::CHARMEIL_24_09);
        $user = $this->householdUser(household: $receipt->household);

        $butternut = $receipt->lines->firstWhere('raw_label', 'Butternut vrac');
        $this->assertSame('courge-butternut', $butternut->pack?->ingredient->slug);

        (new ReceiptProcessor)->apply($receipt, $user);

        // 2,69 € - 0,92 € de réduction pour 1,5 kg → 1,18 €/kg, prix promo
        $price = $butternut->fresh('price')->price;
        $this->assertSame([118, true], [$price->price_cents, $price->is_promo]);
        $this->assertSame('2026-09-24', $price->observed_on->toDateString());
    }
}
