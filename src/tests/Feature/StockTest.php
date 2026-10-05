<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\PantryItem;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Store;
use App\Models\User;
use App\Support\AntiWaste;
use App\Support\Pantry;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** v0.9.0 : stock du foyer, déduction dans la liste de courses, fin des courses, anti-gaspi. */
class StockTest extends TestCase
{
    use RefreshDatabase;

    private User $louis;

    private Recipe $galettes;

    private Recipe $flan;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 9, 29, 10, 0, 0, 'Europe/Paris'));
        ReferenceImporter::import();

        $this->louis = $this->householdUser();
        $household = $this->louis->household;
        $household->update(['main_store_id' => Store::firstWhere('slug', 'leclerc-drive')->id, 'produce_store_id' => Store::firstWhere('slug', 'morin')->id]);
        $household->members()->create(['name' => 'Tobilianok', 'category' => 'adulte', 'portion_coefficient' => 1.5, 'user_id' => $this->louis->id, 'position' => 10]);

        $this->galettes = $this->recipe('Galettes test', [['oeuf', 3, 'piece'], ['farine-de-ble-t55', 250, 'g'], ['lait-entier', 20, 'cl'], ['carotte', 2, 'piece']]);
        $this->flan = $this->recipe('Flan test', [['lait-entier', 40, 'cl'], ['oeuf', 4, 'piece'], ['beurre-doux', 30, 'g']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function recipe(string $title, array $lines): Recipe
    {
        $recipe = Recipe::create([
            'title' => $title, 'slug' => str($title)->slug(), 'category' => 'plat', 'yield_quantity' => 4, 'yield_unit' => 'personnes',
            'prep_minutes' => 10, 'cook_minutes' => 20, 'difficulty' => 'facile', 'status' => Recipe::STATUS_PUBLISHED, 'author_id' => $this->louis->id,
        ]);
        foreach ($lines as $i => [$slug, $quantity, $unit]) {
            $recipe->ingredients()->create(['position' => $i, 'ingredient_id' => $this->ing($slug)->id, 'quantity' => $quantity, 'unit' => $unit]);
        }

        return $recipe;
    }

    private function ing(string $slug): Ingredient
    {
        return Ingredient::with('aisle')->firstWhere('slug', $slug);
    }

    private function stock(string $slug, float $quantity, ?string $expires = null, ?string $location = null): PantryItem
    {
        return Pantry::add($this->louis->household, $this->ing($slug), $quantity, $expires, $location);
    }

    private function plan(Recipe $recipe, string $date)
    {
        return $this->actingAs($this->louis)->post('/planning/repas', ['kind' => 'recette', 'recipe_id' => $recipe->id, 'date' => $date, 'slot' => 'diner', 'parts' => 4]);
    }

    private function createList(): ShoppingList
    {
        $this->actingAs($this->louis)->post('/courses', ['date_from' => '2026-09-29', 'date_to' => '2026-10-05'])->assertRedirect('/courses');

        return ShoppingList::latest('id')->first();
    }

    private function item(ShoppingList $list, string $slug): ShoppingListItem
    {
        return $list->items()->where('ingredient_id', $this->ing($slug)->id)->firstOrFail();
    }

    private function qty(string $slug): float
    {
        return (float) PantryItem::where('ingredient_id', $this->ing($slug)->id)->sum('quantity');
    }

    // ------------------------------------------------------------------ page Stock

    public function test_ajouter_modifier_retirer(): void
    {
        $this->actingAs($this->louis)->get('/stock')->assertOk()->assertSee('Le stock est vide')->assertSee('Ajouter au stock');

        // 50 cl de lait → 500 ml, rangé au frigo (rayon crèmerie)
        $this->post('/stock', ['ingredient' => 'lait entier', 'quantite' => '50', 'unite' => 'cl'])->assertRedirect('/stock')
            ->assertSessionHas('status', fn ($s) => str_contains($s, '50 cl'));
        $lot = PantryItem::first();
        $this->assertSame([500.0, 'frigo', 'manuel'], [$lot->quantity, $lot->location, $lot->source]);

        // Même ingrédient, même endroit, même date limite : les quantités s'additionnent
        $this->post('/stock', ['ingredient' => 'Lait entier', 'quantite' => '0,5', 'unite' => 'l']);
        $this->assertSame([1, 1000.0], [PantryItem::count(), PantryItem::first()->quantity]);

        // Une autre date limite = un autre lot
        $this->post('/stock', ['ingredient' => 'Lait entier', 'quantite' => '1', 'unite' => 'l', 'expires_on' => '2026-09-30']);
        $this->assertSame(2, PantryItem::count());

        $this->get('/stock')->assertSee('Lait entier')->assertSee('1 L')->assertSee('expire demain')->assertSee('À consommer vite')->assertSee('Frigo');

        // Modification : 1,5 kg de farine → 1500 g, au placard
        $this->post('/stock', ['ingredient' => 'Farine de blé T55', 'quantite' => '1', 'unite' => 'kg']);
        $farine = PantryItem::where('ingredient_id', $this->ing('farine-de-ble-t55')->id)->first();
        $this->assertSame([1000.0, 'placard'], [$farine->quantity, $farine->location]);
        $this->put("/stock/{$farine->id}", ['quantite' => '1,5', 'unite' => 'kg', 'expires_on' => '2027-01-31', 'location' => 'placard', 'note' => 'sachet ouvert'])->assertSessionHasNoErrors();
        $farine->refresh();
        $this->assertSame([1500.0, '2027-01-31', 'sachet ouvert'], [$farine->quantity, $farine->expires_on->toDateString(), $farine->note]);
        $this->get('/stock')->assertSee('1,5 kg')->assertSee('sachet ouvert');

        // Retrait
        $this->delete("/stock/{$farine->id}")->assertRedirect('/stock');
        $this->assertNull(PantryItem::find($farine->id));
    }

    public function test_saisies_refusees(): void
    {
        $this->actingAs($this->louis);
        $this->post('/stock', ['ingredient' => 'Truffe du Périgord', 'quantite' => '1', 'unite' => 'g'])->assertSessionHasErrors('ingredient');
        $this->post('/stock', ['ingredient' => 'Lait entier', 'quantite' => '0', 'unite' => 'l'])->assertSessionHasErrors('quantite');
        $this->post('/stock', ['ingredient' => 'Lait entier', 'quantite' => '1', 'unite' => 'tonne'])->assertSessionHasErrors('unite');
        $this->post('/stock', ['ingredient' => 'Lait entier', 'quantite' => '1', 'unite' => 'l', 'expires_on' => 'demain'])->assertSessionHasErrors('expires_on');
        // Carotte : pas de densité connue, impossible de la compter en litres
        $this->post('/stock', ['ingredient' => 'Carotte', 'quantite' => '1', 'unite' => 'l'])->assertSessionHasErrors('quantite');
        $this->assertSame(0, PantryItem::count());
    }

    public function test_le_stock_est_prive_au_foyer(): void
    {
        $lot = $this->stock('lait-entier', 1000);
        $stranger = $this->householdUser();

        $this->actingAs($stranger)->get('/stock')->assertOk()->assertSee('Le stock est vide');
        $this->put("/stock/{$lot->id}", ['quantite' => '5', 'unite' => 'l'])->assertNotFound();
        $this->delete("/stock/{$lot->id}")->assertNotFound();
        $this->assertSame(1000.0, $lot->fresh()->quantity);

        auth()->logout();
        $this->get('/stock')->assertRedirect('/login');
    }

    public function test_dates_limites(): void
    {
        $demain = $this->stock('lait-entier', 1000, '2026-09-30');
        $perime = $this->stock('beurre-doux', 250, '2026-09-27');
        $loin = $this->stock('farine-de-ble-t55', 1000, '2026-12-01');

        $this->assertSame(['expire demain', 'périmé depuis 2 j', 'expire dans 63 j'], [$demain->expiryLabel(), $perime->expiryLabel(), $loin->expiryLabel()]);
        $this->assertSame([true, false, true, false], [$demain->isSoon(), $perime->isSoon(), $perime->isExpired(), $loin->isSoon()]);

        // Un lot périmé n'est pas disponible
        $available = Pantry::available($this->louis->household);
        $this->assertSame([1000.0, 1000.0], [$available[$this->ing('lait-entier')->id], $available[$this->ing('farine-de-ble-t55')->id]]);
        $this->assertArrayNotHasKey($this->ing('beurre-doux')->id, $available);

        // Bandeau du planning et fiche « à consommer vite » (périmé compris, loin exclu)
        $this->actingAs($this->louis)->get('/planning')->assertSee('À consommer vite')->assertSee('Lait entier')->assertSee('Beurre doux')->assertSee('périmé depuis 2 j')->assertDontSee('Farine de blé T55');
    }

    public function test_consommation_par_date_limite(): void
    {
        $this->stock('lait-entier', 500);                       // sans date
        $this->stock('lait-entier', 300, '2026-10-05');         // le plus proche
        $this->stock('lait-entier', 400, '2026-10-20');

        $this->assertSame(600.0, Pantry::consume($this->louis->household, $this->ing('lait-entier')->id, 600));
        $left = PantryItem::orderBy('expires_on')->get()->map(fn ($l) => [$l->expires_on?->toDateString(), $l->quantity])->all();
        $this->assertEqualsCanonicalizing([[null, 500.0], ['2026-10-20', 100.0]], $left, '300 + 300 pris sur le lot qui expire le premier puis le suivant, jamais sur le lot sans date en premier');

        // Plus que ce qu'il y a : on retire ce qui existe
        $this->assertSame(600.0, Pantry::consume($this->louis->household, $this->ing('lait-entier')->id, 5000));
        $this->assertSame(0, PantryItem::count());
    }

    // ------------------------------------------------------------------ liste de courses

    public function test_la_liste_deduit_le_stock(): void
    {
        $this->stock('lait-entier', 400);
        $this->plan($this->galettes, '2026-09-29');
        $this->plan($this->flan, '2026-09-30');
        $list = $this->createList();

        // Besoin 600 ml, stock 400 ml : il reste 200 ml à acheter = 1 bouteille
        $lait = $this->item($list, 'lait-entier');
        $this->assertSame([600.0, 400.0, 'achat', '1 × Bouteille 1 L', 125], [$lait->needed_base, $lait->stock_base, $lait->section, $lait->purchaseLabel(), $lait->estimated_cents]);
        $this->assertSame('40 cl', $lait->stockLabel());
        $this->get('/courses')->assertSee('en stock : 40 cl');

        // Stock suffisant : plus rien à acheter, l'article passe dans « Déjà en stock »
        $this->stock('lait-entier', 600);
        $this->post("/courses/liste/{$list->id}/actualiser");
        $lait->refresh();
        $this->assertSame(['stock', null], [$lait->section, $lait->estimated_cents]);
        $this->assertSame(600.0, $lait->stock_base, 'Le stock utilisé est plafonné au besoin');
        $this->get('/courses')->assertSee('Déjà en stock')->assertSee('en stock');

        // Le total en caisse ne compte plus le lait
        $this->assertSame(399 + 35 + 269, $list->items()->where('section', 'achat')->sum('estimated_cents'));

        // Il n'y en a plus (en vrai) : on l'achète quand même
        $this->put("/courses/articles/{$lait->id}", ['action' => 'stock'])->assertSessionHasNoErrors();
        $lait->refresh();
        $this->assertSame(['achat', true, '1 × Bouteille 1 L'], [$lait->section, $lait->stock_ignored, $lait->purchaseLabel()]);
        $this->assertNull($lait->stock_base);
        $this->put("/courses/articles/{$lait->id}", ['action' => 'stock']);
        $this->assertSame('stock', $lait->fresh()->section, 'Redonne la main au stock');
    }

    public function test_stock_perime_ou_modifie(): void
    {
        $this->stock('lait-entier', 1000, '2026-09-28'); // périmé hier
        $this->plan($this->galettes, '2026-09-29');
        $list = $this->createList();
        $this->assertSame('achat', $this->item($list, 'lait-entier')->section, 'Un lot périmé ne couvre rien');

        // Ajouter du stock rend la liste « à mettre à jour »
        $this->assertFalse($this->getJson("/courses/liste/{$list->id}/etat")->json('stale'));
        $this->stock('lait-entier', 1000);
        $this->assertTrue($this->getJson("/courses/liste/{$list->id}/etat")->json('stale'));
        $this->post("/courses/liste/{$list->id}/actualiser");
        $this->assertSame('stock', $this->item($list, 'lait-entier')->section);
    }

    public function test_un_produit_de_base_en_stock_n_est_plus_a_verifier(): void
    {
        $this->plan($this->galettes, '2026-09-29');
        $this->assertSame('verifier', $this->item($this->createList(), 'farine-de-ble-t55')->section);

        $this->stock('farine-de-ble-t55', 800);
        $list = ShoppingList::first();
        $this->post("/courses/liste/{$list->id}/actualiser");
        $this->assertSame('stock', $this->item($list, 'farine-de-ble-t55')->section);
    }

    public function test_fin_des_courses_met_le_stock_a_jour(): void
    {
        $this->stock('lait-entier', 400);
        $this->plan($this->galettes, '2026-09-29');
        $this->plan($this->flan, '2026-09-30');
        $list = $this->createList();

        // Rien n'est coché : seul le stock utilisé est consommé, rien n'est ajouté
        foreach (['lait-entier', 'oeuf', 'carotte', 'beurre-doux'] as $slug) {
            $this->postJson('/courses/articles/'.$this->item($list, $slug)->id.'/cocher', ['checked' => in_array($slug, ['lait-entier', 'oeuf', 'carotte'], true) ? 1 : 0]);
        }

        // Article ajouté à la main et acheté : tout entre en stock
        $this->post("/courses/liste/{$list->id}/articles", ['label' => 'Beurre doux']);
        $manual = $list->items()->where('source', 'manuel')->first();
        $this->postJson("/courses/articles/{$manual->id}/cocher", ['checked' => 1]);

        $this->post("/courses/liste/{$list->id}/terminer")->assertRedirect('/courses')
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Stock mis à jour'));

        // Lait : 400 ml utilisés, bouteille de 1 L pour 200 ml de besoin → 800 ml de reste
        $this->assertSame(800.0, $this->qty('lait-entier'));
        // Œufs : boîte de 12 pour 7 → 5 de reste, au frigo
        $this->assertSame([5.0, 'frigo'], [$this->qty('oeuf'), PantryItem::firstWhere('ingredient_id', $this->ing('oeuf')->id)->location]);
        // Carottes au poids (250 g pour 250 g) : aucun reste
        $this->assertSame(0.0, $this->qty('carotte'));
        // Beurre : le non coché n'est pas acheté ; l'ajout manuel coché (1 plaquette) entre en entier
        $this->assertSame(250.0, $this->qty('beurre-doux'));
        $this->assertSame('courses', PantryItem::firstWhere('ingredient_id', $this->ing('lait-entier')->id)->source);

        // Une seule fois : rouvrir puis terminer de nouveau ne double rien
        $this->assertNotNull($list->fresh()->stock_applied_at);
        $this->post("/courses/liste/{$list->id}/rouvrir");
        $this->post("/courses/liste/{$list->id}/terminer");
        $this->assertSame([800.0, 5.0, 250.0], [$this->qty('lait-entier'), $this->qty('oeuf'), $this->qty('beurre-doux')]);

        $this->get('/stock')->assertSee('reste des courses')->assertSee('Œuf');
    }

    // ------------------------------------------------------------------ anti-gaspi

    public function test_que_cuisiner_classe_selon_le_stock_et_les_dates_limites(): void
    {
        $household = $this->louis->household->fresh();

        $this->assertTrue(AntiWaste::suggestions($household, $this->louis)->isEmpty(), 'Stock vide : aucune suggestion');

        $this->stock('lait-entier', 1000);
        $this->stock('oeuf', 12);
        $this->stock('carotte', 300, '2026-09-30');  // à finir vite : seulement dans les galettes
        $this->stock('sel-fin', 500);                // produit de base : ne compte pas

        $suggestions = AntiWaste::suggestions($household, $this->louis);
        $this->assertSame(['Galettes test', 'Flan test'], $suggestions->pluck('recipe.title')->all());

        [$galettes, $flan] = $suggestions->all();
        $this->assertSame([3, 3, ['Carotte'], []], [$galettes['covered'], $galettes['total'], $galettes['expiring'], $galettes['missing']], 'Farine = produit de base, non comptée');
        $this->assertSame([2, 3, ['Beurre doux']], [$flan['covered'], $flan['total'], $flan['missing']]);
        $this->assertGreaterThan(0, $flan['missing_cents']);

        $this->actingAs($this->louis)->get('/stock/recettes')->assertOk()
            ->assertSee('Galettes test')->assertSee('3 / 3 en stock')->assertSee('À finir : Carotte')->assertSee('Tout est déjà en stock')
            ->assertSee('À acheter :')->assertSee('Beurre doux')
            ->assertSee('/planning/repas/nouveau?recette=galettes-test', false);
    }

    public function test_que_cuisiner_ignore_les_recettes_sans_rapport(): void
    {
        $this->stock('sel-fin', 500);
        $this->stock('beurre-doux', 10);                                   // un peu de beurre : pas assez pour le flan (11 g)
        $household = $this->louis->household->fresh();

        $this->assertSame([], AntiWaste::suggestions($household, $this->louis)->pluck('recipe.title')->all(), 'Rien de couvert en entier et rien à finir : écartée');

        // Mais un produit à finir fait remonter la recette même peu couverte
        PantryItem::query()->delete();
        $this->stock('beurre-doux', 10, '2026-10-01');
        $this->assertSame(['Flan test'], AntiWaste::suggestions($household, $this->louis)->pluck('recipe.title')->all());

        $this->actingAs($this->louis)->get('/stock/recettes')->assertSee('Flan test');
        $this->get('/')->assertSee('à consommer vite');
    }

    public function test_menu_et_accueil(): void
    {
        $this->actingAs($this->louis)->get('/')->assertOk()->assertSee('Rien en stock pour l\'instant', false)->assertSee('>Stock<', false);
        $this->stock('lait-entier', 1000, '2026-09-30');
        $this->get('/')->assertSee('1 ligne en stock')->assertSee('1 à consommer vite');
        $this->get('/stock/recettes')->assertOk();
    }
}
