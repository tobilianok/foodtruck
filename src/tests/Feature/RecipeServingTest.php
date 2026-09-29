<?php

namespace Tests\Feature;

use App\Models\HouseholdMember;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\User;
use App\Support\RecipeCost;
use App\Support\RecipeImporter;
use App\Support\RecipeServing;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** v0.6.0 : recette proratisée selon le foyer (qui mange, invités, repas) ou par fournée. */
class RecipeServingTest extends TestCase
{
    use RefreshDatabase;

    private User $louis;

    private Recipe $crepes;

    private HouseholdMember $tobi;

    private HouseholdMember $marina;

    private HouseholdMember $bebe;

    protected function setUp(): void
    {
        parent::setUp();
        ReferenceImporter::import();
        RecipeImporter::import();

        $this->louis = $this->householdUser();
        $household = $this->louis->household;
        $this->tobi = $household->members()->create(['name' => 'Tobilianok', 'category' => 'adulte', 'portion_coefficient' => 1.5, 'user_id' => $this->louis->id, 'position' => 10]);
        $this->marina = $household->members()->create(['name' => 'Marina', 'category' => 'adulte', 'portion_coefficient' => 1, 'position' => 20]);
        $this->bebe = $household->members()->create(['name' => 'Bébé', 'category' => 'tout-petit', 'portion_coefficient' => 0, 'position' => 30]);

        // Recette pour 4 personnes aux unités variées
        $this->crepes = Recipe::create([
            'title' => 'Galettes test', 'slug' => 'galettes-test', 'category' => 'plat', 'yield_quantity' => 4, 'yield_unit' => 'personnes',
            'prep_minutes' => 10, 'cook_minutes' => 20, 'difficulty' => 'facile', 'status' => Recipe::STATUS_PUBLISHED, 'author_id' => $this->louis->id,
        ]);
        foreach ([['oeuf', 3, 'piece'], ['farine-de-ble-t55', 250, 'g'], ['lait-entier', 20, 'cl'], ['huile-dolive', 1, 'cas'], ['sel-fin', 1, 'pincee'], ['carotte', 2, 'piece']] as $i => [$slug, $quantity, $unit]) {
            $this->crepes->ingredients()->create(['position' => $i, 'ingredient_id' => Ingredient::firstWhere('slug', $slug)->id, 'quantity' => $quantity, 'unit' => $unit]);
        }
        $this->crepes->steps()->create(['position' => 1, 'body' => 'Mélanger 250 g de farine et 3 œufs.']);
    }

    private function serving(array $input): RecipeServing
    {
        return RecipeServing::for($this->crepes->fresh(), $this->louis->household->fresh()->load('members'), $input);
    }

    public function test_parts_du_foyer_par_defaut(): void
    {
        $serving = $this->serving([]);

        $this->assertSame([2.5, 1, 2.5, 0.625], [$serving->perMeal, $serving->meals, $serving->total, $serving->factor]);
        $this->assertSame([$this->tobi->id, $this->marina->id, $this->bebe->id], $serving->eaters, 'Tout le foyer, tout-petit compris (0 part)');

        $page = $this->actingAs($this->louis)->get("/recettes/{$this->crepes->slug}")->assertOk();
        $page->assertSee('Pour 2,5 parts')->assertSee('Tobilianok, Marina, Bébé')
            ->assertSee('2 pièces')        // 1,875 œuf
            ->assertSee('155 g')           // 156,25 g
            ->assertSee('13 cl')           // 12,5 cl
            ->assertSee('½ c. à soupe')    // 0,625
            ->assertSee('1 pincée')
            ->assertSee('calcul exact : 1,88 pièces')
            ->assertSee('≈ 125 g')         // 1,25 carotte → 1 carotte de 125 g
            ->assertSee('recette d\'origine : 4 personnes', false)
            ->assertSee('Les quantités citées dans les étapes sont celles de la recette d\'origine', false);
    }

    public function test_qui_mange_invites_et_repas(): void
    {
        // Marina seule + 1 adulte invité → 2 parts
        $serving = $this->serving(['ajuste' => 1, 'qui' => [$this->marina->id], 'adultes' => 1]);
        $this->assertSame([2.0, 0.5], [$serving->perMeal, $serving->factor]);
        $this->assertSame('Marina + 1 adulte invité', $serving->detailLabel());

        // Tout le foyer + 2 enfants invités, 2 repas → (2,5 + 1,2) × 2 = 7,4 parts
        $serving = $this->serving(['ajuste' => 1, 'qui' => [$this->tobi->id, $this->marina->id], 'enfants' => 2, 'repas' => 2]);
        $this->assertSame([3.7, 2, 7.4], [$serving->perMeal, $serving->meals, $serving->total]);
        $this->assertSame('Tobilianok, Marina + 2 enfants invités · 2 repas de 3,7 parts', $serving->detailLabel());

        $page = $this->actingAs($this->louis)->get("/recettes/{$this->crepes->slug}?ajuste=1&qui[]={$this->tobi->id}&qui[]={$this->marina->id}&repas=2")->assertOk();
        $page->assertSee('Pour 5 parts')->assertSee('4 pièces')->assertSee('315 g')->assertSee('25 cl');
    }

    public function test_reglage_libre_et_garde_fous(): void
    {
        $this->assertSame(6.0, $this->serving(['parts' => '6'])->total, 'Parts saisies à la main');
        $this->assertSame(2.5, $this->serving(['parts' => '2,4'])->perMeal, 'Arrondi au ½');
        $this->assertSame(2.5, $this->serving(['parts' => 'abc'])->perMeal, 'Saisie invalide ignorée');

        $serving = $this->serving(['repas' => '99', 'adultes' => '-3', 'enfants' => '500']);
        $this->assertSame([4, 0, RecipeServing::MAX_GUESTS], [$serving->meals, $serving->adults, $serving->children]);

        // Personne de coché : recette d'origine, avec un avertissement
        $serving = $this->serving(['ajuste' => 1]);
        $this->assertSame([4.0, 1.0], [$serving->total, $serving->factor]);
        $this->assertStringContainsString('Personne n\'est coché', $serving->warnings[0]);

        // Quantités multipliées : conseil sur la cuisson
        $serving = $this->serving(['repas' => 4]);
        $this->assertSame(10.0, $serving->total);
        $this->assertStringContainsString('plat plus grand', $serving->warnings[0]);
    }

    public function test_cout_proratise(): void
    {
        $recipe = $this->crepes->fresh()->load('ingredients.ingredient.packs.prices');
        $base = RecipeCost::compute($recipe);
        $scaled = RecipeCost::compute($recipe, 0.625);

        $this->assertTrue($base['complete']);
        $this->assertEqualsWithDelta($base['total_cents'] * 0.625, $scaled['total_cents'], 0.01);

        $this->actingAs($this->louis)->get("/recettes/{$this->crepes->slug}")
            ->assertSee(\App\Models\Price::formatCents($scaled['total_cents']))->assertSee('/ part');
    }

    public function test_fournee_de_yaourts(): void
    {
        $yaourts = Recipe::firstWhere('title', 'Yaourts nature à la yaourtière');
        $this->assertSame('pots', $yaourts->yield_unit);

        $serving = RecipeServing::for($yaourts, $this->louis->household, []);
        $this->assertSame([RecipeServing::MODE_BATCH, 8.0, 1.0], [$serving->mode, $serving->total, $serving->factor], 'Indépendant du foyer');
        $this->assertSame(['½' => 4.0, '1' => 8.0, '2' => 16.0, '3' => 24.0], $serving->batchChoices());

        $this->assertSame(2.0, RecipeServing::for($yaourts, $this->louis->household, ['quantite' => '16'])->factor);
        $this->assertSame(160.0, RecipeServing::for($yaourts, $this->louis->household, ['quantite' => '1000'])->total, 'Plafonné à 20 fournées');

        $this->actingAs($this->louis)->get("/recettes/{$yaourts->slug}?quantite=16")->assertOk()
            ->assertSee('Pour 16 pots')->assertSee('recette d\'origine : 8 pots')->assertSee('Quantité à préparer')->assertDontSee('Qui mange');
    }

    public function test_recette_telle_quelle_quantites_saisies(): void
    {
        // Facteur 1 (4 parts demandées) : quantités affichées exactement comme saisies
        $this->actingAs($this->louis)->get("/recettes/{$this->crepes->slug}?parts=4")->assertOk()
            ->assertSee('3 pièces')->assertSee('250 g')->assertSee('20 cl')->assertDontSee('calcul exact')
            ->assertDontSee('Les quantités citées dans les étapes');
    }
}
