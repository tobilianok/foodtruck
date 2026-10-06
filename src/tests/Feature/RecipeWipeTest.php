<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\IngredientUnit;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\RecipeImport;
use App\Models\RecipeIngredient;
use App\Support\RecipeImporter;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** v0.18.0 : « ./ft vider-recettes » supprime toutes les recettes puis relit les fiches de Paperless. */
class RecipeWipeTest extends TestCase
{
    use RefreshDatabase;

    public function test_toutes_les_recettes_supprimees_le_reste_conserve_les_fiches_relues(): void
    {
        Storage::fake('public');
        ReferenceImporter::import();
        RecipeImporter::import();
        $user = $this->householdUser();
        $household = $user->household;
        $household->forceFill(['paperless_url' => 'http://paperless.test:8000', 'paperless_token' => str_repeat('a', 40)])->save();
        config(['foodtruck.pages_url' => 'http://pages.test', 'foodtruck.vision_url' => 'http://10.10.10.1:11434']);

        $recipe = Recipe::first();
        Storage::disk('public')->put('recettes/photo.jpg', 'x');
        Storage::disk('public')->put('recettes/photo-mini.jpg', 'x');
        $recipe->forceFill(['photo_path' => 'recettes/photo.jpg', 'thumb_path' => 'recettes/photo-mini.jpg'])->save();
        $source = MealPlanEntry::create(['household_id' => $household->id, 'date' => '2026-10-07', 'slot' => 'diner', 'position' => 0, 'kind' => 'recette', 'recipe_id' => $recipe->id, 'meals' => 2]);
        MealPlanEntry::create(['household_id' => $household->id, 'date' => '2026-10-08', 'slot' => 'dejeuner', 'position' => 0, 'kind' => 'restes', 'recipe_id' => $recipe->id, 'source_entry_id' => $source->id]);
        MealPlanEntry::create(['household_id' => $household->id, 'date' => '2026-10-08', 'slot' => 'diner', 'position' => 0, 'kind' => 'hors_maison', 'note' => 'Restaurant']);
        RecipeImport::create(['household_id' => $household->id, 'paperless_document_id' => 490, 'status' => RecipeImport::STATUS_TO_REVIEW, 'title' => 'Ancienne lecture']);
        $ingredients = Ingredient::count();
        $units = IngredientUnit::count();
        $this->assertGreaterThan(10, Recipe::count());

        Http::fake([
            'http://paperless.test:8000/api/tags/*' => Http::response(['count' => 1, 'results' => [['id' => 9, 'name' => 'recettes']]]),
            'http://paperless.test:8000/api/documents/*' => Http::response(['count' => 2, 'next' => null, 'results' => [
                ['id' => 490, 'title' => 'Salade façon piémontaise au jambon', 'modified' => '2026-10-06T15:00:00+02:00', 'content' => ''],
                ['id' => 491, 'title' => 'Salade de grenailles', 'modified' => '2026-10-06T15:00:00+02:00', 'content' => ''],
            ]]),
        ]);

        // Aperçu : rien n'est effacé ; mauvaise confirmation : rien n'est effacé
        $this->artisan('foodtruck:vider-recettes --apercu')->expectsOutputToContain('Recettes')->assertExitCode(0);
        $this->artisan('foodtruck:vider-recettes')->expectsQuestion('Pour confirmer, tape EFFACER', 'non')->assertExitCode(1);
        $this->assertGreaterThan(10, Recipe::count());

        $this->artisan('foodtruck:vider-recettes --oui')->expectsOutputToContain('Toutes les recettes sont supprimées')->assertExitCode(0);

        $this->assertSame([0, 0], [Recipe::count(), RecipeIngredient::count()]);
        $this->assertSame(['hors_maison'], MealPlanEntry::pluck('kind')->all(), 'Repas sans recette conservé, restes supprimés avec leur repas');
        $this->assertFalse(Storage::disk('public')->exists('recettes/photo.jpg'));
        $this->assertFalse(Storage::disk('public')->exists('recettes/photo-mini.jpg'));
        $this->assertSame([$ingredients, $units], [Ingredient::count(), IngredientUnit::count()], 'Ingrédients et unités conservés');

        // Les fiches de Paperless sont remises en lecture par le modèle (l'ancienne lecture est oubliée)
        $this->assertSame([490, 491], RecipeImport::orderBy('paperless_document_id')->pluck('paperless_document_id')->all());
        $this->assertTrue(RecipeImport::all()->every(fn (RecipeImport $i) => $i->isReading()));
        $this->assertSame('Salade façon piémontaise au jambon', RecipeImport::firstWhere('paperless_document_id', 490)->title);
    }
}
