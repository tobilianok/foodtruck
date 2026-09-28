<?php

namespace Tests\Feature;

use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\IngredientPack;
use App\Models\Price;
use App\Models\Store;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rayons_et_magasins_de_depart(): void
    {
        $this->assertSame(14, Aisle::count());
        $this->assertEqualsCanonicalizing(
            ['leclerc-drive', 'morin', 'lidl', 'carrefour', 'hyper-u', 'grand-frais'],
            Store::pluck('slug')->all()
        );
    }

    public function test_import_du_jeu_de_depart_idempotent(): void
    {
        $first = ReferenceImporter::import();
        $this->assertGreaterThan(100, $first['ingredients']);
        $this->assertSame(Ingredient::count(), $first['ingredients']);
        $this->assertSame(0, Price::where('source', '!=', 'estimation')->count());

        // Une correction faite dans l'appli n'est pas écrasée par un nouvel import
        Ingredient::firstWhere('slug', 'carotte')->update(['name' => 'Carotte des sables']);
        $second = ReferenceImporter::import();

        $this->assertSame(0, $second['ingredients']);
        $this->assertSame($first['ingredients'], $second['skipped']);
        $this->assertSame('Carotte des sables', Ingredient::firstWhere('slug', 'carotte')->name);
    }

    public function test_meilleure_offre(): void
    {
        ReferenceImporter::import();

        $lait = Ingredient::with('packs.prices')->firstWhere('slug', 'lait-demi-ecreme');
        $best = $lait->bestOffer();

        // Pack 6 × 1 L à 5,99 € (0,998 €/L) moins cher que la bouteille à 1,05 €
        $this->assertSame('Pack 6 × 1 L', $best['pack']->label);
        $this->assertEqualsWithDelta(99.83, $best['per_reference_cents'], 0.01);

        $carotte = Ingredient::with('packs.prices')->firstWhere('slug', 'carotte');
        $this->assertSame('morin', $carotte->bestOffer()['price']->store->slug);
        $this->assertSame('leclerc-drive', $carotte->bestOffer([Store::firstWhere('slug', 'leclerc-drive')->id])['price']->store->slug);
    }

    public function test_saisonnalite(): void
    {
        ReferenceImporter::import();

        $butternut = Ingredient::firstWhere('slug', 'courge-butternut');
        $this->assertTrue($butternut->isInSeason(10));
        $this->assertFalse($butternut->isInSeason(7));
        $this->assertNull(Ingredient::firstWhere('slug', 'carotte')->isInSeason(7));
    }

    public function test_le_prix_le_plus_recent_fait_foi(): void
    {
        ReferenceImporter::import();
        $pack = IngredientPack::whereHas('ingredient', fn ($q) => $q->where('slug', 'beurre-doux'))->first();
        $store = Store::firstWhere('slug', 'leclerc-drive');

        Price::create(['ingredient_pack_id' => $pack->id, 'store_id' => $store->id, 'price_cents' => 289, 'source' => 'ticket', 'observed_on' => '2026-10-02']);
        Price::create(['ingredient_pack_id' => $pack->id, 'store_id' => $store->id, 'price_cents' => 250, 'source' => 'manuel', 'observed_on' => '2026-09-30']);

        $this->assertSame(289, $pack->fresh('prices')->currentPriceFor($store->id)->price_cents);
    }
}
