<?php

namespace Tests\Feature;

use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\User;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.17.0 : corrections faites dans une fenêtre, sans quitter la recette (réponses JSON) : créer un ingrédient,
 * retenir une équivalence d'unité, un poids de pièce ou une densité.
 */
class IngredientQuickTest extends TestCase
{
    use RefreshDatabase;

    private User $louis;

    protected function setUp(): void
    {
        parent::setUp();
        ReferenceImporter::import();
        $this->louis = $this->householdUser();
    }

    public function test_creer_un_ingredient_depuis_la_fenetre(): void
    {
        $aisle = Aisle::firstWhere('slug', 'fruits-legumes');

        $this->actingAs($this->louis)->postJson(route('ingredients.quick.store'), ['name' => 'radis', 'aisle_id' => $aisle->id, 'base_unit' => 'g'])
            ->assertCreated()
            ->assertJson(['name' => 'Radis', 'slug' => 'radis', 'base' => 'g', 'created' => true]);

        $radis = Ingredient::firstWhere('slug', 'radis');
        $this->assertSame([$aisle->id, $this->louis->id, true], [$radis->aisle_id, $radis->created_by, $radis->is_fresh]);

        // Déjà présent : repris tel quel, sans doublon
        $this->postJson(route('ingredients.quick.store'), ['name' => 'Radis', 'aisle_id' => $aisle->id, 'base_unit' => 'piece'])
            ->assertOk()->assertJson(['created' => false, 'base' => 'g']);
        $this->assertSame(1, Ingredient::where('slug', 'radis')->count());

        // Unités courantes pré-remplies à la création (herbes : sachet, botte…)
        $this->postJson(route('ingredients.quick.store'), ['name' => 'Coriandre thaïe', 'aisle_id' => $aisle->id, 'base_unit' => 'g'])
            ->assertCreated()->assertJsonPath('units.u:sachet', 'sachet');
    }

    public function test_saisie_refusee_et_acces_reserve(): void
    {
        $this->postJson(route('ingredients.quick.store'), ['name' => 'Radis'])->assertUnauthorized();

        $this->actingAs($this->louis)->postJson(route('ingredients.quick.store'), ['name' => '', 'aisle_id' => 999, 'base_unit' => 'litre'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'aisle_id', 'base_unit']);
        $this->postJson(route('ingredients.quick.unit', Ingredient::firstWhere('name', 'Ail')), ['word' => 'gousse', 'quantity' => 'beaucoup'])
            ->assertUnprocessable();
    }

    public function test_equivalence_d_unite_retenue(): void
    {
        $ail = Ingredient::firstWhere('name', 'Ail');

        $this->actingAs($this->louis)->postJson(route('ingredients.quick.unit', $ail), ['word' => 'Gousses', 'quantity' => '6'])
            ->assertOk()->assertJson(['unit' => 'u:gousses']);
        $this->postJson(route('ingredients.quick.unit', $ail), ['word' => 'gousse', 'quantity' => '6,5'])
            ->assertOk()->assertJson(['unit' => 'u:gousse', 'message' => '1 gousse = 6,5 g : retenu pour toutes les recettes.']);

        $gousse = $ail->fresh()->unitBySlug('gousse');
        $this->assertSame([6.5, false], [$gousse->quantity, $gousse->is_estimate], 'La valeur typique devient la valeur de Louis');
    }

    public function test_poids_d_une_piece_et_densite(): void
    {
        $aisle = Aisle::firstWhere('slug', 'fruits-legumes');
        $this->actingAs($this->louis)->postJson(route('ingredients.quick.store'), ['name' => 'Grenailles', 'aisle_id' => $aisle->id, 'base_unit' => 'g'])->assertCreated();
        $grenailles = Ingredient::firstWhere('slug', 'grenailles');

        $this->postJson(route('ingredients.quick.measure', $grenailles), ['kind' => 'piece', 'quantity' => '35'])->assertOk()->assertJson(['piece' => 35]);
        $this->postJson(route('ingredients.quick.measure', $grenailles), ['kind' => 'density', 'quantity' => '12', 'unit' => 'cas'])->assertOk();
        $this->assertSame([35.0, 0.8], [$grenailles->fresh()->piece_weight_g, $grenailles->fresh()->density]);
    }

    public function test_formulaire_de_recette_avec_la_fenetre_et_le_catalogue(): void
    {
        $this->actingAs($this->louis)->get('/recettes/nouvelle')->assertOk()
            ->assertSee('id="fix-dialog"', false)
            ->assertSee('id="ingredient-catalog"', false)
            ->assertSee('name="csrf-token"', false)
            ->assertSee('ingredients\/rapide', false);
    }
}
