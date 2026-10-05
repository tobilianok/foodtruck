<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\MealPlanEntry;
use App\Models\PantryItem;
use App\Models\Receipt;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Store;
use App\Models\User;
use App\Support\Pantry;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** v0.9.1 : remise à zéro des données d'usage (planning, listes, stock). */
class ResetTest extends TestCase
{
    use RefreshDatabase;

    private User $louis;

    private User $voisin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 9, 29, 10, 0, 0, 'Europe/Paris'));
        ReferenceImporter::import();

        $this->louis = $this->householdUser();
        $this->voisin = $this->householdUser();
        $this->louis->household->update(['main_store_id' => Store::firstWhere('slug', 'leclerc-drive')->id]);

        foreach ([$this->louis, $this->voisin] as $user) {
            $recipe = Recipe::create(['title' => 'Plat '.$user->id, 'slug' => 'plat-'.$user->id, 'category' => 'plat', 'yield_quantity' => 4, 'yield_unit' => 'personnes', 'status' => Recipe::STATUS_PUBLISHED, 'author_id' => $user->id]);
            $recipe->ingredients()->create(['position' => 0, 'ingredient_id' => \App\Models\Ingredient::firstWhere('slug', 'oeuf')->id, 'quantity' => 3, 'unit' => 'piece']);

            $source = MealPlanEntry::create(['household_id' => $user->household_id, 'date' => '2026-09-30', 'slot' => 'diner', 'position' => 0, 'kind' => 'recette', 'recipe_id' => $recipe->id, 'meals' => 2]);
            MealPlanEntry::create(['household_id' => $user->household_id, 'date' => '2026-10-01', 'slot' => 'dejeuner', 'position' => 0, 'kind' => 'restes', 'recipe_id' => $recipe->id, 'source_entry_id' => $source->id]);

            $list = ShoppingList::create(['household_id' => $user->household_id, 'date_from' => '2026-09-29', 'date_to' => '2026-10-05']);
            ShoppingListItem::create(['shopping_list_id' => $list->id, 'label' => 'Lessive', 'source' => 'manuel']);

            Pantry::add($user->household, \App\Models\Ingredient::firstWhere('slug', 'oeuf'), 6, '2026-10-02');
        }

        Receipt::create(['household_id' => $this->louis->household_id, 'source' => 'manuel', 'store_id' => Store::firstWhere('slug', 'leclerc-drive')->id, 'purchased_on' => '2026-09-21', 'raw_text' => 'Oeufs 2,50']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_apercu_ne_supprime_rien(): void
    {
        $this->artisan('foodtruck:reset', ['--apercu' => true])
            ->expectsOutputToContain('Repas du planning')
            ->expectsOutputToContain('Conservé')
            ->assertSuccessful();

        $this->assertSame(4, MealPlanEntry::count());
        $this->assertSame(2, ShoppingList::count());
        $this->assertSame(2, PantryItem::count());
    }

    public function test_confirmation_refusee_ne_supprime_rien(): void
    {
        $this->artisan('foodtruck:reset')->expectsQuestion('Pour confirmer, tape EFFACER', 'non')->assertFailed();

        $this->assertSame(4, MealPlanEntry::count());
        $this->assertSame(2, PantryItem::count());
    }

    public function test_reset_efface_l_usage_et_garde_le_reste(): void
    {
        $recipes = Recipe::count();
        $ingredients = \App\Models\Ingredient::count();
        $prices = \App\Models\Price::count();

        $this->artisan('foodtruck:reset')->expectsQuestion('Pour confirmer, tape EFFACER', 'EFFACER')->assertSuccessful();

        $this->assertSame(0, MealPlanEntry::count());
        $this->assertSame(0, ShoppingList::count());
        $this->assertSame(0, ShoppingListItem::count());
        $this->assertSame(0, PantryItem::count());

        // Conservé
        $this->assertSame(2, User::count());
        $this->assertSame(2, Household::count());
        $this->assertSame($recipes, Recipe::count());
        $this->assertSame($ingredients, \App\Models\Ingredient::count());
        $this->assertSame($prices, \App\Models\Price::count());
        $this->assertSame(1, Receipt::count());
        $this->assertNotNull($this->louis->household->fresh()->main_store_id);
    }

    public function test_reset_d_un_seul_foyer(): void
    {
        $this->artisan('foodtruck:reset', ['--foyer' => $this->louis->household_id, '--oui' => true])->assertSuccessful();

        $this->assertSame(0, MealPlanEntry::where('household_id', $this->louis->household_id)->count());
        $this->assertSame(0, ShoppingList::where('household_id', $this->louis->household_id)->count());
        $this->assertSame(0, PantryItem::where('household_id', $this->louis->household_id)->count());

        $this->assertSame(2, MealPlanEntry::where('household_id', $this->voisin->household_id)->count());
        $this->assertSame(1, ShoppingList::where('household_id', $this->voisin->household_id)->count());
        $this->assertSame(1, ShoppingListItem::count());
        $this->assertSame(1, PantryItem::where('household_id', $this->voisin->household_id)->count());
    }

    public function test_rien_a_effacer(): void
    {
        $this->artisan('foodtruck:reset', ['--oui' => true])->assertSuccessful();
        $this->artisan('foodtruck:reset', ['--oui' => true])->expectsOutputToContain('Rien à effacer')->assertSuccessful();
    }
}
