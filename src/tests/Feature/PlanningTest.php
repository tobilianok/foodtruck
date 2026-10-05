<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\User;
use App\Support\MealPlanner;
use App\Support\RecipeCost;
use App\Support\RecipeImporter;
use App\Support\RecipeServing;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** v0.7.0 : planning de la semaine, restes, hors maison, budget. */
class PlanningTest extends TestCase
{
    use RefreshDatabase;

    private User $louis;

    private Recipe $hachis;

    private Recipe $curry;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 9, 29, 10, 0, 0, 'Europe/Paris')); // un mardi
        ReferenceImporter::import();
        RecipeImporter::import();

        $this->louis = $this->householdUser();
        $household = $this->louis->household;
        $household->members()->create(['name' => 'Tobilianok', 'category' => 'adulte', 'portion_coefficient' => 1.5, 'user_id' => $this->louis->id, 'position' => 10]);
        $household->members()->create(['name' => 'Marina', 'category' => 'adulte', 'portion_coefficient' => 1, 'position' => 20]);

        $this->hachis = Recipe::firstWhere('slug', 'hachis-parmentier-aux-legumes-caches');
        $this->curry = Recipe::firstWhere('slug', 'curry-de-lentilles-corail-et-patate-douce');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function add(array $data, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->louis)->post('/planning/repas', $data + ['kind' => 'recette']);
    }

    private function entries(): \Illuminate\Support\Collection
    {
        return MealPlanEntry::orderBy('date')->orderBy('id')->get()
            ->map(fn ($e) => $e->date->format('D d').' '.$e->slot.' '.$e->kind.($e->is_frozen ? ' gelé' : ''));
    }

    public function test_semaine_du_lundi_au_dimanche(): void
    {
        $this->assertSame('2026-09-28', MealPlanner::weekStart()->toDateString());
        $this->assertSame('2026-09-28', MealPlanner::weekStart('2026-10-04')->toDateString(), 'Dimanche : semaine commencée le lundi');
        $this->assertSame('2026-09-28', MealPlanner::weekStart('pas-une-date')->toDateString());

        $this->actingAs($this->louis)->get('/planning')->assertOk()
            ->assertSee('Semaine du 28 septembre au 4 octobre 2026')
            ->assertSee('Mardi')->assertSee("aujourd'hui", false)
            ->assertSee('Déjeuner')->assertSee('Dîner')->assertSee('À préparer')->assertDontSee('Petit-déjeuner')
            ->assertSee('/planning/2026-10-05', false);

        $this->get('/planning/2026-10-01')->assertRedirect('/planning/2026-09-28');
        $this->get('/')->assertSee("Rien de prévu aujourd'hui", false);
    }

    public function test_plat_pour_deux_repas_restes_au_prochain_creneau_libre(): void
    {
        // Demain midi déjà pris : les restes du dîner de ce soir vont à demain soir
        $this->actingAs($this->louis)->post('/planning/repas', ['kind' => 'hors_maison', 'date' => '2026-09-30', 'slot' => 'dejeuner', 'note' => 'cantine'])->assertRedirect('/planning/2026-09-28');

        $this->add(['recipe_id' => $this->hachis->id, 'date' => '2026-09-29', 'slot' => 'diner', 'repas' => 2])
            ->assertRedirect('/planning/2026-09-28')
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Restes prévus : dîner du mercredi 30 septembre'));

        $this->assertSame(['Tue 29 diner recette', 'Wed 30 dejeuner hors_maison', 'Wed 30 diner restes'], $this->entries()->all());

        $cooked = MealPlanEntry::firstWhere('kind', 'recette');
        $this->assertSame([null, 2], [$cooked->eaters, $cooked->meals], 'Convives non précisés : tout le foyer');

        $page = $this->get('/planning')->assertOk();
        $page->assertSee('Hachis parmentier aux légumes cachés')->assertSee('restes du mar. soir')->assertSee('2,5 parts')->assertSee('cantine')
            ->assertSee('/recettes/hachis-parmentier-aux-legumes-caches?repas=2', false);

        // Accueil : repas du jour
        $this->get('/')->assertSee('Dîner')->assertSee('Hachis parmentier aux légumes cachés');
    }

    public function test_restes_du_dimanche_soir_la_semaine_suivante_et_regeneration(): void
    {
        $this->add(['recipe_id' => $this->curry->id, 'date' => '2026-10-04', 'slot' => 'diner', 'repas' => 3]);
        $this->assertSame(['Sun 04 diner recette', 'Mon 05 dejeuner restes', 'Mon 05 diner restes'], $this->entries()->all());
        $this->actingAs($this->louis)->get('/planning')->assertSee('Curry de lentilles corail', false)->assertDontSee('restes du dim.');
        $this->get('/planning/2026-10-05')->assertSee('restes du dim. soir');

        // Modification : 2 repas → un seul reste, replacé
        $entry = MealPlanEntry::firstWhere('kind', 'recette');
        $this->actingAs($this->louis)->put("/planning/repas/{$entry->id}", [
            'kind' => 'recette', 'recipe_id' => $this->curry->id, 'date' => '2026-10-04', 'slot' => 'diner', 'repas' => 2,
        ])->assertSessionHasNoErrors();
        $this->assertSame(['Sun 04 diner recette', 'Mon 05 dejeuner restes'], $this->entries()->all());

        // Restes déplacés, puis congelés, puis ressortis
        $leftover = MealPlanEntry::firstWhere('kind', 'restes');
        $this->put("/planning/repas/{$leftover->id}", ['date' => '2026-10-07', 'slot' => 'dejeuner'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-07', $leftover->fresh()->date->toDateString());

        $this->post("/planning/repas/{$leftover->id}/congeler")->assertRedirect('/planning/2026-10-05');
        $this->assertTrue($leftover->fresh()->is_frozen);
        $this->get('/planning/2026-10-05')->assertSee('Restes mis de côté')->assertSee('Planifier');

        $this->put("/planning/repas/{$leftover->id}", ['date' => '2026-10-09', 'slot' => 'diner'])->assertSessionHasNoErrors();
        $this->assertFalse($leftover->fresh()->is_frozen, 'Planifier ressort les restes du congélateur');

        // Suppression du plat : ses restes partent avec lui
        $this->delete("/planning/repas/{$entry->id}")->assertSessionHas('status');
        $this->assertSame(0, MealPlanEntry::count());
    }

    public function test_sans_creneau_libre_les_restes_sont_mis_de_cote(): void
    {
        $this->louis->household->update(['meal_slots' => ['preparation']]);

        $this->add(['recipe_id' => $this->hachis->id, 'date' => '2026-09-29', 'slot' => 'preparation', 'repas' => 3])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '2 repas de restes sans créneau libre'));

        $this->assertSame(2, MealPlanEntry::where('kind', 'restes')->where('is_frozen', true)->count());
        $this->actingAs($this->louis)->get('/planning')->assertSee('Restes mis de côté')->assertDontSee('Petit-déjeuner');
    }

    public function test_convives_invites_et_fournee(): void
    {
        $household = $this->louis->household->load('members');
        $marina = $household->members->firstWhere('name', 'Marina');

        $this->add(['recipe_id' => $this->curry->id, 'date' => '2026-09-30', 'slot' => 'dejeuner', 'ajuste' => 1, 'qui' => [$marina->id], 'adultes' => 1, 'enfants' => 1])
            ->assertSessionHasNoErrors();
        $entry = MealPlanEntry::first();
        $this->assertSame([[$marina->id], 1, 1], [$entry->eaters, $entry->guest_adults, $entry->guest_children]);
        $this->assertSame(2.6, $entry->serving($household)->perMeal, 'Marina 1 + adulte 1 + enfant 0,6');

        // Fournée de yaourts : quantité en pots, jamais de restes
        $yaourts = Recipe::firstWhere('title', 'Yaourts nature à la yaourtière');
        $this->add(['recipe_id' => $yaourts->id, 'date' => '2026-10-04', 'slot' => 'preparation', 'quantite' => 16, 'repas' => 3])->assertSessionHasNoErrors();
        $batch = MealPlanEntry::where('recipe_id', $yaourts->id)->first();
        $this->assertSame([16.0, 1, 0], [$batch->batch_quantity, $batch->meals, $batch->leftovers()->count()]);
        $this->assertSame('16 pots', $batch->partsLabel($household));
    }

    public function test_cout_de_la_semaine_restes_comptes_une_fois(): void
    {
        $this->add(['recipe_id' => $this->hachis->id, 'date' => '2026-09-29', 'slot' => 'diner', 'repas' => 2]);
        $this->add(['recipe_id' => $this->curry->id, 'date' => '2026-10-01', 'slot' => 'diner']);

        $household = $this->louis->household->fresh()->load('members');
        $entries = MealPlanEntry::with('recipe.ingredients.ingredient.packs.prices')->get();
        $cost = MealPlanner::cost($entries, $household);

        $expected = 0.0;
        foreach ([[$this->hachis, ['repas' => 2]], [$this->curry, []]] as [$recipe, $input]) {
            $recipe->load('ingredients.ingredient.packs.prices');
            $expected += RecipeCost::compute($recipe, RecipeServing::for($recipe, $household, $input)->factor)['total_cents'];
        }
        $this->assertEqualsWithDelta($expected, $cost['total_cents'], 0.01);
        $this->assertCount(2, $cost['per_entry'], 'Les restes ne sont pas recomptés');

        $this->actingAs($this->louis)->get('/planning')->assertSee('Coût estimé des repas : '.\App\Models\Price::formatCents($expected))->assertSee('sur un budget de 100,00');
    }

    public function test_hors_maison_note_et_validation(): void
    {
        $this->actingAs($this->louis)->post('/planning/repas', ['kind' => 'note', 'date' => '2026-09-30', 'slot' => 'diner'])->assertSessionHasErrors('note');
        $this->post('/planning/repas', ['kind' => 'recette', 'date' => '2026-09-30', 'slot' => 'diner'])->assertSessionHasErrors('recipe_id');
        $this->post('/planning/repas', ['kind' => 'recette', 'recipe_id' => $this->curry->id, 'date' => '2026-09-30', 'slot' => 'minuit'])->assertSessionHasErrors('slot');
        $this->post('/planning/repas', ['kind' => 'recette', 'recipe_id' => $this->curry->id, 'date' => '2030-01-01', 'slot' => 'diner'])->assertSessionHasErrors('date');

        $this->post('/planning/repas', ['kind' => 'note', 'date' => '2026-09-30', 'slot' => 'diner', 'note' => 'Pizza surgelée'])->assertSessionHasNoErrors();
        $this->get('/planning')->assertSee('Pizza surgelée');

        $this->get('/planning/repas/nouveau?date=2026-10-02&creneau=gouter&recette='.$this->curry->slug)->assertOk()
            ->assertSee('value="2026-10-02"', false)->assertSee('<option value="gouter" selected', false)
            ->assertSee('<option value="'.$this->curry->id.'" data-mode="parts"', false);
    }

    public function test_foyers_isoles_et_brouillons(): void
    {
        $this->add(['recipe_id' => $this->curry->id, 'date' => '2026-09-30', 'slot' => 'diner']);
        $entry = MealPlanEntry::first();

        $other = $this->householdUser(User::HOUSEHOLD_ADMIN, Household::create(['name' => 'Voisins']));
        $this->actingAs($other)->get("/planning/repas/{$entry->id}/modifier")->assertNotFound();
        $this->actingAs($other)->delete("/planning/repas/{$entry->id}")->assertNotFound();
        $this->actingAs($other)->get('/planning')->assertDontSee('Curry');

        // Le brouillon d'un autre compte n'est pas planifiable
        $draft = Recipe::create(['title' => 'Brouillon secret', 'slug' => 'brouillon-secret', 'category' => 'plat', 'yield_quantity' => 2, 'yield_unit' => 'personnes',
            'difficulty' => 'facile', 'status' => Recipe::STATUS_DRAFT, 'author_id' => $other->id]);
        $this->add(['recipe_id' => $draft->id, 'date' => '2026-09-30', 'slot' => 'dejeuner'])->assertSessionHasErrors('recipe_id');

        // Un membre (non admin) du foyer planifie aussi
        $member = $this->householdUser(User::HOUSEHOLD_MEMBER, $this->louis->household);
        $this->add(['recipe_id' => $this->hachis->id, 'date' => '2026-10-01', 'slot' => 'dejeuner'], $member)->assertSessionHasNoErrors();
    }

    public function test_depuis_la_fiche_recette(): void
    {
        $page = $this->actingAs($this->louis)->get("/recettes/{$this->curry->slug}?repas=2&adultes=1")->assertOk();
        $page->assertSee('Ajouter au planning')->assertSee('name="repas" value="2"', false)->assertSee('name="adultes" value="1"', false);

        // Formulaire envoyé tel quel (réglages de la fiche en champs cachés)
        $household = $this->louis->household->load('members');
        $this->add([
            'recipe_id' => $this->curry->id, 'date' => '2026-09-29', 'slot' => 'diner', 'ajuste' => 1,
            'qui' => $household->members->pluck('id')->all(), 'adultes' => 1, 'repas' => 2, 'parts' => '3.5',
        ])->assertSessionHasNoErrors();

        $entry = MealPlanEntry::firstWhere('kind', 'recette');
        $this->assertSame([null, 1, 2, 3.5], [$entry->eaters, $entry->guest_adults, $entry->meals, $entry->parts_manual]);
        $this->assertSame(1, $entry->leftovers()->count());

        // Convives non touchés sur la fiche : pas transmis, le planning suivra la semaine type
        $this->get("/recettes/{$this->curry->slug}?repas=2")->assertDontSee('<input type="hidden" name="qui[]"', false);
        $marina = $household->members->firstWhere('name', 'Marina')->id;
        $this->get("/recettes/{$this->curry->slug}?ajuste=1&qui[]={$marina}")->assertSee('<input type="hidden" name="qui[]" value="'.$marina.'">', false);
    }

    public function test_repas_du_planning_reglables_dans_mon_foyer(): void
    {
        $household = $this->louis->household;
        $this->actingAs($this->louis)->put('/foyer/reglages', [
            'name' => $household->name, 'budget' => 100, 'meal_slots' => ['dejeuner', 'diner'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['dejeuner', 'diner'], $household->fresh()->mealSlots());

        $this->get('/planning')->assertDontSee('Goûter')->assertSee('Déjeuner');

        // Tous les repas, goûter et petit-déjeuner compris
        $this->put('/foyer/reglages', ['name' => $household->name, 'budget' => 100, 'meal_slots' => array_keys(MealPlanEntry::SLOTS)])->assertSessionHasNoErrors();
        $this->assertSame(MealPlanEntry::slotCodes(), $household->fresh()->mealSlots());
        $this->get('/planning')->assertSee('Goûter')->assertSee('Petit-déjeuner');

        // Réglage absent du formulaire : inchangé ; foyer neuf : déjeuner, dîner, à préparer
        $this->put('/foyer/reglages', ['name' => $household->name, 'budget' => 100])->assertSessionHasNoErrors();
        $this->assertSame(MealPlanEntry::slotCodes(), $household->fresh()->mealSlots());
        $this->assertSame(['dejeuner', 'diner', 'preparation'], Household::create(['name' => 'Neuf'])->mealSlots());
    }

    public function test_semaine_type(): void
    {
        $household = $this->louis->household->load('members');
        $tobi = $household->members->firstWhere('name', 'Tobilianok')->id;
        $marina = $household->members->firstWhere('name', 'Marina')->id;

        // Tobilianok travaille en semaine le midi ; le mercredi midi, personne à la maison
        $presents = [];
        foreach (range(1, 7) as $weekday) {
            $presents['dejeuner'][$weekday] = $weekday <= 5 ? [$marina] : [$tobi, $marina];
            $presents['diner'][$weekday] = [$tobi, $marina];
        }
        $presents['dejeuner'][3] = [];

        $member = $this->householdUser(User::HOUSEHOLD_MEMBER, $household);
        $this->actingAs($member)->put('/foyer/semaine-type', ['presents' => $presents])->assertForbidden();

        $this->actingAs($this->louis)->put('/foyer/semaine-type', ['presents' => $presents])->assertRedirect()->assertSessionHas('status');
        $household = $household->fresh()->load('members');
        $this->assertSame(['dejeuner' => ['1' => [$tobi], '2' => [$tobi], '3' => [$tobi, $marina], '4' => [$tobi], '5' => [$tobi]]], $household->usual_absences, 'Seules les absences sont enregistrées');
        $this->assertSame([$marina], $household->usualEaters(Carbon::parse('2026-10-01'), 'dejeuner'));
        $this->assertSame([], $household->usualEaters(Carbon::parse('2026-09-30'), 'dejeuner'));
        $this->assertNull($household->usualEaters(Carbon::parse('2026-10-03'), 'dejeuner'), 'Samedi : tout le foyer');
        $this->assertNull($household->usualEaters(Carbon::parse('2026-10-01'), 'diner'));

        $this->get('/foyer')->assertSee('Semaine type')->assertSee('name="presents[dejeuner][1][]"', false);
        $this->get('/planning')->assertSee('👤 Marina')->assertSee('personne à la maison');

        // Restes du dîner de mardi (3 repas) : mercredi midi sauté (personne), puis mercredi soir et jeudi midi
        $this->add(['recipe_id' => $this->hachis->id, 'date' => '2026-09-29', 'slot' => 'diner', 'repas' => 3])->assertSessionHasNoErrors();
        $this->assertSame(['Tue 29 diner recette', 'Wed 30 diner restes', 'Thu 01 dejeuner restes'], $this->entries()->all());

        // Nouveau repas jeudi midi : Marina seule d'après la semaine type
        $this->get('/planning/repas/nouveau?date=2026-10-02&creneau=dejeuner')->assertOk()->assertSee("d'après la semaine type", false)
            ->assertSee('name="qui[]" value="'.$marina.'" checked', false)->assertDontSee('name="qui[]" value="'.$tobi.'" checked', false);

        $this->add(['recipe_id' => $this->curry->id, 'date' => '2026-10-02', 'slot' => 'dejeuner', 'ajuste' => 1, 'qui' => [$marina]])->assertSessionHasNoErrors();
        $friday = MealPlanEntry::whereDate('date', '2026-10-02')->first();
        $this->assertNull($friday->eaters, 'Identique à la semaine type : rien d\'enregistré');
        $this->assertSame(1.0, $friday->serving()->perMeal, 'Marina seule : 1 part');

        // Exception : Tobilianok en congé ce vendredi-là
        $this->put("/planning/repas/{$friday->id}", ['kind' => 'recette', 'recipe_id' => $this->curry->id, 'date' => '2026-10-02', 'slot' => 'dejeuner', 'ajuste' => 1, 'qui' => [$tobi, $marina]])->assertSessionHasNoErrors();
        $this->assertSame([$tobi, $marina], $friday->fresh()->eaters);
        $this->assertSame(2.5, $friday->fresh()->serving()->perMeal);
    }
}
