<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Support\RecipeCost;
use App\Support\RecipeImporter;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_premier_lot_s_importe_avec_des_couts_complets(): void
    {
        ReferenceImporter::import();
        $counts = RecipeImporter::import();

        $this->assertSame(24, $counts['recipes']);
        $this->assertSame(24, Recipe::where('status', 'publie')->count());

        foreach (Recipe::with(['ingredients.ingredient.packs.prices', 'steps', 'tags'])->get() as $recipe) {
            $cost = RecipeCost::compute($recipe);
            $this->assertTrue($cost['complete'], "{$recipe->title} : prix manquants ".implode(', ', $cost['missing']));
            $this->assertGreaterThan(0, $cost['total_cents'], $recipe->title);
            $this->assertNotEmpty($recipe->steps, $recipe->title);
            $this->assertNotEmpty($recipe->tags, $recipe->title);

            foreach ($recipe->ingredients as $line) {
                if ($line->quantity !== null) {
                    $this->assertNotNull($line->baseQuantity(), "{$recipe->title} / {$line->ingredient->name}");
                }
            }
        }

        // Réimport : rien n'est dupliqué
        $again = RecipeImporter::import();
        $this->assertSame(0, $again['recipes']);
        $this->assertSame(24, $again['skipped']);
    }

    public function test_indicateurs_calcules(): void
    {
        ReferenceImporter::import();
        RecipeImporter::import();

        $velute = Recipe::with('ingredients.ingredient.packs.prices')->firstWhere('slug', 'veloute-de-potimarron');
        $this->assertTrue($velute->isInSeason(10));
        $this->assertFalse($velute->isInSeason(6));
        $this->assertTrue(RecipeCost::isCheap($velute, RecipeCost::compute($velute)));

        $yaourts = Recipe::with('ingredients.ingredient.packs.prices')->firstWhere('slug', 'yaourts-nature-a-la-yaourtiere');
        $cost = RecipeCost::compute($yaourts);
        $this->assertLessThan($yaourts->industrial_price_cents, $cost['total_cents'], 'Les yaourts maison doivent coûter moins que l\'industriel');
        $this->assertSame('8 pots', $yaourts->yieldLabel());
    }
}
