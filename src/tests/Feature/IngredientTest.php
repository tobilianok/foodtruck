<?php

namespace Tests\Feature;

use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\IngredientPack;
use App\Models\Price;
use App\Models\Store;
use App\Models\User;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngredientTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        ReferenceImporter::import();
        // Un simple membre du foyer : il peut tout de même gérer ingrédients et prix
        $this->user = $this->householdUser(User::HOUSEHOLD_MEMBER);
    }

    public function test_liste_filtres_et_saison(): void
    {
        $this->actingAs($this->user)->get('/ingredients')
            ->assertOk()->assertSee('Carotte')->assertSee('Lait demi-écrémé')->assertSee('/kg');

        $this->get('/ingredients?q=lait')->assertOk()->assertSee('Lait entier')->assertDontSee('Carotte');
        $this->get('/ingredients?q=ECREME')->assertOk()->assertSee('Lait demi-écrémé');
        $this->get('/ingredients?rayon=cremerie')->assertOk()->assertSee('Beurre doux')->assertDontSee('Farine');

        $this->travelTo(now()->setDate(2026, 10, 15));
        $this->get('/ingredients?saison=1')->assertOk()->assertSee('Potimarron')->assertDontSee('Aubergine');
    }

    public function test_fiche_ingredient(): void
    {
        $this->actingAs($this->user)->get('/ingredients/lait-demi-ecreme')
            ->assertOk()
            ->assertSee('Pack 6 × 1 L')
            ->assertSee('Meilleur prix connu')
            ->assertSee('Leclerc Drive');
    }

    public function test_creation_avec_conditionnement_et_prix(): void
    {
        $aisle = Aisle::firstWhere('slug', 'epicerie-sucree');
        $lidl = Store::firstWhere('slug', 'lidl');

        $this->actingAs($this->user)->post('/ingredients', [
            'name' => 'Graines de chia',
            'aisle_id' => $aisle->id,
            'base_unit' => 'g',
            'season_months' => [],
            'is_fresh' => '0',
            'is_staple' => '0',
            'pack_label' => 'Sachet 250 g',
            'pack_quantity' => '250',
            'price' => '2,49 €',
            'price_store_id' => $lidl->id,
        ])->assertRedirect('/ingredients/graines-de-chia');

        $chia = Ingredient::with('packs.prices')->firstWhere('slug', 'graines-de-chia');
        $this->assertSame($this->user->id, $chia->created_by);
        $this->assertNull($chia->season_months);
        $this->assertSame(249, $chia->packs->first()->prices->first()->price_cents);
        $this->assertSame('manuel', $chia->packs->first()->prices->first()->source);

        // Doublon refusé
        $this->post('/ingredients', ['name' => 'graines de Chia', 'aisle_id' => $aisle->id, 'base_unit' => 'g'])
            ->assertSessionHasErrors('name');
    }

    public function test_modification_et_verrou_de_l_unite(): void
    {
        $this->actingAs($this->user)->put('/ingredients/carotte', [
            'name' => 'Carotte',
            'aisle_id' => Aisle::firstWhere('slug', 'fruits-legumes')->id,
            'base_unit' => 'g',
            'piece_weight_g' => '130',
            'season_months' => array_map('strval', range(1, 12)),
            'is_fresh' => '1',
            'is_staple' => '0',
        ])->assertRedirect();

        $carotte = Ingredient::firstWhere('slug', 'carotte');
        $this->assertEquals(130, $carotte->piece_weight_g);
        $this->assertNull($carotte->season_months, 'Douze mois cochés = toute l\'année');

        $this->put('/ingredients/carotte', [
            'name' => 'Carotte', 'aisle_id' => $carotte->aisle_id, 'base_unit' => 'piece',
        ])->assertSessionHasErrors('base_unit');
    }

    public function test_conditionnements_et_prix(): void
    {
        $this->actingAs($this->user);
        $beurre = Ingredient::firstWhere('slug', 'beurre-doux');

        $this->post('/ingredients/beurre-doux/conditionnements', ['label' => 'Plaquette 500 g', 'quantity' => '500'])->assertRedirect();
        $pack = IngredientPack::where('ingredient_id', $beurre->id)->where('label', 'Plaquette 500 g')->firstOrFail();

        $this->post('/ingredients/beurre-doux/prix', [
            'ingredient_pack_id' => $pack->id,
            'store_id' => Store::firstWhere('slug', 'lidl')->id,
            'price' => '4.19',
            'is_promo' => '1',
            'observed_on' => now('Europe/Paris')->toDateString(),
        ])->assertRedirect();
        $this->assertDatabaseHas('prices', ['ingredient_pack_id' => $pack->id, 'price_cents' => 419, 'is_promo' => true]);

        // Un conditionnement d'un autre ingrédient est refusé
        $autre = IngredientPack::whereHas('ingredient', fn ($q) => $q->where('slug', 'carotte'))->first();
        $this->post('/ingredients/beurre-doux/prix', [
            'ingredient_pack_id' => $autre->id, 'store_id' => 1, 'price' => '1', 'observed_on' => now()->toDateString(),
        ])->assertSessionHasErrors('ingredient_pack_id');
        $this->put("/ingredients/beurre-doux/conditionnements/{$autre->id}", ['label' => 'X', 'quantity' => 1])->assertNotFound();

        // Date future refusée
        $this->post('/ingredients/beurre-doux/prix', [
            'ingredient_pack_id' => $pack->id, 'store_id' => 1, 'price' => '1', 'observed_on' => now()->addDays(3)->toDateString(),
        ])->assertSessionHasErrors('observed_on');

        $this->delete("/ingredients/beurre-doux/conditionnements/{$pack->id}")->assertRedirect();
        $this->assertModelMissing($pack);
        $this->assertDatabaseMissing('prices', ['ingredient_pack_id' => $pack->id]);
    }

    public function test_mise_a_jour_rapide_des_prix(): void
    {
        $this->actingAs($this->user);
        $leclerc = Store::firstWhere('slug', 'leclerc-drive');

        $this->get('/prix')->assertRedirect('/prix/leclerc-drive');
        $this->get('/prix/leclerc-drive')->assertOk()->assertSee('Lait demi-écrémé')->assertSee('estimé');
        $this->get('/prix/morin?rayon=fruits-legumes')->assertOk()->assertSee('Carotte')->assertDontSee('Beurre doux');
        // Par défaut : seulement les produits déjà vendus dans le magasin ; « tous » pour en ajouter d'autres
        $this->get('/prix/morin')->assertOk()->assertDontSee('Beurre doux')->assertSee('déjà vendus');
        $this->get('/prix/morin?tous=1')->assertOk()->assertSee('Beurre doux');
        // Magasin sans aucun prix : tout est affiché d'office
        $this->get('/prix/lidl')->assertOk()->assertSee('Beurre doux')->assertSee('Tous les produits');

        $lait = IngredientPack::whereHas('ingredient', fn ($q) => $q->where('slug', 'lait-demi-ecreme'))->where('label', 'Bouteille 1 L')->first();
        $beurre = IngredientPack::whereHas('ingredient', fn ($q) => $q->where('slug', 'beurre-doux'))->first();
        $farine = IngredientPack::whereHas('ingredient', fn ($q) => $q->where('slug', 'farine-de-ble-t55'))->first();
        $before = Price::count();

        $this->post('/prix/leclerc-drive', [
            'observed_on' => now('Europe/Paris')->toDateString(),
            'prices' => [
                $lait->id => '1,09',       // nouveau prix
                $beurre->id => '2,69',     // même montant que l'estimation : devient un prix confirmé
                $farine->id => 'abc',      // incompréhensible : ignoré
                '999999' => '1',           // conditionnement inconnu : ignoré
            ],
            'promo' => [$lait->id => '1'],
        ])->assertRedirect()->assertSessionHasErrors('prices');

        $this->assertSame($before + 2, Price::count());
        $this->assertSame(109, $lait->fresh('prices')->currentPriceFor($leclerc->id)->price_cents);
        $this->assertTrue($lait->fresh('prices')->currentPriceFor($leclerc->id)->is_promo);
        $this->assertSame('manuel', $beurre->fresh('prices')->currentPriceFor($leclerc->id)->source);

        // Resaisir un prix déjà confirmé ne crée pas de doublon
        $this->post('/prix/leclerc-drive', ['observed_on' => now('Europe/Paris')->toDateString(), 'prices' => [$beurre->id => '2,69']]);
        $this->assertSame($before + 2, Price::count());
    }

    public function test_magasins_du_foyer(): void
    {
        $admin = $this->householdUser();
        $leclerc = Store::firstWhere('slug', 'leclerc-drive');
        $morin = Store::firstWhere('slug', 'morin');

        $this->actingAs($admin)->put('/foyer/reglages', [
            'name' => 'Famille Test', 'budget' => 100, 'main_store_id' => $leclerc->id, 'produce_store_id' => $morin->id,
        ])->assertRedirect();

        $household = $admin->household->fresh();
        $this->assertSame($leclerc->id, $household->main_store_id);
        $this->assertSame($morin->id, $household->produce_store_id);
        $this->get('/foyer')->assertOk()->assertSee('Leclerc Drive');
    }

    public function test_acces_reserve_aux_comptes_avec_foyer(): void
    {
        $this->get('/ingredients')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get('/ingredients')->assertRedirect(route('onboarding'));
    }
}
