<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use App\Support\MenuGenerator;
use App\Support\WeekFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** v0.13.0 : menu automatique (semaine proposée, à garder, changer ou valider). */
class MenuAutomatiqueTest extends TestCase
{
    use RefreshDatabase;

    private User $louis;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 5, 10, 0, 0, 'Europe/Paris')); // un lundi

        $this->louis = $this->householdUser();
        $this->household = $this->louis->household;
        $this->household->members()->create(['name' => 'Tobilianok', 'category' => 'adulte', 'portion_coefficient' => 1.5, 'user_id' => $this->louis->id, 'position' => 10]);
        $this->household->members()->create(['name' => 'Marina', 'category' => 'adulte', 'portion_coefficient' => 1, 'position' => 20]);

        // 11 plats : de quoi remplir 8 plats cuisinés (lundi midi + 7 dîners) avec des protéines variées
        $this->recipe('Bœuf bourguignon', 'boeuf');
        $this->recipe('Chili con carne', 'boeuf');
        $this->recipe('Poulet rôti', 'volaille');
        $this->recipe('Blanc de poulet au citron', 'volaille');
        $this->recipe('Rôti de porc', 'porc');
        $this->recipe('Cabillaud au four', 'poisson');
        $this->recipe('Saumon en papillote', 'poisson');
        $this->recipe('Gratin de ravioles', 'laitages');
        $this->recipe('Ratatouille', 'aucune');
        $this->recipe('Curry de lentilles', 'legumineuses', true);
        $this->recipe('Omelette aux légumes', 'oeufs', true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function recipe(string $title, ?string $protein, bool $veggy = false): Recipe
    {
        $recipe = Recipe::create([
            'title' => $title, 'slug' => str($title)->slug()->toString(), 'category' => 'plat', 'yield_quantity' => 4, 'yield_unit' => 'personnes',
            'prep_minutes' => 10, 'cook_minutes' => 15, 'difficulty' => 'facile', 'protein' => $protein,
            'status' => Recipe::STATUS_PUBLISHED, 'author_id' => $this->louis->id,
        ]);

        if ($veggy) {
            $recipe->tags()->attach(Tag::firstWhere('slug', 'veggy')->id);
        }

        return $recipe;
    }

    private function week(): Carbon
    {
        return Carbon::create(2026, 10, 5, 0, 0, 0, 'Europe/Paris');
    }

    private function proposals(): \Illuminate\Support\Collection
    {
        return MealPlanEntry::whereNotNull('proposed_at')->orderBy('date')->orderBy('id')->get();
    }

    private function dishes(): \Illuminate\Support\Collection
    {
        return MealPlanEntry::where('kind', MealPlanEntry::KIND_RECIPE)->with('recipe')->get()->sortBy(fn ($e) => $e->sortKey())->values();
    }

    public function test_la_semaine_est_remplie_dejeuners_et_diners_restes_comptes(): void
    {
        $result = MenuGenerator::generate($this->household, $this->louis, $this->week(), 2, 42);

        // Lundi midi, puis un dîner par soir ; chaque dîner couvre le déjeuner du lendemain (sauf dimanche)
        $this->assertSame(8, $result['created']);
        $this->assertSame(0, $result['unfilled']);
        $this->assertFalse($result['no_recipes']);

        $this->assertSame(8, MealPlanEntry::where('kind', 'recette')->count());
        $this->assertSame(6, MealPlanEntry::where('kind', 'restes')->count());
        $this->assertSame(14, $this->proposals()->count(), 'Tout est une proposition');

        $slots = MealPlanEntry::where('is_frozen', false)->get()->map(fn ($e) => $e->date->toDateString().'|'.$e->slot);
        $this->assertSame($slots->count(), $slots->unique()->count(), 'Un seul plat par repas');
        $this->assertCount(14, $slots);

        // Les 8 plats sont tous différents
        $this->assertSame(8, $this->dishes()->pluck('recipe_id')->unique()->count());
    }

    public function test_les_repas_deja_prevus_sont_conserves_et_comptent(): void
    {
        $this->household->mealPlanEntries()->create([
            'date' => '2026-10-06', 'slot' => 'dejeuner', 'kind' => 'hors_maison', 'note' => 'cantine', 'created_by' => $this->louis->id,
        ]);
        $mine = Recipe::firstWhere('slug', 'poulet-roti');
        $kept = $this->household->mealPlanEntries()->create([
            'date' => '2026-10-07', 'slot' => 'diner', 'kind' => 'recette', 'recipe_id' => $mine->id, 'meals' => 1, 'created_by' => $this->louis->id,
        ]);

        MenuGenerator::generate($this->household, $this->louis, $this->week(), 2, 7);

        $this->assertSame('hors_maison', MealPlanEntry::whereDate('date', '2026-10-06')->where('slot', 'dejeuner')->sole()->kind);
        $this->assertNull($kept->fresh()->proposed_at);
        $this->assertSame($mine->id, $kept->fresh()->recipe_id);
        $this->assertSame(1, MealPlanEntry::where('recipe_id', $mine->id)->where('kind', 'recette')->count(), 'Le plat déjà prévu n\'est pas reproposé');

        $slots = MealPlanEntry::where('is_frozen', false)->get()->map(fn ($e) => $e->date->toDateString().'|'.$e->slot);
        $this->assertSame($slots->count(), $slots->unique()->count());
    }

    public function test_jamais_deux_fois_la_meme_proteine_de_suite(): void
    {
        foreach ([1, 2, 3, 99] as $seed) {
            MenuGenerator::generate($this->household, $this->louis, $this->week(), 2, $seed);

            $proteins = $this->dishes()->map(fn ($e) => $e->recipe->protein)->all();
            foreach ($proteins as $i => $protein) {
                if ($i > 0 && $protein !== 'aucune') {
                    $this->assertNotSame($proteins[$i - 1], $protein, 'Même protéine à la suite (graine '.$seed.') : '.implode(', ', $proteins));
                }
            }
        }
    }

    public function test_objectif_de_repas_vegetariens_atteint(): void
    {
        foreach ([1, 2, 3] as $seed) {
            MenuGenerator::generate($this->household, $this->louis, $this->week(), 2, $seed);

            $veggy = $this->dishes()->filter(fn ($e) => $e->recipe->tags()->where('slug', 'veggy')->exists())->count();
            $this->assertGreaterThanOrEqual(2, $veggy, 'Au moins 2 repas végétariens (graine '.$seed.')');
        }
    }

    public function test_une_proposition_n_entre_pas_dans_la_liste_de_courses_avant_validation(): void
    {
        $this->actingAs($this->louis)->post('/planning/menu/proposer', ['semaine' => '2026-10-05'])
            ->assertRedirect('/planning/2026-10-05')
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'proposés'));

        $this->assertSame(0, WeekFlow::for($this->household)->planned, 'Les propositions ne comptent pas comme repas planifiés');
        $this->assertSame(0, MealPlanEntry::confirmed()->count());

        $this->actingAs($this->louis)->post('/planning/menu/valider', ['semaine' => '2026-10-05'])
            ->assertRedirect('/planning/2026-10-05')
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Menu validé : 8 plats'));

        $this->assertSame(0, $this->proposals()->count());
        $this->assertSame(8, WeekFlow::for($this->household)->planned);
        $this->assertSame(14, MealPlanEntry::confirmed()->count(), 'Restes compris');
    }

    public function test_garder_autre_idee_et_les_restes_suivent(): void
    {
        $this->actingAs($this->louis)->post('/planning/menu/proposer', ['semaine' => '2026-10-05']);

        $monday = MealPlanEntry::where('kind', 'recette')->whereDate('date', '2026-10-05')->where('slot', 'diner')->sole();
        $tuesday = MealPlanEntry::where('kind', 'recette')->whereDate('date', '2026-10-06')->where('slot', 'diner')->sole();
        $mondayLeftover = $monday->leftovers()->sole();
        $tuesdayLeftover = $tuesday->leftovers()->sole();
        $this->assertNotNull($mondayLeftover->proposed_at, 'Les restes suivent la proposition');

        // Garder : le plat et ses restes deviennent de vrais repas
        $this->actingAs($this->louis)->post('/planning/repas/'.$monday->id.'/garder')
            ->assertRedirect('/planning/2026-10-05')
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'gardé'));
        $this->assertNull($monday->fresh()->proposed_at);
        $this->assertNull($mondayLeftover->fresh()->proposed_at);
        $this->assertNotNull($tuesday->fresh()->proposed_at, 'Les autres propositions ne bougent pas');

        // Autre idée : nouvelle recette, restes compris
        $before = $tuesday->recipe_id;
        $this->actingAs($this->louis)->post('/planning/repas/'.$tuesday->id.'/autre-idee')->assertRedirect('/planning/2026-10-05');

        $tuesday = $tuesday->fresh();
        $this->assertNotSame($before, $tuesday->recipe_id);
        $this->assertSame($tuesday->recipe_id, $tuesdayLeftover->fresh()->recipe_id);
        $this->assertNotNull($tuesday->proposed_at, 'Toujours une proposition');
        $this->assertSame(8, $this->dishes()->pluck('recipe_id')->unique()->count(), 'Pas de doublon dans la semaine');

        // Garder via les restes : renvoie au plat dont ils viennent
        $this->actingAs($this->louis)->post('/planning/repas/'.$tuesdayLeftover->id.'/garder')->assertRedirect('/planning/2026-10-05');
        $this->assertNull($tuesday->fresh()->proposed_at);
    }

    public function test_reproposer_garde_ce_qui_a_ete_garde_et_effacer_ne_touche_pas_aux_vrais_repas(): void
    {
        $this->actingAs($this->louis)->post('/planning/menu/proposer', ['semaine' => '2026-10-05']);

        $monday = MealPlanEntry::where('kind', 'recette')->whereDate('date', '2026-10-05')->where('slot', 'diner')->sole();
        $this->actingAs($this->louis)->post('/planning/repas/'.$monday->id.'/garder');

        $this->actingAs($this->louis)->post('/planning/menu/proposer', ['semaine' => '2026-10-05'])->assertRedirect('/planning/2026-10-05');

        $this->assertNull($monday->fresh()->proposed_at);
        $this->assertSame(2, MealPlanEntry::confirmed()->count(), 'Le plat gardé et ses restes');
        $this->assertSame(7, $this->proposals()->where('kind', 'recette')->count(), 'Lundi midi + 6 dîners');
        $this->assertSame(0, $this->proposals()->where('recipe_id', $monday->recipe_id)->count(), 'Le plat gardé n\'est pas reproposé');

        $this->actingAs($this->louis)->post('/planning/menu/effacer', ['semaine' => '2026-10-05'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Propositions effacées'));

        $this->assertSame(0, $this->proposals()->count());
        $this->assertSame(2, MealPlanEntry::count(), 'Les vrais repas restent');
    }

    public function test_ecran_du_planning_et_acces_reserve_au_foyer(): void
    {
        $this->actingAs($this->louis)->get('/planning')->assertOk()
            ->assertSee('Menu automatique')->assertSee('Proposer la semaine')->assertDontSee('Valider le menu');

        $this->post('/planning/menu/proposer', ['semaine' => '2026-10-05', 'vegetarien' => 3]);
        $this->assertSame(3, (int) $this->household->fresh()->menu_veggy_min, 'Le réglage est mémorisé');

        $this->get('/planning')->assertOk()
            ->assertSee('Menu proposé pour la semaine')->assertSee('Valider le menu')->assertSee('Garder')->assertSee('Autre idée')->assertSee('pour ce repas');

        // Un autre foyer ne peut ni garder ni changer ces plats
        $other = $this->householdUser(User::HOUSEHOLD_ADMIN, Household::create(['name' => 'Autre foyer', 'weekly_budget_cents' => 5000]));
        $entry = $this->proposals()->firstWhere('kind', 'recette');
        $this->actingAs($other)->post('/planning/repas/'.$entry->id.'/garder')->assertNotFound();
        $this->actingAs($other)->post('/planning/repas/'.$entry->id.'/autre-idee')->assertNotFound();

        // Un repas déjà confirmé n'est pas une proposition
        MenuGenerator::accept($this->household, $this->week());
        $this->actingAs($this->louis)->post('/planning/repas/'.$entry->id.'/autre-idee')->assertNotFound();
    }
}
