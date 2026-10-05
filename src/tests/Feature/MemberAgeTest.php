<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Ingredient;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\User;
use App\Support\RecipeServing;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** v0.10.0 : date de naissance des membres, coefficient de portion qui suit l'âge à la date de chaque repas. */
class MemberAgeTest extends TestCase
{
    use RefreshDatabase;

    private User $louis;

    private function member(array $attributes): HouseholdMember
    {
        return $this->louis->household->members()->create($attributes + ['position' => 50]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 5, 10, 0, 0, 'Europe/Paris'));
        $this->louis = $this->householdUser();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_grille_des_coefficients(): void
    {
        $expected = [[0, 0.0], [0, 0.0], [1, 0.3], [2, 0.3], [3, 0.5], [4, 0.5], [5, 0.7], [11, 0.7], [12, 0.8], [14, 0.8], [15, 1.0], [40, 1.0]];
        foreach ($expected as [$years, $coefficient]) {
            $this->assertSame($coefficient, HouseholdMember::gridFor($years)[1], "{$years} ans");
        }
    }

    public function test_le_coefficient_change_le_jour_de_l_anniversaire(): void
    {
        $enfant = $this->member(['name' => 'Léa', 'category' => 'enfant', 'birth_date' => '2023-10-20', 'portion_coefficient' => 0.3, 'coefficient_manual' => false]);

        $this->assertSame(2, $enfant->ageOn(Carbon::create(2026, 10, 19)));
        $this->assertSame(0.3, $enfant->coefficientOn(Carbon::create(2026, 10, 19)));
        $this->assertSame(3, $enfant->ageOn(Carbon::create(2026, 10, 20)));
        $this->assertSame(0.5, $enfant->coefficientOn(Carbon::create(2026, 10, 20)));
        $this->assertSame(0.7, $enfant->coefficientOn(Carbon::create(2028, 10, 20)));
        $this->assertSame(0.3, $enfant->coefficientOn(), 'Aujourd\'hui (5 octobre 2026) : 2 ans');
        $this->assertSame('tout-petit', $enfant->categoryOn(Carbon::create(2026, 10, 19)));
        $this->assertSame('enfant', $enfant->categoryOn(Carbon::create(2026, 10, 20)));
    }

    public function test_libelles_d_age(): void
    {
        $this->assertSame('8 mois', $this->member(['name' => 'A', 'category' => 'tout-petit', 'birth_date' => '2026-02-01', 'portion_coefficient' => 0])->ageLabel());
        $this->assertSame('23 mois', $this->member(['name' => 'B', 'category' => 'tout-petit', 'birth_date' => '2024-10-10', 'portion_coefficient' => 0])->ageLabel());
        $this->assertSame('7 ans', $this->member(['name' => 'C', 'category' => 'enfant', 'birth_date' => '2019-03-15', 'portion_coefficient' => 0.7])->ageLabel());
        $this->assertNull($this->member(['name' => 'D', 'category' => 'adulte', 'portion_coefficient' => 1])->ageLabel());
    }

    public function test_reglage_manuel_et_sans_date_de_naissance(): void
    {
        $gros = $this->member(['name' => 'Gros mangeur', 'category' => 'adulte', 'birth_date' => '1985-05-05', 'portion_coefficient' => 1.5, 'coefficient_manual' => true]);
        $this->assertSame(1.5, $gros->coefficientOn());

        $sans = $this->member(['name' => 'Sans date', 'category' => 'enfant', 'portion_coefficient' => 0.6]);
        $this->assertSame(0.6, $sans->coefficientOn(Carbon::create(2030, 1, 1)), 'Sans date de naissance : valeur enregistrée, fixe');
    }

    public function test_valeurs_enregistrees_d_apres_le_formulaire(): void
    {
        // Sans date : coefficient saisi (1 par défaut), fixe
        $this->assertSame(['birth_date' => null, 'portion_coefficient' => 1.0, 'coefficient_manual' => true, 'category' => 'adulte'], HouseholdMember::attributesFromInput(null, null, false));
        $this->assertSame(1.5, HouseholdMember::attributesFromInput('', '1.5', false)['portion_coefficient']);

        // Avec date : automatique, le coefficient saisi est ignoré
        $auto = HouseholdMember::attributesFromInput('2023-10-20', '1.2', false);
        $this->assertSame([0.3, false, 'tout-petit', '2023-10-20'], [$auto['portion_coefficient'], $auto['coefficient_manual'], $auto['category'], $auto['birth_date']]);

        // Avec date et « à la main »
        $manual = HouseholdMember::attributesFromInput('1985-05-05', '1.5', true);
        $this->assertSame([1.5, true, 'adulte'], [$manual['portion_coefficient'], $manual['coefficient_manual'], $manual['category']]);

        // « À la main » sans coefficient : retour à l'automatique
        $this->assertFalse(HouseholdMember::attributesFromInput('1985-05-05', null, true)['coefficient_manual']);
    }

    public function test_formulaires_mon_foyer(): void
    {
        $this->actingAs($this->louis);

        $this->post('/foyer/membres', ['name' => 'Léa', 'birth_date' => '2023-10-20', 'coefficient' => '1', 'coefficient_manual' => '0'])->assertSessionHasNoErrors();
        $lea = HouseholdMember::firstWhere('name', 'Léa');
        $this->assertSame([0.3, false, 'tout-petit'], [$lea->portion_coefficient, $lea->coefficient_manual, $lea->category]);

        $this->post('/foyer/membres', ['name' => 'Papa', 'coefficient' => '1.5'])->assertSessionHasNoErrors();
        $papa = HouseholdMember::firstWhere('name', 'Papa');
        $this->assertSame([1.5, null], [$papa->portion_coefficient, $papa->birth_date]);

        // Papa renseigne sa date de naissance et garde 1,5 à la main
        $this->put("/foyer/membres/{$papa->id}", ['name' => 'Papa', 'birth_date' => '1985-05-05', 'coefficient' => '1.5', 'coefficient_manual' => '1'])->assertSessionHasNoErrors();
        $this->assertSame([1.5, true, '1985-05-05'], [$papa->fresh()->coefficientOn(), $papa->fresh()->coefficient_manual, $papa->fresh()->birth_date->toDateString()]);

        // Date impossible (futur, format)
        $this->post('/foyer/membres', ['name' => 'Futur', 'birth_date' => '2027-01-01', 'coefficient' => '1'])->assertSessionHasErrors('birth_date');
        $this->post('/foyer/membres', ['name' => 'Futur', 'birth_date' => '05/05/1985', 'coefficient' => '1'])->assertSessionHasErrors('birth_date');

        // La page affiche l'âge et la note
        $this->get('/foyer')->assertOk()->assertSee("2 ans aujourd'hui", false)->assertSee('plat simple à part')->assertSee('moins de 1 an : 0')->assertSee('15 ans et plus : 1');
    }

    public function test_assistant_de_premiere_connexion(): void
    {
        $newcomer = User::factory()->create(['authentik_sub' => fake()->uuid(), 'username' => 'nouveau']);

        $this->actingAs($newcomer)->post('/bienvenue', [
            'name' => 'Famille Neuve', 'budget' => 100,
            'members' => [
                1 => ['name' => 'Papa', 'coefficient' => '1.5'],
                2 => ['name' => 'Léo', 'birth_date' => '2018-02-01', 'coefficient' => '1'],
            ],
            'me' => 1,
        ])->assertSessionHasNoErrors();

        $household = Household::firstWhere('name', 'Famille Neuve');
        $this->assertSame(1.5, $household->members()->firstWhere('name', 'Papa')->coefficientOn());
        $leo = $household->members()->firstWhere('name', 'Léo');
        $this->assertSame([0.7, 'enfant'], [$leo->coefficientOn(), $leo->category]);
        $this->assertSame(2.2, round($household->fresh()->load('members')->totalPortions(), 2));
    }

    public function test_les_portions_suivent_l_age_a_la_date_du_repas(): void
    {
        ReferenceImporter::import();
        $household = $this->louis->household;
        $household->members()->create(['name' => 'Adulte', 'category' => 'adulte', 'portion_coefficient' => 1, 'position' => 10]);
        $household->members()->create(['name' => 'Léa', 'category' => 'tout-petit', 'birth_date' => '2023-10-20', 'portion_coefficient' => 0.3, 'coefficient_manual' => false, 'position' => 20]);
        $household->load('members');

        $recipe = Recipe::create(['title' => 'Plat', 'slug' => 'plat', 'category' => 'plat', 'yield_quantity' => 4, 'yield_unit' => 'personnes', 'status' => Recipe::STATUS_PUBLISHED, 'author_id' => $this->louis->id]);
        $recipe->ingredients()->create(['position' => 0, 'ingredient_id' => Ingredient::firstWhere('slug', 'carotte')->id, 'quantity' => 400, 'unit' => 'g']);

        // Avant et après les 3 ans de Léa
        $before = RecipeServing::for($recipe, $household, [], Carbon::create(2026, 10, 19));
        $after = RecipeServing::for($recipe, $household, [], Carbon::create(2026, 10, 20));
        $this->assertSame([1.3, 1.5], [$before->perMeal, $after->perMeal]);

        // Le planning utilise la date du repas
        foreach (['2026-10-19', '2026-10-20'] as $date) {
            MealPlanEntry::create(['household_id' => $household->id, 'date' => $date, 'slot' => 'diner', 'position' => 0, 'kind' => 'recette', 'recipe_id' => $recipe->id, 'meals' => 1]);
        }
        [$first, $second] = MealPlanEntry::with('recipe.ingredients.ingredient')->orderBy('date')->get()->map(fn ($e) => $e->setRelation('household', $household))->all();
        $this->assertSame([1.3, 1.5], [$first->serving($household)->perMeal, $second->serving($household)->perMeal]);

        // Aujourd'hui (5 octobre 2026) : Léa a 2 ans, 0,3
        $this->assertSame(1.3, round($household->totalPortions(), 2));
        $this->assertSame(1.5, round($household->totalPortions(Carbon::create(2026, 11, 1)), 2));

        // La page de planning affiche le coefficient du jour du repas
        $this->actingAs($this->louis)->get('/planning/repas/nouveau?date=2026-10-20')->assertOk()->assertSee('Léa');
    }
}
