<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\IngredientPack;
use App\Models\Price;
use App\Models\Receipt;
use App\Models\ReceiptLine;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Store;
use App\Models\User;
use App\Support\ListReconciliation;
use App\Support\ReferenceImporter;
use App\Support\Receipts\ReceiptProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** v0.9.1 : rapprochement ticket ↔ liste de courses, bilan, prix qui bougent. */
class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $louis;

    private Store $leclerc;

    private ShoppingList $list;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 9, 29, 10, 0, 0, 'Europe/Paris'));
        ReferenceImporter::import();

        $this->leclerc = Store::firstWhere('slug', 'leclerc-drive');
        $this->louis = $this->householdUser();
        $this->louis->household->update(['main_store_id' => $this->leclerc->id, 'produce_store_id' => Store::firstWhere('slug', 'morin')->id]);
        $this->louis->household->members()->create(['name' => 'Tobilianok', 'category' => 'adulte', 'portion_coefficient' => 1.5, 'user_id' => $this->louis->id, 'position' => 10]);

        $recipe = Recipe::create(['title' => 'Galettes test', 'slug' => 'galettes-test', 'category' => 'plat', 'yield_quantity' => 4, 'yield_unit' => 'personnes', 'status' => Recipe::STATUS_PUBLISHED, 'author_id' => $this->louis->id]);
        foreach ([['oeuf', 3, 'piece'], ['lait-entier', 20, 'cl'], ['carotte', 2, 'piece']] as $i => [$slug, $q, $unit]) {
            $recipe->ingredients()->create(['position' => $i, 'ingredient_id' => $this->ing($slug)->id, 'quantity' => $q, 'unit' => $unit]);
        }

        $this->actingAs($this->louis)->post('/planning/repas', ['kind' => 'recette', 'recipe_id' => $recipe->id, 'date' => '2026-09-30', 'slot' => 'diner', 'parts' => 4]);
        $this->post('/courses', ['date_from' => '2026-09-29', 'date_to' => '2026-10-05']);
        $this->list = ShoppingList::latest('id')->first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ing(string $slug): Ingredient
    {
        return Ingredient::firstWhere('slug', $slug);
    }

    private function item(string $slug): ShoppingListItem
    {
        return $this->list->items()->where('ingredient_id', $this->ing($slug)->id)->firstOrFail();
    }

    private function pack(string $slug): IngredientPack
    {
        return IngredientPack::where('ingredient_id', $this->ing($slug)->id)->whereHas('prices', fn ($q) => $q->where('store_id', $this->leclerc->id))->orderBy('id')->firstOrFail();
    }

    /** Ticket Leclerc avec des lignes reconnues (slug, prix payé en centimes). */
    private function receipt(string $date, array $lines, ?int $storeId = null): Receipt
    {
        $receipt = Receipt::create(['household_id' => $this->louis->household_id, 'source' => 'manuel', 'store_id' => $storeId ?? $this->leclerc->id, 'purchased_on' => $date, 'total_cents' => array_sum(array_column($lines, 1)), 'raw_text' => 'x']);
        foreach ($lines as $i => [$slug, $cents]) {
            $receipt->lines()->create([
                'position' => ($i + 1) * 10, 'kind' => 'produit', 'raw_label' => strtoupper($slug), 'normalized_label' => $slug,
                'quantity' => 1, 'quantity_unit' => 'piece', 'unit_price_cents' => $cents, 'total_cents' => $cents,
                'status' => ReceiptLine::STATUS_KNOWN, 'ingredient_pack_id' => $this->pack($slug)->id,
            ]);
        }

        return $receipt;
    }

    public function test_ticket_traite_rattache_a_la_liste_et_coche_les_articles(): void
    {
        $revision = $this->list->revision;
        $receipt = $this->receipt('2026-09-30', [['lait-entier', 130], ['oeuf', 290], ['beurre-doux', 270]]);

        (new ReceiptProcessor)->apply($receipt, $this->louis);

        $this->assertSame($this->list->id, $receipt->fresh()->shopping_list_id);
        $this->assertTrue($this->item('lait-entier')->is_checked);
        $this->assertTrue($this->item('oeuf')->is_checked);
        $this->assertFalse($this->item('carotte')->is_checked, 'Pas sur le ticket : reste à acheter');
        $this->assertGreaterThan($revision, $this->list->fresh()->revision, 'Les autres téléphones voient le changement');
    }

    public function test_ticket_d_une_autre_periode_ou_d_un_autre_foyer_n_est_pas_rattache(): void
    {
        $far = $this->receipt('2026-08-01', [['lait-entier', 130]]);
        (new ReceiptProcessor)->apply($far, $this->louis);
        $this->assertNull($far->fresh()->shopping_list_id);

        $other = $this->householdUser();
        $foreign = Receipt::create(['household_id' => $other->household_id, 'source' => 'manuel', 'store_id' => $this->leclerc->id, 'purchased_on' => '2026-09-30', 'status' => 'traite', 'raw_text' => 'x']);
        $this->assertNull(ListReconciliation::candidate($foreign));
    }

    public function test_liste_classee_comparee_mais_pas_cochee(): void
    {
        $this->list->update(['archived_at' => now()]);
        $receipt = $this->receipt('2026-09-30', [['lait-entier', 130]]);
        (new ReceiptProcessor)->apply($receipt, $this->louis);

        $this->assertSame($this->list->id, $receipt->fresh()->shopping_list_id);
        $this->assertFalse($this->item('lait-entier')->is_checked);
    }

    public function test_comparaison_paye_estime(): void
    {
        $lait = $this->item('lait-entier');
        $receipt = $this->receipt('2026-09-30', [['lait-entier', $lait->estimated_cents + 40], ['oeuf', 290], ['beurre-doux', 270]]);
        (new ReceiptProcessor)->apply($receipt, $this->louis);

        $cmp = ListReconciliation::compare($this->list->fresh());

        $row = collect($cmp['rows'])->first(fn ($r) => $r['item']->id === $lait->id);
        $this->assertTrue($row['found']);
        $this->assertSame($lait->estimated_cents + 40, $row['paid_cents']);

        $carotte = collect($cmp['rows'])->first(fn ($r) => $r['item']->ingredient_id === $this->ing('carotte')->id);
        $this->assertFalse($carotte['found']);
        $this->assertSame(1, $cmp['missing']);

        $this->assertSame(['Beurre doux'], array_column($cmp['extras'], 'label'));
        $this->assertSame(270, $cmp['extras_cents']);
        $this->assertSame($lait->estimated_cents + 40 + 290 + 270, $cmp['paid_cents']);
        $this->assertSame(10000, $cmp['budget_cents']);
    }

    public function test_page_bilan(): void
    {
        $this->actingAs($this->louis)->get("/courses/liste/{$this->list->id}/bilan")->assertOk()
            ->assertSee('Aucun ticket rattaché');

        $receipt = $this->receipt('2026-09-30', [['lait-entier', 130], ['beurre-doux', 270]]);
        // Pas encore traité : proposé au rattachement manuel
        $receipt->update(['status' => 'traite']);
        $this->get("/courses/liste/{$this->list->id}/bilan")->assertSee('Rattacher à cette liste');
        $this->post("/tickets/{$receipt->id}/liste", ['list_id' => $this->list->id])->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, '1 article coché'));

        $this->get("/courses/liste/{$this->list->id}/bilan")->assertOk()
            ->assertSee('Bilan des courses du')->assertSee('Article par article')->assertSee('Payé (tickets)')
            ->assertSee('Achats hors liste')->assertSee('Beurre doux')->assertSee('pas retrouvé sur les tickets');

        // Détacher
        $this->post("/tickets/{$receipt->id}/liste", ['list_id' => ''])->assertRedirect();
        $this->assertNull($receipt->fresh()->shopping_list_id);

        // La fiche du ticket propose les listes
        $this->get("/tickets/{$receipt->id}")->assertOk()->assertSee('Liste de courses')->assertSee('Courses du');
    }

    public function test_bilan_d_un_autre_foyer_refuse(): void
    {
        $this->actingAs($this->householdUser())->get("/courses/liste/{$this->list->id}/bilan")->assertNotFound();
    }

    public function test_prix_qui_monte_et_estimation_recalee(): void
    {
        $oeuf = $this->pack('oeuf');
        $lait = $this->pack('lait-entier');

        // Un ticket précédent : œufs à 2,00 €
        Price::create(['ingredient_pack_id' => $oeuf->id, 'store_id' => $this->leclerc->id, 'price_cents' => 200, 'source' => 'ticket', 'observed_on' => '2026-09-15']);
        $estimatedMilk = Price::where('ingredient_pack_id', $lait->id)->where('store_id', $this->leclerc->id)->where('source', 'estimation')->value('price_cents');

        $receipt = $this->receipt('2026-09-30', [['oeuf', 260], ['lait-entier', $estimatedMilk + 60]]);
        (new ReceiptProcessor)->apply($receipt, $this->louis);

        $changes = ListReconciliation::priceChanges($this->list->fresh());

        $this->assertCount(1, $changes['hausses']);
        $this->assertSame([200, 260, 30], [$changes['hausses'][0]['from_cents'], $changes['hausses'][0]['to_cents'], $changes['hausses'][0]['percent']]);
        $this->assertSame('Lait entier', $changes['recalages'][0]['ingredient']);
        $this->assertSame($estimatedMilk, $changes['recalages'][0]['from_cents']);

        $this->actingAs($this->louis)->get("/courses/liste/{$this->list->id}/bilan")->assertSee('Prix qui montent')->assertSee('Estimations recalées');
    }

    public function test_petite_variation_et_promotion_ignorees(): void
    {
        $oeuf = $this->pack('oeuf');
        Price::create(['ingredient_pack_id' => $oeuf->id, 'store_id' => $this->leclerc->id, 'price_cents' => 200, 'source' => 'ticket', 'observed_on' => '2026-09-15']);

        $receipt = $this->receipt('2026-09-30', [['oeuf', 205]]);
        (new ReceiptProcessor)->apply($receipt, $this->louis);

        $this->assertSame([], ListReconciliation::priceChanges($this->list->fresh())['hausses']);
    }

    public function test_accueil_et_fin_des_courses(): void
    {
        $this->actingAs($this->louis)->get('/')->assertOk()->assertSee('Fais tes courses');

        $receipt = $this->receipt('2026-09-30', [['lait-entier', 130], ['oeuf', 290]]);
        (new ReceiptProcessor)->apply($receipt, $this->louis);

        $this->get('/')->assertSee('Dernier bilan')->assertSee('4,20');

        // « Courses terminées » mène au bilan, avec le stock rangé
        $this->post("/courses/liste/{$this->list->id}/terminer")->assertRedirect("/courses/liste/{$this->list->id}/bilan");
        $this->get("/courses/liste/{$this->list->id}/bilan")->assertOk()->assertSee('Payé (tickets)')->assertSee('Rangé au stock');
    }
}
