<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\Store;
use App\Models\User;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** v0.9.1 : remplacer un plat trop cher quand le budget est entamé à 80 %. */
class SavingsTest extends TestCase
{
    use RefreshDatabase;

    private User $louis;

    private Recipe $cher;

    private Recipe $cheap;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 9, 29, 10, 0, 0, 'Europe/Paris'));
        ReferenceImporter::import();

        $this->louis = $this->householdUser();
        $this->louis->household->update(['main_store_id' => Store::firstWhere('slug', 'leclerc-drive')->id, 'weekly_budget_cents' => 900]);
        $this->louis->household->members()->create(['name' => 'Tobilianok', 'category' => 'adulte', 'portion_coefficient' => 1.5, 'user_id' => $this->louis->id, 'position' => 10]);

        $this->cher = $this->recipe('Plat cher', 'plat', [['beurre-doux', 400, 'g'], ['emmental-rape', 300, 'g']]);
        $this->cheap = $this->recipe('Plat économique', 'plat', [['carotte', 300, 'g'], ['pomme-de-terre', 500, 'g']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function recipe(string $title, string $category, array $lines, string $status = Recipe::STATUS_PUBLISHED, string $unit = 'personnes'): Recipe
    {
        $recipe = Recipe::create(['title' => $title, 'slug' => str($title)->slug(), 'category' => $category, 'yield_quantity' => 4, 'yield_unit' => $unit, 'status' => $status, 'author_id' => $this->louis->id]);
        foreach ($lines as $i => [$slug, $q, $u]) {
            $recipe->ingredients()->create(['position' => $i, 'ingredient_id' => Ingredient::firstWhere('slug', $slug)->id, 'quantity' => $q, 'unit' => $u]);
        }

        return $recipe;
    }

    private function plan(Recipe $recipe, string $date, int $meals = 1)
    {
        $this->actingAs($this->louis)->post('/planning/repas', ['kind' => 'recette', 'recipe_id' => $recipe->id, 'date' => $date, 'slot' => 'diner', 'parts' => 4, 'repas' => $meals])->assertSessionHasNoErrors();

        return MealPlanEntry::where('recipe_id', $recipe->id)->where('kind', 'recette')->latest('id')->first();
    }

    public function test_pas_de_proposition_tant_que_le_budget_est_confortable(): void
    {
        $this->louis->household->update(['weekly_budget_cents' => 100000]);
        $this->plan($this->cher, '2026-09-30');

        $this->get('/planning/2026-09-28')->assertOk()->assertDontSee('Économiser sur la semaine');
    }

    public function test_proposition_quand_le_budget_est_entame(): void
    {
        $this->plan($this->cher, '2026-09-30');

        $this->get('/planning/2026-09-28')->assertOk()
            ->assertSee('Économiser sur la semaine')->assertSee('Plat économique')->assertSee('Remplacer');
    }

    public function test_plat_deja_passe_ou_deja_au_planning_non_propose(): void
    {
        $this->plan($this->cher, '2026-09-28');          // lundi : déjà passé
        $this->get('/planning/2026-09-28')->assertDontSee('Économiser sur la semaine');

        $this->plan($this->cher, '2026-10-01');
        $this->plan($this->cheap, '2026-10-02');         // déjà au planning : pas reproposé
        $this->get('/planning/2026-09-28')->assertDontSee('Économiser sur la semaine');
    }

    public function test_recettes_incompletes_ou_brouillons_non_proposees(): void
    {
        $this->cheap->update(['status' => Recipe::STATUS_DRAFT]);
        $this->recipe('Plat sans prix', 'plat', [['carotte', 300, 'g']], Recipe::STATUS_PUBLISHED);
        $this->recipe('Dessert pas cher', 'dessert', [['carotte', 100, 'g']]);
        $this->plan($this->cher, '2026-09-30');

        $this->get('/planning/2026-09-28')->assertDontSee('Plat économique')->assertDontSee('Dessert pas cher');
    }

    public function test_remplacement(): void
    {
        $entry = $this->plan($this->cher, '2026-09-30', 2);
        $this->assertSame(1, MealPlanEntry::where('source_entry_id', $entry->id)->count(), 'Un reste est placé');

        $this->post("/planning/repas/{$entry->id}/remplacer", ['recipe_id' => $this->cheap->id])->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, '« Plat cher » remplacé par « Plat économique »'));

        $this->assertSame($this->cheap->id, $entry->fresh()->recipe_id);
        $this->assertSame([$this->cheap->id], MealPlanEntry::where('source_entry_id', $entry->id)->pluck('recipe_id')->all());
        $this->assertSame('2026-09-30', $entry->fresh()->date->toDateString());
    }

    public function test_remplacement_refuse(): void
    {
        $entry = $this->plan($this->cher, '2026-09-30');
        $draft = $this->recipe('Brouillon', 'plat', [['carotte', 100, 'g']], Recipe::STATUS_DRAFT);
        $pots = $this->recipe('Yaourts', 'plat', [['carotte', 100, 'g']], Recipe::STATUS_PUBLISHED, 'pots');

        $this->post("/planning/repas/{$entry->id}/remplacer", ['recipe_id' => $draft->id])->assertNotFound();
        $this->post("/planning/repas/{$entry->id}/remplacer", ['recipe_id' => $pots->id])->assertNotFound();

        $stranger = $this->householdUser();
        $this->actingAs($stranger)->post("/planning/repas/{$entry->id}/remplacer", ['recipe_id' => $this->cheap->id])->assertNotFound();
        $this->assertSame($this->cher->id, $entry->fresh()->recipe_id);
    }
}
