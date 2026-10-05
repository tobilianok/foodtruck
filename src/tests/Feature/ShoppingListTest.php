<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Ingredient;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Store;
use App\Models\User;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** v0.8.0 : liste de courses calculée depuis le planning, partagée et cochable. */
class ShoppingListTest extends TestCase
{
    use RefreshDatabase;

    private User $louis;

    private User $marina;

    private Recipe $galettes;

    private Recipe $flan;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 9, 29, 10, 0, 0, 'Europe/Paris')); // un mardi
        ReferenceImporter::import();

        $this->louis = $this->householdUser();
        $household = $this->louis->household;
        $household->update([
            'main_store_id' => Store::firstWhere('slug', 'leclerc-drive')->id,
            'produce_store_id' => Store::firstWhere('slug', 'morin')->id,
        ]);
        $household->members()->create(['name' => 'Tobilianok', 'category' => 'adulte', 'portion_coefficient' => 1.5, 'user_id' => $this->louis->id, 'position' => 10]);
        $this->marina = $this->householdUser(User::HOUSEHOLD_MEMBER, $household);

        $this->galettes = $this->recipe('Galettes test', [['oeuf', 3, 'piece'], ['farine-de-ble-t55', 250, 'g'], ['lait-entier', 20, 'cl'], ['huile-dolive', 1, 'cas'], ['sel-fin', 1, 'pincee'], ['carotte', 2, 'piece']]);
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
            $recipe->ingredients()->create(['position' => $i, 'ingredient_id' => Ingredient::firstWhere('slug', $slug)->id, 'quantity' => $quantity, 'unit' => $unit]);
        }

        return $recipe;
    }

    /** Repas planifié pour 4 parts exactement (donc quantités de la recette telles qu'écrites). */
    private function plan(Recipe $recipe, string $date, string $slot = 'diner', array $extra = [])
    {
        return $this->actingAs($this->louis)->post('/planning/repas', ['kind' => 'recette', 'recipe_id' => $recipe->id, 'date' => $date, 'slot' => $slot, 'parts' => 4] + $extra);
    }

    private function createList(string $from = '2026-09-29', string $to = '2026-10-05', ?User $user = null): ShoppingList
    {
        $this->actingAs($user ?? $this->louis)->post('/courses', ['date_from' => $from, 'date_to' => $to])->assertRedirect('/courses');

        return ShoppingList::latest('id')->first();
    }

    private function item(ShoppingList $list, string $slug): ShoppingListItem
    {
        return $list->items()->where('ingredient_id', Ingredient::firstWhere('slug', $slug)->id)->firstOrFail();
    }

    private function twoMeals(): void
    {
        $this->plan($this->galettes, '2026-09-29');
        $this->plan($this->flan, '2026-09-30');
    }

    public function test_sans_liste_on_propose_de_la_creer(): void
    {
        $this->actingAs($this->louis)->get('/courses')->assertOk()
            ->assertSee('Ta liste de courses')->assertSee('Calculer la liste')
            ->assertSee('value="2026-09-29"', false)->assertSee('value="2026-10-05"', false);
        $this->get('/')->assertSee('Choisis les repas de la semaine');
        $this->assertStringContainsString('>Courses<', $this->get('/')->getContent());
    }

    public function test_quantites_cumulees_et_conditionnements_entiers(): void
    {
        $this->twoMeals();
        $list = $this->createList();

        // 20 cl + 40 cl de lait → une bouteille de 1 L, il en reste 40 cl
        $lait = $this->item($list, 'lait-entier');
        $this->assertSame([600.0, '1 × Bouteille 1 L', 125, '40 cl'], [$lait->needed_base, $lait->purchaseLabel(), $lait->estimated_cents, $lait->surplusLabel()]);
        $this->assertEqualsCanonicalizing(['Galettes test', 'Flan test'], array_column($lait->uses, 'title'));

        // 3 + 4 œufs → une boîte de 12, moins chère que deux boîtes de 6
        $oeufs = $this->item($list, 'oeuf');
        $this->assertSame([7.0, '1 × Boîte de 12', 399], [$oeufs->needed_base, $oeufs->purchaseLabel(), $oeufs->estimated_cents]);

        // Fruits et légumes chez le primeur, au poids : 2 carottes de 125 g = 250 g
        $carotte = $this->item($list, 'carotte');
        $this->assertSame([Store::firstWhere('slug', 'morin')->id, 250.0, 35], [$carotte->store_id, $carotte->needed_base, $carotte->estimated_cents]);
        $this->assertSame(Store::firstWhere('slug', 'leclerc-drive')->id, $lait->store_id, 'Le reste au magasin principal');

        // Produits de base : à vérifier chez soi, jamais comptés
        foreach (['farine-de-ble-t55', 'sel-fin', 'huile-dolive'] as $slug) {
            $this->assertSame('verifier', $this->item($list, $slug)->section, $slug);
        }

        $this->assertSame(125 + 399 + 35 + 269, $list->items()->where('section', 'achat')->sum('estimated_cents'));
        $this->assertSame(0, $list->items()->where('source', 'manuel')->count());
    }

    public function test_page_par_magasin_et_par_rayon(): void
    {
        $this->twoMeals();
        $this->createList();

        $page = $this->actingAs($this->louis)->get('/courses')->assertOk();
        $page->assertSee('Courses du 29 septembre au 5 octobre 2026')
            ->assertSee('Leclerc Drive')->assertSee('Morin Fruits et Légumes')
            ->assertSee('1 × Bouteille 1 L')->assertSee('1 × Boîte de 12')->assertSee('250 g (vrac)')->assertDontSee('<h2>Lidl</h2>', false)
            ->assertSee('Crèmerie')->assertSee('Fruits et légumes')
            ->assertSee('besoin : 60 cl')->assertSee('il en restera 40 cl')
            ->assertSee('À vérifier chez vous')->assertSee('Farine de blé T55')
            ->assertSee('Estimation en caisse : 8,28 €')->assertSee('pour un budget de 100,00 €');
    }

    public function test_les_restes_ne_sont_jamais_recomptes(): void
    {
        // 3 repas du même plat : quantités × 3 (cuisiné une fois), pas × 3 puis encore pour chaque reste
        $this->plan($this->flan, '2026-09-29', 'diner', ['repas' => 3]);
        $this->assertSame(2, MealPlanEntry::where('kind', 'restes')->count());

        $list = $this->createList();
        $this->assertSame(1200.0, $this->item($list, 'lait-entier')->needed_base);
        $this->assertSame(12.0, $this->item($list, 'oeuf')->needed_base);
        $this->assertCount(1, $this->item($list, 'oeuf')->uses);
    }

    public function test_periode_et_repas_ecartes(): void
    {
        $this->plan($this->galettes, '2026-09-29');
        $this->plan($this->flan, '2026-10-12'); // hors période
        $list = $this->createList('2026-09-29', '2026-10-05');

        $this->assertNull($list->items()->where('label', 'Beurre doux')->first(), 'Repas du 12 octobre non compté');

        // Période étendue : le flan arrive
        $this->put("/courses/liste/{$list->id}", ['date_from' => '2026-09-29', 'date_to' => '2026-10-12'])->assertRedirect('/courses');
        $this->assertNotNull($list->items()->where('label', 'Beurre doux')->first());

        // Repas écarté (décoché) : ses ingrédients disparaissent
        $flan = MealPlanEntry::firstWhere('recipe_id', $this->flan->id);
        $galettes = MealPlanEntry::firstWhere('recipe_id', $this->galettes->id);
        $this->put("/courses/liste/{$list->id}", ['date_from' => '2026-09-29', 'date_to' => '2026-10-12', 'vus' => [$flan->id, $galettes->id], 'inclus' => [$galettes->id]]);
        $this->assertNull($list->items()->where('label', 'Beurre doux')->first());
        $this->assertSame([$flan->id], $list->fresh()->excluded_entry_ids);
        $this->assertSame(3.0, $this->item($list, 'oeuf')->needed_base);

        $this->get('/courses')->assertSee('Période et repas pris en compte');

        // Dates incohérentes
        $this->put("/courses/liste/{$list->id}", ['date_from' => '2026-10-05', 'date_to' => '2026-09-29'])->assertSessionHasErrors('date_to');
        // Durée plafonnée à 31 jours
        $this->put("/courses/liste/{$list->id}", ['date_from' => '2026-09-29', 'date_to' => '2027-03-01'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-29', $list->fresh()->date_to->toDateString());
    }

    public function test_cocher_est_partage_et_survit_au_recalcul(): void
    {
        $this->twoMeals();
        $list = $this->createList();
        $lait = $this->item($list, 'lait-entier');

        $this->actingAs($this->marina)->postJson("/courses/articles/{$lait->id}/cocher", ['checked' => 1])
            ->assertOk()->assertJson(['id' => $lait->id, 'checked' => 1]);

        // Louis voit la case cochée par Marina, sans recharger la liste
        $state = $this->actingAs($this->louis)->getJson("/courses/liste/{$list->id}/etat")->assertOk()->json();
        $this->assertSame([1, $this->marina->firstName()], $state['checked'][$lait->id]);
        $this->assertSame(1, $state['done']);
        $this->assertSame(4, $state['total'], 'Lait, œufs, carottes, beurre (les 3 produits de base sont à vérifier)');
        $this->assertFalse($state['stale']);

        // Recalcul d'après le planning : la case reste cochée
        $this->post("/courses/liste/{$list->id}/actualiser")->assertRedirect('/courses');
        $this->assertTrue($lait->fresh()->is_checked);
        $this->assertSame($this->marina->id, $lait->fresh()->checked_by);

        $this->get('/courses')->assertSee('is-checked', false)->assertSee('✓ '.$this->marina->firstName(), false);

        // Décocher
        $this->postJson("/courses/articles/{$lait->id}/cocher", ['checked' => 0])->assertJson(['checked' => 0, 'by' => null]);
        $this->assertFalse($lait->fresh()->is_checked);
    }

    public function test_planning_modifie_la_liste_est_signalee_puis_mise_a_jour(): void
    {
        $this->plan($this->galettes, '2026-09-29');
        $list = $this->createList();
        $this->assertFalse($this->getJson("/courses/liste/{$list->id}/etat")->json('stale'));

        $this->plan($this->flan, '2026-09-30');
        $this->assertTrue($this->getJson("/courses/liste/{$list->id}/etat")->json('stale'));
        $this->get('/courses')->assertSee('Le planning a changé depuis le calcul de cette liste');

        $revision = $list->fresh()->revision;
        $this->post("/courses/liste/{$list->id}/actualiser");
        $this->assertFalse($this->getJson("/courses/liste/{$list->id}/etat")->json('stale'));
        $this->assertGreaterThan($revision, $list->fresh()->revision, 'Les autres téléphones sont prévenus');
        $this->assertSame(7.0, $this->item($list, 'oeuf')->needed_base);
    }

    public function test_changer_un_article_de_magasin(): void
    {
        $this->twoMeals();
        $list = $this->createList();
        $leclerc = Store::firstWhere('slug', 'leclerc-drive');
        $morin = Store::firstWhere('slug', 'morin');

        // Carottes chez Leclerc : 250 g à 1,69 €/kg
        $carotte = $this->item($list, 'carotte');
        $this->put("/courses/articles/{$carotte->id}", ['action' => 'store', 'store_id' => $leclerc->id])->assertSessionHasNoErrors();
        $carotte->refresh();
        $this->assertSame([$leclerc->id, true, 42, 35, $morin->id], [$carotte->store_id, $carotte->store_locked, $carotte->estimated_cents, $carotte->best_cents, $carotte->best_store_id]);
        $this->assertSame(7, $carotte->possibleSaving());

        // Beurre chez Morin : aucun prix connu
        $beurre = $this->item($list, 'beurre-doux');
        $this->put("/courses/articles/{$beurre->id}", ['action' => 'store', 'store_id' => $morin->id]);
        $this->assertNull($beurre->fresh()->estimated_cents);
        $this->get('/courses')->assertSee('prix inconnu');

        // Le choix survit au recalcul
        $this->post("/courses/liste/{$list->id}/actualiser");
        $this->assertSame($leclerc->id, $carotte->fresh()->store_id);
        $this->assertSame($morin->id, $beurre->fresh()->store_id);

        // Magasin inactif ou inexistant refusé
        $this->put("/courses/articles/{$carotte->id}", ['action' => 'store', 'store_id' => 9999])->assertSessionHasErrors('store_id');
    }

    public function test_produit_de_base_deja_a_la_maison_ou_a_acheter(): void
    {
        $this->twoMeals();
        $list = $this->createList();

        $farine = $this->item($list, 'farine-de-ble-t55');
        $this->assertSame('verifier', $farine->section);
        $this->assertSame(0, (int) $list->items()->where('section', 'achat')->where('label', 'Farine de blé T55')->count());

        // Il en manque : ajouté aux courses (et compté dans le budget)
        $this->put("/courses/articles/{$farine->id}", ['action' => 'section']);
        $this->assertSame(['achat', true, 89], [$farine->fresh()->section, $farine->fresh()->section_locked, $farine->fresh()->estimated_cents]);

        // Un légume déjà en stock : passé dans « à vérifier »
        $carotte = $this->item($list, 'carotte');
        $this->put("/courses/articles/{$carotte->id}", ['action' => 'section']);
        $this->assertSame('verifier', $carotte->fresh()->section);

        // Les choix tiennent après un recalcul
        $this->post("/courses/liste/{$list->id}/actualiser");
        $this->assertSame('achat', $farine->fresh()->section);
        $this->assertSame('verifier', $carotte->fresh()->section);
    }

    public function test_ajouts_manuels(): void
    {
        $this->plan($this->galettes, '2026-09-29');
        $list = $this->createList();

        // Article connu du référentiel : rayon et prix repris
        $this->post("/courses/liste/{$list->id}/articles", ['label' => 'lait entier', 'quantity_text' => '2'])->assertSessionHasNoErrors();
        $lait = $list->items()->where('source', 'manuel')->first();
        $this->assertSame([Ingredient::firstWhere('slug', 'lait-entier')->id, 125, '1 × Bouteille 1 L'], [$lait->ingredient_id, $lait->estimated_cents, $lait->purchaseLabel()]);

        // Ligne libre, magasin choisi
        $lidl = Store::firstWhere('slug', 'lidl');
        $this->post("/courses/liste/{$list->id}/articles", ['label' => 'Lessive', 'store_id' => $lidl->id, 'aisle_id' => \App\Models\Aisle::firstWhere('slug', 'maison')->id]);
        $lessive = $list->items()->where('label', 'Lessive')->first();
        $this->assertSame([$lidl->id, null, 'manuel'], [$lessive->store_id, $lessive->estimated_cents, $lessive->source]);

        $this->get('/courses')->assertSee('Lessive')->assertSee('Lidl')->assertSee('Hygiène, entretien');

        // Un recalcul ne touche pas aux ajouts manuels, qui peuvent être retirés
        $this->post("/courses/liste/{$list->id}/actualiser");
        $this->assertSame(2, $list->items()->where('source', 'manuel')->count());
        $this->delete("/courses/articles/{$lessive->id}")->assertRedirect('/courses');
        $this->assertSame(1, $list->items()->where('source', 'manuel')->count());

        // Un article issu d'une recette ne se supprime pas (on le passe en « déjà à la maison »)
        $this->delete('/courses/articles/'.$this->item($list, 'oeuf')->id)->assertNotFound();
        $this->post("/courses/liste/{$list->id}/articles", ['label' => ''])->assertSessionHasErrors('label');
    }

    public function test_courses_terminees_historique_et_une_seule_liste_en_cours(): void
    {
        $this->plan($this->galettes, '2026-09-29');
        $first = $this->createList();

        $this->post("/courses/liste/{$first->id}/terminer")->assertRedirect("/courses/liste/{$first->id}/bilan");
        $this->assertTrue($first->fresh()->isArchived());
        $this->get('/courses')->assertSee('Calculer la liste')->assertSee('Listes précédentes')->assertSee('Courses du 29 septembre au 5 octobre 2026');

        $this->get("/courses/liste/{$first->id}")->assertOk()->assertSee('Rouvrir cette liste')->assertDontSee('Ajouter un article');

        $second = $this->createList('2026-10-06', '2026-10-12');
        $this->assertSame(1, ShoppingList::whereNull('archived_at')->count());

        // Créer une liste en classe l'ancienne ; rouvrir classe l'autre
        $this->post("/courses/liste/{$first->id}/rouvrir");
        $this->assertFalse($first->fresh()->isArchived());
        $this->assertTrue($second->fresh()->isArchived());
    }

    public function test_une_liste_est_privee_a_son_foyer(): void
    {
        $this->plan($this->galettes, '2026-09-29');
        $list = $this->createList();
        $item = $this->item($list, 'oeuf');

        $stranger = $this->householdUser();
        $this->actingAs($stranger);
        $this->get("/courses/liste/{$list->id}")->assertNotFound();
        $this->getJson("/courses/liste/{$list->id}/etat")->assertNotFound();
        $this->postJson("/courses/articles/{$item->id}/cocher", ['checked' => 1])->assertNotFound();
        $this->put("/courses/articles/{$item->id}", ['action' => 'section'])->assertNotFound();
        $this->post("/courses/liste/{$list->id}/articles", ['label' => 'Pirate'])->assertNotFound();
        $this->get('/courses')->assertDontSee('Courses du');
        $this->assertFalse($item->fresh()->is_checked);

        auth()->logout();
        $this->get('/courses')->assertRedirect('/login');
    }

    public function test_budget_au_prorata_de_la_periode(): void
    {
        $this->plan($this->galettes, '2026-09-29');
        $list = $this->createList('2026-09-29', '2026-10-05');
        $this->assertSame(10000, $list->budgetCents());

        $short = $this->createList('2026-09-29', '2026-10-02');
        $this->assertSame(5714, $short->budgetCents(), '4 jours sur 7 de 100 €');
    }
}
