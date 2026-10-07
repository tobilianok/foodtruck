<?php

namespace Tests\Feature;

use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\User;
use App\Support\ReferenceImporter;
use App\Support\UnitConversionException;
use App\Support\Units;
use App\Support\WeekFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.20.1 (signalé par Louis) : « 15 ml d'Oasis Tropical » refusé (« Densité inconnue… ») alors que l'ingrédient se
 * compte à la bouteille : la contenance d'une pièce se donne dans la fenêtre de la recette ou dans la fiche ; un
 * conditionnement « Bouteille 2L = 2 000 pièces » est refusé ; « Choisir mes repas » mène au planning.
 */
class PieceVolumeTest extends TestCase
{
    use RefreshDatabase;

    private User $louis;

    private Ingredient $oasis;

    protected function setUp(): void
    {
        parent::setUp();
        ReferenceImporter::import();
        $this->louis = $this->householdUser();
        $this->oasis = Ingredient::create(['name' => 'Oasis Tropical', 'slug' => 'oasis-tropical', 'aisle_id' => Aisle::firstWhere('slug', 'boissons')->id, 'base_unit' => 'piece']);
        $this->oasis->packs()->create(['label' => 'Pièce (ticket)', 'quantity' => 1, 'position' => 10]);
    }

    private function recipe(): array
    {
        return [
            'title' => 'P\'tit Dej\' Romy', 'category' => 'petit-dejeuner', 'yield_quantity' => '1', 'yield_unit' => 'personnes',
            'prep_minutes' => '1', 'difficulty' => 'facile', 'protein' => 'aucune', 'tags' => [], 'equipment' => [],
            'ingredients' => [['name' => 'Oasis Tropical', 'quantity' => '15', 'unit' => 'ml']],
            'steps' => [['body' => 'Verser dans un verre.']],
        ];
    }

    public function test_quinze_ml_d_une_boisson_comptee_a_la_bouteille(): void
    {
        try {
            Units::toBase(15, 'ml', $this->oasis);
            $this->fail('Conversion impossible attendue');
        } catch (UnitConversionException $e) {
            $this->assertStringContainsString('Contenance d\'une pièce inconnue pour « Oasis Tropical » (1 pièce = combien de ml ?)', $e->getMessage());
        }

        $this->actingAs($this->louis)->post('/recettes', $this->recipe())->assertSessionHasErrors('ingredients');
        $this->assertStringContainsString('Indiquer l\'équivalence', implode(' ', session('errors')->get('ingredients')));

        // Fenêtre « Indiquer l'équivalence » : 1 pièce = 2 000 ml (densité de l'eau supposée)
        $this->postJson(route('ingredients.quick.measure', $this->oasis), ['kind' => 'contains', 'quantity' => '2000'])
            ->assertOk()->assertJsonPath('piece', 2000)->assertJsonPath('density', 1)
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, '2 000 ml : retenu') && str_contains($m, 'Densité de l\'eau supposée'))->etc());
        $this->assertEqualsWithDelta(0.0075, Units::toBase(15, 'ml', $this->oasis->fresh()), 1e-9);

        $this->post('/recettes', $this->recipe())->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Recipe::where('title', 'P\'tit Dej\' Romy')->count());
    }

    public function test_contenance_dans_la_fiche_et_conditionnement_aberrant(): void
    {
        $this->actingAs($this->louis)->put('/ingredients/oasis-tropical', [
            'name' => 'Oasis Tropical', 'aisle_id' => $this->oasis->aisle_id, 'base_unit' => 'piece', 'piece_volume_ml' => '2000',
        ])->assertSessionHasNoErrors();
        $this->oasis->refresh();
        $this->assertEquals([2000, 1], [$this->oasis->piece_weight_g, $this->oasis->density]);
        $this->get('/ingredients/oasis-tropical')->assertSee('Actuellement : 1 pièce ≈ 2 000 ml', false);

        // « Bouteille 2L = 2 000 pièces » : refusé avec l'explication ; 1 pièce : accepté
        $this->post('/ingredients/oasis-tropical/conditionnements', ['label' => 'Bouteille 2L', 'quantity' => '2000'])->assertSessionHasErrors('quantity');
        $this->assertStringContainsString('se compte à la pièce', session('errors')->first('quantity'));
        $this->post('/ingredients/oasis-tropical/conditionnements', ['label' => 'Bouteille 2L', 'quantity' => '1'])->assertSessionHasNoErrors();
    }

    public function test_choisir_mes_repas_mene_au_planning(): void
    {
        $this->actingAs($this->louis)->get('/')->assertSee('Choisir mes repas');
        $this->assertSame(route('planning.index'), WeekFlow::for($this->louis->household)->url);
    }
}
