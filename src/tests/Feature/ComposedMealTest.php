<?php

namespace Tests\Feature;

use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\SavedMenu;
use App\Models\Tag;
use App\Models\User;
use App\Support\RecipeImporter;
use App\Support\ReferenceImporter;
use App\Support\ShoppingListBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** v0.22.0 : repas composés (plusieurs recettes par repas), menus enregistrés, étiquettes créées par le foyer. */
class ComposedMealTest extends TestCase
{
    use RefreshDatabase;

    private User $louis;

    private Recipe $saumon;

    private Recipe $gratin;

    private Recipe $salade;

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

        $this->saumon = Recipe::firstWhere('title', 'Saumon et fondue de poireaux');
        $this->gratin = Recipe::firstWhere('title', 'Gratin de courge butternut');
        $this->salade = Recipe::firstWhere('title', 'Salade de lentilles, betterave et feta');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dishes(string $date = '2026-09-30', string $slot = 'diner')
    {
        return MealPlanEntry::whereDate('date', $date)->where('slot', $slot)->where('kind', 'recette')->orderBy('position')->get();
    }

    public function test_un_repas_avec_plusieurs_recettes_pour_les_memes_convives(): void
    {
        $marina = $this->louis->household->members()->firstWhere('name', 'Marina');

        $this->actingAs($this->louis)->post('/planning/repas', [
            'kind' => 'recette', 'recipe_id' => $this->saumon->id, 'date' => '2026-09-30', 'slot' => 'diner',
            'ajuste' => 1, 'qui' => [$marina->id], 'adultes' => 1, 'avec' => [$this->gratin->id, $this->salade->id, $this->saumon->id],
        ])->assertRedirect('/planning/2026-09-28')
            ->assertSessionHas('status', fn ($s) => str_contains($s, '« Saumon et fondue de poireaux », « Gratin de courge butternut » et « Salade de lentilles, betterave et feta » ajoutés : dîner du mercredi 30 septembre'));

        $dishes = $this->dishes();
        $this->assertSame([$this->saumon->id, $this->gratin->id, $this->salade->id], $dishes->pluck('recipe_id')->all(), 'Ordre gardé, plat en double ignoré');
        foreach ($dishes as $dish) {
            $this->assertSame([[$marina->id], 1, 1], [$dish->eaters, $dish->guest_adults, $dish->meals]);
        }

        // Planning : coût du repas et « Enregistrer comme menu » ; la liste de courses additionne les trois recettes
        $page = $this->get('/planning')->assertOk()->assertSee('Repas : ')->assertSee('Enregistrer comme menu');
        $household = $this->louis->household->fresh()->load('members');
        $entries = MealPlanEntry::with('recipe.ingredients.ingredient.packs')->where('kind', 'recette')->get()->each->setRelation('household', $household);
        $needed = collect(ShoppingListBuilder::needs($entries, $household))->pluck('ingredient')->pluck('id')->all();
        foreach ([$this->saumon, $this->gratin, $this->salade] as $recipe) {
            $first = $recipe->ingredients()->where('is_optional', false)->whereHas('ingredient', fn ($q) => $q->where('is_staple', false))->first();
            $this->assertContains($first->ingredient_id, $needed, $recipe->title.' dans les courses');
        }
    }

    public function test_ajouter_une_recette_a_un_repas_deja_prevu(): void
    {
        $marina = $this->louis->household->members()->firstWhere('name', 'Marina');
        $this->actingAs($this->louis)->post('/planning/repas', ['kind' => 'recette', 'recipe_id' => $this->saumon->id, 'date' => '2026-09-30', 'slot' => 'diner', 'ajuste' => 1, 'qui' => [$marina->id], 'enfants' => 2]);

        // Le + de la case : convives du saumon repris, rappel du plat déjà prévu
        $this->get('/planning/repas/nouveau?date=2026-09-30&creneau=diner')->assertOk()
            ->assertSee('Ce repas compte déjà')->assertSee('Saumon et fondue de poireaux')
            ->assertSee('name="enfants" min="0" max="'.\App\Support\RecipeServing::MAX_GUESTS.'" value="2"', false);
    }

    public function test_les_restes_d_un_repas_compose_restent_ensemble(): void
    {
        $this->actingAs($this->louis)->post('/planning/repas', [
            'kind' => 'recette', 'recipe_id' => $this->saumon->id, 'date' => '2026-09-29', 'slot' => 'diner', 'repas' => 2, 'avec' => [$this->gratin->id],
        ])->assertSessionHasNoErrors();

        $leftovers = MealPlanEntry::where('kind', 'restes')->get();
        $this->assertCount(2, $leftovers);
        $this->assertSame(['2026-09-30 dejeuner'], $leftovers->map(fn ($e) => $e->date->toDateString().' '.$e->slot)->unique()->values()->all(),
            'Saumon et gratin réchauffés ensemble le lendemain midi');

        // Tout le repas déplacé au mardi midi : les restes restent ensemble (relecture : ils se séparaient)
        $saumon = MealPlanEntry::where('kind', 'recette')->where('recipe_id', $this->saumon->id)->first();
        $this->actingAs($this->louis)->put('/planning/repas/'.$saumon->id, ['kind' => 'recette', 'recipe_id' => $this->saumon->id, 'date' => '2026-09-29', 'slot' => 'dejeuner', 'repas' => 2, 'tout_le_repas' => 1]);
        $places = MealPlanEntry::where('kind', 'restes')->get()->map(fn ($e) => $e->date->toDateString().' '.$e->slot)->unique()->values()->all();
        $this->assertSame(['2026-09-29 diner'], $places);
    }

    public function test_a_preparer_n_est_pas_un_repas_et_les_propositions_sont_gardees(): void
    {
        // Deux fournées le même jour : indépendantes (pas « tout le repas », pas de menu)
        $yaourts = Recipe::firstWhere('title', 'Yaourts nature à la yaourtière');
        $cookies = Recipe::firstWhere('title', 'Cookies aux pépites de chocolat');
        $this->actingAs($this->louis)->post('/planning/repas', ['kind' => 'recette', 'recipe_id' => $yaourts->id, 'date' => '2026-10-01', 'slot' => 'preparation', 'quantite' => 8]);
        $this->post('/planning/repas', ['kind' => 'recette', 'recipe_id' => $cookies->id, 'date' => '2026-10-01', 'slot' => 'preparation', 'quantite' => 20]);
        $batch = MealPlanEntry::firstWhere('recipe_id', $yaourts->id);
        $this->get('/planning/repas/'.$batch->id.'/modifier')->assertDontSee('tout le repas');
        $this->put('/planning/repas/'.$batch->id, ['kind' => 'recette', 'recipe_id' => $yaourts->id, 'date' => '2026-10-02', 'slot' => 'preparation', 'quantite' => 8, 'tout_le_repas' => 1]);
        $this->assertSame('2026-10-01', MealPlanEntry::firstWhere('recipe_id', $cookies->id)->date->toDateString());
        $this->get('/planning')->assertDontSee('Enregistrer comme menu');
        $this->get('/planning/menus/nouveau?date=2026-10-01&creneau=preparation')->assertNotFound();

        // Une recette ajoutée à un plat proposé par le menu automatique : la proposition est gardée
        $proposal = MealPlanEntry::create(['household_id' => $this->louis->household_id, 'date' => '2026-09-30', 'slot' => 'dejeuner', 'kind' => 'recette',
            'recipe_id' => $this->saumon->id, 'meals' => 1, 'proposed_at' => now(), 'proposal_reason' => 'de saison']);
        $this->post('/planning/repas', ['kind' => 'recette', 'recipe_id' => $this->gratin->id, 'date' => '2026-09-30', 'slot' => 'dejeuner'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Plat proposé gardé avec : « Saumon et fondue de poireaux »'));
        $this->assertNull($proposal->fresh()->proposed_at);
    }

    public function test_modifier_un_plat_applique_le_jour_et_les_convives_a_tout_le_repas(): void
    {
        $this->actingAs($this->louis)->post('/planning/repas', ['kind' => 'recette', 'recipe_id' => $this->saumon->id, 'date' => '2026-09-30', 'slot' => 'diner', 'avec' => [$this->gratin->id]]);
        [$saumon, $gratin] = $this->dishes()->all();
        $gratin->update(['meals' => 2]);

        $this->get('/planning/repas/'.$saumon->id.'/modifier')->assertOk()->assertSee('Appliquer le jour, le repas et les convives à tout le repas : Gratin de courge butternut')
            ->assertSee('+ Ajouter une recette à ce repas');

        $this->put('/planning/repas/'.$saumon->id, ['kind' => 'recette', 'recipe_id' => $this->saumon->id, 'date' => '2026-10-01', 'slot' => 'dejeuner', 'adultes' => 2, 'tout_le_repas' => 1])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Aussi appliqué à « Gratin de courge butternut »'));

        $gratin->refresh();
        $this->assertSame(['2026-10-01', 'dejeuner', 2, 2], [$gratin->date->toDateString(), $gratin->slot, $gratin->guest_adults, $gratin->meals], 'Le gratin suit, en gardant ses 2 repas');

        // Retirer un plat d'un repas composé : le reste du repas est conservé
        $this->get('/planning/repas/'.$gratin->id.'/modifier')->assertSee('Retirer « Gratin de courge butternut » de ce repas');

        // Case décochée : seul le plat bouge
        $this->put('/planning/repas/'.$saumon->id, ['kind' => 'recette', 'recipe_id' => $this->saumon->id, 'date' => '2026-10-02', 'slot' => 'diner']);
        $this->assertSame('2026-10-01', $gratin->fresh()->date->toDateString());
        $this->put('/planning/repas/'.$saumon->id, ['kind' => 'recette', 'recipe_id' => $this->saumon->id, 'date' => '2026-10-01', 'slot' => 'dejeuner']);
        $this->delete('/planning/repas/'.$gratin->id)->assertSessionHas('status', fn ($s) => str_contains($s, 'retiré de ce repas'));
        $this->assertSame([$this->saumon->id], $this->dishes('2026-10-01', 'dejeuner')->pluck('recipe_id')->all());
    }

    public function test_enregistrer_un_menu_puis_le_replanifier(): void
    {
        $this->actingAs($this->louis)->post('/planning/repas', ['kind' => 'recette', 'recipe_id' => $this->saumon->id, 'date' => '2026-09-30', 'slot' => 'diner', 'avec' => [$this->gratin->id]]);

        $this->get('/planning/menus/nouveau?date=2026-09-30&creneau=diner')->assertOk()
            ->assertSee('value="Saumon et fondue de poireaux + Gratin de courge butternut"', false);

        $this->post('/planning/menus', ['date' => '2026-09-30', 'creneau' => 'diner', 'name' => 'Saumon-gratin', 'recettes' => [$this->saumon->id, $this->gratin->id]])
            ->assertRedirect('/planning/2026-09-28')->assertSessionHas('status', fn ($s) => str_contains($s, 'Menu « Saumon-gratin » enregistré'));

        $menu = SavedMenu::firstWhere('name', 'Saumon-gratin');
        $this->assertSame([$this->saumon->id, $this->gratin->id], $menu->recipeIds());
        $this->assertSame([$menu->id, $menu->id], $this->dishes()->pluck('saved_menu_id')->all());
        $this->get('/planning')->assertSee('☰ Saumon-gratin')->assertDontSee('Enregistrer comme menu');

        // Même nom refusé ; une seule recette refusée
        $this->post('/planning/menus', ['date' => '2026-09-30', 'creneau' => 'diner', 'name' => 'Saumon-gratin', 'recettes' => [$this->saumon->id, $this->gratin->id]])->assertSessionHasErrors('name');
        $this->post('/planning/menus', ['date' => '2026-09-30', 'creneau' => 'diner', 'name' => 'Autre', 'recettes' => [$this->saumon->id]])->assertSessionHasErrors('recettes');

        // Mes menus → Planifier : le formulaire arrive prérempli, le repas rappelle le menu
        $this->get('/planning/menus')->assertOk()->assertSee('Saumon-gratin')->assertSee('planifié 1 fois')->assertSee('/planning/repas/nouveau?menu='.$menu->id, false);
        $this->get('/planning/repas/nouveau?menu='.$menu->id)->assertOk()
            ->assertSee('<option value="'.$menu->id.'"', false)
            ->assertSee('name="avec[]" value="'.$this->gratin->id.'"', false);
        $this->post('/planning/repas', ['kind' => 'recette', 'recipe_id' => $this->saumon->id, 'date' => '2026-10-02', 'slot' => 'dejeuner', 'menu_id' => $menu->id, 'avec' => [$this->gratin->id]]);
        $this->assertSame([$menu->id, $menu->id], $this->dishes('2026-10-02', 'dejeuner')->pluck('saved_menu_id')->all());

        // Recettes changées : le repas n'est plus « ce menu »
        $this->post('/planning/repas', ['kind' => 'recette', 'recipe_id' => $this->saumon->id, 'date' => '2026-10-03', 'slot' => 'diner', 'menu_id' => $menu->id, 'avec' => [$this->salade->id]]);
        $this->assertSame([null, null], $this->dishes('2026-10-03')->pluck('saved_menu_id')->all());

        // Renommer, supprimer : les repas planifiés restent
        $this->put('/planning/menus/'.$menu->id, ['name' => 'Poisson du mercredi'])->assertSessionHas('status', 'Menu renommé : « Poisson du mercredi ».');
        $this->delete('/planning/menus/'.$menu->id)->assertRedirect('/planning/menus');
        $this->assertNull(SavedMenu::find($menu->id));
        $this->assertSame([null, null], $this->dishes()->pluck('saved_menu_id')->all());
        $this->assertCount(2, $this->dishes());
    }

    public function test_menus_isoles_par_foyer_et_recettes_refusees(): void
    {
        $other = $this->householdUser();
        $menu = SavedMenu::create(['household_id' => $other->household_id, 'name' => 'Chez les voisins']);

        $this->actingAs($this->louis)->get('/planning/menus')->assertDontSee('Chez les voisins');
        $this->put('/planning/menus/'.$menu->id, ['name' => 'Volé'])->assertNotFound();
        $this->delete('/planning/menus/'.$menu->id)->assertNotFound();

        // Fournée (yaourts) refusée à côté d'un plat ; un seul plat : pas de menu possible
        $yaourts = Recipe::firstWhere('title', 'Yaourts nature à la yaourtière');
        $this->post('/planning/repas', ['kind' => 'recette', 'recipe_id' => $this->saumon->id, 'date' => '2026-09-30', 'slot' => 'diner', 'avec' => [$yaourts->id]])
            ->assertSessionHasErrors('avec');
        $this->assertCount(0, $this->dishes());
        $this->post('/planning/repas', ['kind' => 'recette', 'recipe_id' => $this->saumon->id, 'date' => '2026-09-30', 'slot' => 'diner']);
        $this->get('/planning/menus/nouveau?date=2026-09-30&creneau=diner')->assertRedirect('/planning/2026-09-28');
    }

    public function test_etiquettes_creees_depuis_la_page_et_depuis_une_recette(): void
    {
        $this->actingAs($this->louis)->get('/recettes/etiquettes')->assertOk()->assertSee('Veggy')->assertSee('est réservé aux administrateurs', false);

        $this->post('/recettes/etiquettes', ['name' => 'viandes, Accompagnement ; veggy'])
            ->assertSessionHas('status', 'Étiquettes créées : Viandes, Accompagnement. Déjà là : Veggy.');
        $this->assertSame('Viandes', Tag::firstWhere('slug', 'viandes')->name);

        // Depuis le formulaire d'une recette : créée et cochée d'un coup, sans doublon
        $data = [
            'title' => 'Jarret de porc', 'category' => 'plat', 'yield_quantity' => 4, 'yield_unit' => 'personnes', 'difficulty' => 'facile',
            'tags' => [Tag::firstWhere('slug', 'viandes')->id], 'new_tags' => 'Viandes, Plat du dimanche',
            'ingredients' => [['name' => 'Carotte', 'quantity' => '2', 'unit' => 'piece']], 'steps' => [['body' => 'Cuire.']],
        ];
        $this->post('/recettes', $data)->assertSessionHasNoErrors();
        $recipe = Recipe::firstWhere('title', 'Jarret de porc');
        $this->assertSame(['Plat du dimanche', 'Viandes'], $recipe->tags->pluck('name')->sort()->values()->all());
        $this->get('/recettes?etiquette=plat-du-dimanche')->assertSee('Jarret de porc');

        // Renommer et supprimer : administrateurs seulement
        $tag = Tag::firstWhere('slug', 'viandes');
        $this->put('/recettes/etiquettes/'.$tag->id, ['name' => 'Viande'])->assertForbidden();
        $this->louis->forceFill(['role' => User::ROLE_ADMIN])->save();
        $this->put('/recettes/etiquettes/'.$tag->id, ['name' => 'Viandes et volailles'])->assertSessionHas('status', 'Étiquette renommée : « Viandes et volailles ».');
        $this->assertSame('viandes-et-volailles', $tag->fresh()->slug);
        $this->put('/recettes/etiquettes/'.Tag::firstWhere('slug', 'veggy')->id, ['name' => 'Végétarien']);
        $this->assertSame('Végétarien', Tag::firstWhere('slug', 'veggy')->name, 'Étiquette de départ : adresse du filtre inchangée');
        $this->delete('/recettes/etiquettes/'.$tag->id)->assertSessionHas('status', fn ($s) => str_contains($s, 'retirée de 1 recette'));
        $this->assertSame(['Plat du dimanche'], $recipe->fresh()->tags->pluck('name')->all());

        $this->post('/recettes', array_merge($data, ['title' => 'Autre', 'new_tags' => str_repeat('x', 41)]))->assertSessionHasErrors('new_tags');

        // Étiquette de départ renommée : retrouvée par son nom, jamais en double ; pas supprimable
        $this->post('/recettes/etiquettes', ['name' => 'végétarien'])->assertSessionHas('status', 'Déjà là : Végétarien.');
        $veggy = Tag::firstWhere('slug', 'veggy');
        $this->delete('/recettes/etiquettes/'.$veggy->id)->assertSessionHas('status', fn ($s) => str_contains($s, 'pas supprimée'));
        $this->assertNotNull($veggy->fresh());

        // Recette nommée « Étiquettes » : son adresse ne masque pas la page des étiquettes
        $this->post('/recettes', array_merge($data, ['title' => 'Étiquettes', 'tags' => [], 'new_tags' => null]))->assertSessionHasNoErrors();
        $this->assertSame('etiquettes-recette', Recipe::firstWhere('title', 'Étiquettes')->slug);
    }
}
