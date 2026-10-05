<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\User;
use App\Support\RecipeImporter;
use App\Support\ReferenceImporter;
use App\Support\WeekFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** v0.11.0 : l'accueil guide la semaine en 4 étapes ; pages « Plus » et « Aide ». */
class HomeFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $louis;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 9, 29, 10, 0, 0, 'Europe/Paris')); // un mardi
        ReferenceImporter::import();
        RecipeImporter::import();
        $this->louis = $this->householdUser();
        $this->louis->household->members()->create(['name' => 'Louis', 'category' => 'adulte', 'portion_coefficient' => 1, 'user_id' => $this->louis->id, 'position' => 10]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_les_quatre_etapes_se_suivent(): void
    {
        $this->actingAs($this->louis);

        // 1. rien de prévu : choisir les repas
        $this->get('/')->assertOk()->assertSee('Choisis les repas de la semaine')->assertSee('Choisir mes repas')
            ->assertSee('route-stop', false)->assertSee('Repas')->assertSee('Bilan');
        $this->assertSame(0, WeekFlow::for($this->louis->household)->index);

        // 2. un plat est prévu : préparer la liste
        $hachis = Recipe::firstWhere('slug', 'hachis-parmentier-aux-legumes-caches');
        $this->post('/planning/repas', ['kind' => 'recette', 'recipe_id' => $hachis->id, 'date' => '2026-09-30', 'slot' => 'diner'])->assertRedirect();
        $this->get('/')->assertSee('Prépare ta liste de courses')->assertSee('1 plat au menu');
        $this->assertSame(1, WeekFlow::for($this->louis->household->fresh())->index);

        // 3. liste créée : faire les courses
        $this->post('/courses', ['date_from' => '2026-09-28', 'date_to' => '2026-10-04'])->assertRedirect();
        $this->get('/')->assertSee('Fais tes courses')->assertSee('Ouvrir ma liste');
        $this->assertSame(2, WeekFlow::for($this->louis->household->fresh())->index);

        // 4. tout est coché : terminer, puis bilan
        $list = ShoppingList::firstOrFail();
        $list->items()->where('section', 'achat')->update(['is_checked' => true]);
        $this->get('/')->assertSee('Courses terminées ?')->assertSee('Terminer les courses');

        $this->post("/courses/liste/{$list->id}/terminer");
        $this->get('/')->assertSee('Fais le bilan')->assertSee('Voir le bilan');
        $flow = WeekFlow::for($this->louis->household->fresh());
        $this->assertSame('bilan', $flow->key);
        $this->assertFalse($flow->done);
    }

    public function test_navigation_a_cinq_onglets(): void
    {
        $page = $this->actingAs($this->louis)->get('/')->assertOk()->getContent();

        foreach (['Accueil', 'Menus', 'Courses', 'Recettes', 'Plus'] as $label) {
            $this->assertStringContainsString($label, $page);
        }
        $this->assertStringNotContainsString('>Ingrédients<', $page, 'Les modules secondaires sont rangés sous « Plus »');
        $this->assertStringContainsString('class="tabbar"', $page);
    }

    public function test_plus_et_aide(): void
    {
        $this->actingAs($this->louis)->get('/plus')->assertOk()
            ->assertSee('Frigo et placards')->assertSee('Tickets de caisse')->assertSee('Ingrédients')->assertSee('Prix par magasin')->assertSee('Mon foyer')
            ->assertSee('Se déconnecter');
        $this->get('/aide')->assertOk()->assertSee('Choisis les repas')->assertSee('Fais le bilan')->assertSee('Questions fréquentes');
    }

    public function test_pages_reservees_aux_comptes_connectes(): void
    {
        $this->get('/aide')->assertRedirect();
        $this->get('/plus')->assertRedirect();
    }
}
