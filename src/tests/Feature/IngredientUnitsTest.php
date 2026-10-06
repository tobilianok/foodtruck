<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\User;
use App\Support\ReferenceImporter;
use App\Support\RecipeScan\ScanImporter;
use App\Support\Units;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * v0.16.0 : plusieurs unités par ingrédient. L'unité de base (g, ml, pièce) reste la référence ; « sachet », « gousse »,
 * « boîte »… sont des unités propres de l'ingrédient, converties automatiquement. Une fiche importée n'a plus besoin
 * que d'une validation finale : seule une unité vraiment inconnue pose UNE question, retenue pour la suite.
 */
class IngredientUnitsTest extends TestCase
{
    use RefreshDatabase;

    private User $louis;

    protected function setUp(): void
    {
        parent::setUp();
        ReferenceImporter::import();
        $this->louis = $this->householdUser();
    }

    private function ingredient(string $name): Ingredient
    {
        return Ingredient::with('units')->where('name', $name)->firstOrFail();
    }

    private function crevettes(): Ingredient
    {
        return Ingredient::create(['name' => 'Crevettes', 'slug' => 'crevettes', 'aisle_id' => $this->ingredient('Ail')->aisle_id, 'base_unit' => 'g']);
    }

    public function test_unites_courantes_pre_remplies_avec_des_valeurs_typiques(): void
    {
        $ail = $this->ingredient('Ail');
        $this->assertSame([5.0, true], [$ail->unitBySlug('gousse')->quantity, $ail->unitBySlug('gousse')->is_estimate]);
        $this->assertSame(50.0, $ail->unitBySlug('tete')->quantity);
        $this->assertSame('1 tête = 50 g', $ail->unitBySlug('tete')->equivalence('g'));

        // Herbes comptées à la botte (1 pièce = 30 g) : 1 sachet de 10 g = ⅓ de botte
        $this->assertEqualsWithDelta(1 / 3, $this->ingredient('Coriandre')->unitBySlug('sachet')->quantity, 0.001);
        $this->assertSame(5.0, $this->ingredient('Gingembre frais')->unitBySlug('cm')->quantity);

        // Relançable sans rien écraser
        $this->ingredient('Ail')->unitBySlug('gousse')->update(['quantity' => 6, 'is_estimate' => false]);
        $this->assertSame(0, Artisan::call('foodtruck:unites'));
        $this->assertStringContainsString('Unités typiques ajoutées : 0', Artisan::output());
        $this->assertSame(6.0, $this->ingredient('Ail')->unitBySlug('gousse')->quantity);
    }

    public function test_unites_lues_sur_une_fiche_converties_sans_relecture(): void
    {
        $text = "Curry express\nPour 2 personnes\n\nIngrédients\n- 4 gousses d'ail\n- 1 boîte de lait de coco\n- 2 cm de gingembre frais\n- ½ sachet de coriandre\n- 3 brins de thym frais\n\nPréparation\n1. Tout faire revenir puis mijoter.";
        $analysis = (new ScanImporter)->analyse($text);
        $rows = collect($analysis['rows'])->keyBy('name');

        $this->assertSame([], collect($analysis['rows'])->whereNotNull('problem')->pluck('problem', 'name')->all(), 'Aucune ligne à corriger');
        $this->assertSame([4.0, 'u:gousse', null], [$rows['Ail']['quantity'], $rows['Ail']['unit'], $rows['Ail']['note']]);
        $this->assertSame([2.0, 'u:cm'], [$rows['Gingembre frais']['quantity'], $rows['Gingembre frais']['unit']]);
        $this->assertSame([0.5, 'u:sachet'], [$rows['Coriandre']['quantity'], $rows['Coriandre']['unit']]);
        $this->assertSame('u:brin', $rows['Thym frais']['unit']);

        // Boîte : d'après le conditionnement « Boîte 40 cl » de l'ingrédient, créée comme valeur typique
        $this->assertSame('u:boite', $rows['Lait de coco']['unit']);
        $this->assertSame(400.0, $this->ingredient('Lait de coco')->unitBySlug('boite')->quantity);
        $this->assertStringContainsString('1 boîte = 40 cl', $rows['Lait de coco']['info']);
        $this->assertTrue($analysis['complete']);
    }

    public function test_unite_inconnue_une_seule_question_retenue_pour_les_fiches_suivantes(): void
    {
        $crevettes = $this->crevettes();
        $text = "Crevettes sautées\nPour 2 personnes\n\nIngrédients\n- 1 paquet de crevettes\n- 1 gousse d'ail\n\nPréparation\n1. Faire sauter.";

        $row = collect((new ScanImporter)->analyse($text)['rows'])->firstWhere('name', 'Crevettes');
        $this->assertSame(['kind' => 'unit', 'word' => 'paquet', 'label' => '1 paquet', 'base' => 'g'], $row['ask']);
        $this->assertStringContainsString('Combien vaut 1 paquet de « Crevettes »', $row['problem']);

        // La relecture : une seule case remplie (1 paquet = 200 g), puis validation
        $response = $this->actingAs($this->louis)->post('/recettes', [
            'title' => 'Crevettes sautées', 'category' => 'plat', 'yield_quantity' => '2', 'yield_unit' => 'personnes', 'difficulty' => 'facile',
            'ingredients' => [
                ['name' => 'Crevettes', 'label' => 'crevettes', 'quantity' => '1', 'unit' => 'piece', 'note' => 'paquet', 'ask_kind' => 'unit', 'ask_word' => 'paquet', 'ask_value' => '200'],
                ['name' => 'Ail', 'quantity' => '1', 'unit' => 'u:gousse'],
            ],
            'steps' => [['body' => 'Faire sauter.', 'equipment_id' => Equipment::firstWhere('slug', 'plaques')->id]],
        ]);
        $recipe = Recipe::firstWhere('title', 'Crevettes sautées');
        $response->assertRedirect(route('recipes.show', $recipe));

        $line = $recipe->ingredients()->with('ingredient.units')->get()->firstWhere('ingredient_id', $crevettes->id);
        $this->assertSame(['u:paquet', null, 200.0], [$line->unit, $line->note, $line->baseQuantity()]);
        $this->assertSame([200.0, false], [$crevettes->fresh()->unitBySlug('paquet')->quantity, $crevettes->fresh()->unitBySlug('paquet')->is_estimate]);

        // Fiche suivante : plus aucune question
        $next = collect((new ScanImporter)->analyse($text)['rows'])->firstWhere('name', 'Crevettes');
        $this->assertSame(['u:paquet', null, null], [$next['unit'], $next['ask'], $next['problem']]);

        // Affichage : l'unité de la fiche, avec l'équivalent en petit
        $this->get(route('recipes.show', $recipe))->assertOk()->assertSee('1 paquet')->assertSee('≈ 200 g')->assertSee('1 gousse')->assertSee('≈ 5 g');
    }

    public function test_poids_d_une_piece_demande_une_fois_et_retenu(): void
    {
        $crevettes = $this->crevettes();
        $row = collect((new ScanImporter)->analyse("Test\nPour 2 personnes\n\nIngrédients\n- 2 crevettes\n\nPréparation\n1. Cuire.")['rows'])->first();
        $this->assertSame('piece', $row['ask']['kind']);
        $this->assertStringContainsString('Combien pèse 1 pièce de « Crevettes »', $row['problem']);

        $this->actingAs($this->louis)->post('/recettes', [
            'title' => 'Deux crevettes', 'category' => 'plat', 'yield_quantity' => '2', 'yield_unit' => 'personnes', 'difficulty' => 'facile',
            'ingredients' => [['name' => 'Crevettes', 'quantity' => '2', 'unit' => 'piece', 'ask_kind' => 'piece', 'ask_word' => 'piece', 'ask_value' => '15']],
            'steps' => [['body' => 'Cuire.']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(15.0, $crevettes->fresh()->piece_weight_g);
        $this->assertSame(30.0, Recipe::firstWhere('title', 'Deux crevettes')->ingredients()->first()->baseQuantity());
    }

    public function test_page_ingredient_unites_modifiables_et_protegees(): void
    {
        $ail = $this->ingredient('Ail');
        $gousse = $ail->unitBySlug('gousse');

        $this->actingAs($this->louis)->get(route('ingredients.show', $ail))->assertOk()
            ->assertSee('Unités de mesure')->assertSee('valeur typique')->assertSee('gousse');

        $this->put(route('ingredients.units.update', [$ail, $gousse]), ['name' => 'gousse', 'plural' => 'gousses', 'quantity' => '6'])->assertSessionHasNoErrors();
        $this->assertSame([6.0, false], [$gousse->fresh()->quantity, $gousse->fresh()->is_estimate]);

        $this->post(route('ingredients.units.store', $ail), ['name' => 'Gousse', 'quantity' => '4'])->assertSessionHasErrors('name');
        $this->post(route('ingredients.units.store', $ail), ['name' => 'Bocal', 'plural' => 'bocaux', 'quantity' => '120,5'])->assertSessionHasNoErrors();
        $this->assertSame(120.5, $ail->fresh()->unitBySlug('bocal')->quantity);

        // Utilisée par une recette : pas de suppression (la recette perdrait sa quantité)
        $recipe = Recipe::create(['title' => 'Aïoli', 'slug' => 'aioli', 'category' => 'sauce', 'yield_quantity' => 4, 'yield_unit' => 'personnes', 'difficulty' => 'facile', 'status' => 'publie', 'author_id' => $this->louis->id]);
        $recipe->ingredients()->create(['ingredient_id' => $ail->id, 'quantity' => 3, 'unit' => 'u:gousse', 'position' => 10]);
        $this->delete(route('ingredients.units.destroy', [$ail, $gousse]))->assertSessionHasErrors('unit');
        $this->delete(route('ingredients.units.destroy', [$ail, $ail->fresh()->unitBySlug('bocal')]))->assertSessionHasNoErrors();
        $this->assertNull($ail->fresh()->unitBySlug('bocal'));
    }

    public function test_quantites_proratisees_en_fractions(): void
    {
        $coriandre = $this->ingredient('Coriandre');

        $this->assertSame('½ sachet', Units::quantityLabel(0.5, 'u:sachet', $coriandre));
        $this->assertSame('1 ½ sachets', Units::scaledLabel(Units::practical(0.5 * 3, 'u:sachet'), 'u:sachet', $coriandre));
        $this->assertSame('¾ sachet', Units::scaledLabel(Units::practical(0.5 * 1.5, 'u:sachet'), 'u:sachet', $coriandre));
        $this->assertTrue(Units::validCode('u:sachet'));
        $this->assertFalse(Units::validCode('u:Sachet!'));
    }
}
