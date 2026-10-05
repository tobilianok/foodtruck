<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Store;
use App\Support\PackPlanner;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** v0.8.0 : du besoin (60 cl de lait) aux conditionnements entiers (1 bouteille de 1 L). */
class PackPlannerTest extends TestCase
{
    use RefreshDatabase;

    private int $leclerc;

    private int $morin;

    protected function setUp(): void
    {
        parent::setUp();
        ReferenceImporter::import();
        $this->leclerc = Store::firstWhere('slug', 'leclerc-drive')->id;
        $this->morin = Store::firstWhere('slug', 'morin')->id;
    }

    private function plan(string $slug, ?float $needed, ?int $store = null): ?array
    {
        $ingredient = Ingredient::with('packs.prices')->firstWhere('slug', $slug);

        return PackPlanner::plan($ingredient, $needed, $store ?? $this->leclerc);
    }

    private function summary(?array $plan): string
    {
        return $plan === null ? 'aucun prix' : implode(' + ', array_map(fn ($p) => $p['bulk'] ? $p['quantity'].' vrac' : $p['count'].'×'.$p['label'], $plan['parts'])).' = '.$plan['cents'];
    }

    public function test_20_cl_plus_40_cl_de_lait_font_une_bouteille(): void
    {
        $plan = $this->plan('lait-entier', 600);

        $this->assertSame('1×Bouteille 1 L = 125', $this->summary($plan));
        $this->assertSame(400.0, $plan['surplus']);
    }

    public function test_le_pack_est_pris_quand_il_revient_moins_cher_que_les_bouteilles(): void
    {
        $this->assertSame('2×Bouteille 1 L = 250', $this->summary($this->plan('lait-entier', 1500)));
        $this->assertSame('5×Bouteille 1 L = 625', $this->summary($this->plan('lait-entier', 5000)));
        // 6 bouteilles = 7,50 € > pack de 6 = 7,19 €
        $this->assertSame('1×Pack 6 × 1 L = 719', $this->summary($this->plan('lait-entier', 5500)));
    }

    public function test_combinaison_de_boites_d_oeufs(): void
    {
        $this->assertSame('1×Boîte de 6 = 219', $this->summary($this->plan('oeuf', 3)));
        $this->assertSame('1×Boîte de 12 = 399', $this->summary($this->plan('oeuf', 7)), '12 œufs coûtent moins que 2 boîtes de 6');
        $this->assertSame('1×Boîte de 12 + 1×Boîte de 6 = 618', $this->summary($this->plan('oeuf', 13)));
    }

    public function test_produit_au_poids_achete_a_la_quantite_utile(): void
    {
        $plan = $this->plan('carotte', 430, $this->morin);

        $this->assertSame('450 vrac = 63', $this->summary($plan), 'Arrondi à la pesée de 50 g : 450 g à 1,40 €/kg');
        $this->assertSame(20.0, $plan['surplus']);
    }

    public function test_un_leger_manque_ne_fait_pas_racheter_un_paquet(): void
    {
        $this->assertSame('1×Bouteille 1 L = 125', $this->summary($this->plan('lait-entier', 1010)), '1 % de moins qu\'attendu : toléré');
        $this->assertSame('2×Bouteille 1 L = 250', $this->summary($this->plan('lait-entier', 1020)), 'Au-delà de 15 ml : on rachète');
        $this->assertSame('1×Boîte de 6 = 219', $this->summary($this->plan('oeuf', 6)));
        $this->assertSame('1×Boîte de 12 = 399', $this->summary($this->plan('oeuf', 7)), 'Jamais de tolérance sur des pièces');
    }

    public function test_magasin_sans_prix_et_besoin_inconnu(): void
    {
        $this->assertNull($this->plan('beurre-doux', 100, $this->morin), 'Pas de prix de beurre chez Morin');
        $this->assertSame('1×Plaquette 250 g = 269', $this->summary($this->plan('beurre-doux', null)), 'Besoin non chiffré : un seul article');
        $this->assertSame('50 vrac = 7', $this->summary($this->plan('carotte', null, $this->morin)));
    }

    public function test_magasin_le_moins_cher_et_cout_utilise(): void
    {
        $carotte = Ingredient::with('packs.prices')->firstWhere('slug', 'carotte');

        $this->assertSame(['store_id' => $this->morin, 'cents' => 35], PackPlanner::cheapest($carotte, 250, [$this->leclerc, $this->morin]));
        $this->assertSame(35, PackPlanner::usedCents($carotte, 250, $this->morin));
        $this->assertSame(42, PackPlanner::usedCents($carotte, 250, $this->leclerc));

        $lait = Ingredient::with('packs.prices')->firstWhere('slug', 'lait-entier');
        $this->assertSame(72, PackPlanner::usedCents($lait, 600, $this->leclerc), '600 ml au prix du pack de 6 L (1,198 €/L)');
    }
}
