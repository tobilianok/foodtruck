<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Household;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use App\Support\RecipeImporter;
use App\Support\ReferenceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecipeTest extends TestCase
{
    use RefreshDatabase;

    private User $louis;

    private User $camille;

    protected function setUp(): void
    {
        parent::setUp();
        ReferenceImporter::import();
        RecipeImporter::import();

        $this->louis = $this->householdUser();
        $this->camille = $this->householdUser(User::HOUSEHOLD_MEMBER, $this->louis->household);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Purée de carottes',
            'description' => 'Toute douce.',
            'category' => 'accompagnement',
            'yield_quantity' => '4',
            'yield_unit' => 'personnes',
            'prep_minutes' => '10',
            'cook_minutes' => '20',
            'difficulty' => 'facile',
            'protein' => 'aucune',
            'tags' => [Tag::firstWhere('slug', 'veggy')->id],
            'equipment' => [],
            'ingredients' => [
                ['name' => 'carotte', 'quantity' => '6', 'unit' => 'piece', 'note' => 'épluchées'],
                ['name' => 'Beurre doux', 'quantity' => '20', 'unit' => 'g'],
                ['name' => 'Crème liquide entière', 'quantity' => '5', 'unit' => 'cl', 'optional' => '1'],
                ['name' => 'Sel fin', 'quantity' => '', 'unit' => ''],
                ['name' => '', 'quantity' => '', 'unit' => ''],
            ],
            'steps' => [
                ['body' => 'Cuire les carottes.', 'timer' => '20', 'equipment_id' => Equipment::firstWhere('slug', 'plaques')->id],
                ['body' => 'Mixer avec le beurre.', 'equipment_id' => Equipment::firstWhere('slug', 'mixeur-plongeant')->id],
                ['body' => ''],
            ],
        ], $overrides);
    }

    public function test_liste_et_filtres(): void
    {
        $this->actingAs($this->louis)->get('/recettes')->assertOk()
            ->assertSee('Velouté de potimarron')->assertSee('Barres de céréales gourmandes')->assertSee('/ personne');

        $this->get('/recettes?q=POTIMARRON')->assertOk()->assertSee('Velouté de potimarron')->assertDontSee('Chili sin carne');
        $this->get('/recettes?categorie=gouter')->assertOk()->assertSee('Cookies')->assertDontSee('Hachis parmentier');
        $this->get('/recettes?etiquette=vegan')->assertOk()->assertSee('Chili sin carne')->assertDontSee('Quiche');
        $this->get('/recettes?rapide=1')->assertOk()->assertSee('Pâtes crémeuses')->assertDontSee('Hachis parmentier');

        // Appareils : le foyer de test n'a aucun appareil
        $this->get('/recettes?appareils=1')->assertOk()->assertSee('Pâte brisée maison')->assertDontSee('Velouté de potimarron');
    }

    public function test_fiche_recette(): void
    {
        $this->actingAs($this->louis)->get('/recettes/hachis-parmentier-aux-legumes-caches')->assertOk()
            ->assertSee('Hachis parmentier')
            ->assertSee('Bœuf haché 5 %')
            ->assertSee('≈ 250 g', false)
            ->assertSee('Coût estimé')
            ->assertSee('Il manque à ton foyer');

        $this->get('/recettes/yaourts-nature-a-la-yaourtiere')->assertOk()->assertSee('Fait maison rentable');
    }

    public function test_creation_et_calculs(): void
    {
        $this->actingAs($this->camille)->post('/recettes', $this->payload())
            ->assertRedirect('/recettes/puree-de-carottes');

        $recipe = Recipe::with(['ingredients', 'steps', 'equipment', 'tags'])->firstWhere('slug', 'puree-de-carottes');
        $this->assertSame($this->camille->id, $recipe->author_id);
        $this->assertSame('publie', $recipe->status);
        $this->assertCount(4, $recipe->ingredients, 'La ligne vide est ignorée');
        $this->assertNull($recipe->ingredients[3]->quantity, 'Sel : selon goût');
        $this->assertTrue($recipe->ingredients[2]->is_optional);
        $this->assertCount(2, $recipe->steps);
        $this->assertEqualsCanonicalizing(['plaques', 'mixeur-plongeant'], $recipe->equipment->pluck('slug')->all(), 'Appareils des étapes ajoutés');

        $this->get('/recettes/puree-de-carottes')->assertOk()->assertSee('6 pièces')->assertSee('≈ 750 g', false);
    }

    public function test_erreurs_de_saisie(): void
    {
        $this->actingAs($this->louis);

        $this->post('/recettes', $this->payload(['ingredients' => [['name' => 'Truffe blanche', 'quantity' => '10', 'unit' => 'g']]]))
            ->assertSessionHasErrors('ingredients');
        $this->assertStringContainsString('Truffe blanche', implode(' ', session('errors')->get('ingredients')));

        $this->post('/recettes', $this->payload(['ingredients' => [['name' => 'Farine de blé T55', 'quantity' => '2', 'unit' => 'piece']]]))
            ->assertSessionHasErrors('ingredients');
        $this->assertStringContainsString('Poids d\'une pièce inconnu', implode(' ', session('errors')->get('ingredients')));

        $this->post('/recettes', $this->payload(['ingredients' => [['name' => 'Carotte', 'quantity' => '3', 'unit' => '']]]))
            ->assertSessionHasErrors('ingredients');

        $this->post('/recettes', $this->payload(['steps' => [['body' => '']]]))->assertSessionHasErrors('steps');
        $this->assertNull(Recipe::firstWhere('slug', 'puree-de-carottes'));
    }

    public function test_brouillon_et_droits(): void
    {
        $this->actingAs($this->camille)->post('/recettes', $this->payload(['draft' => '1']));
        $recipe = Recipe::firstWhere('slug', 'puree-de-carottes');
        $this->assertSame('brouillon', $recipe->status);

        // Brouillon invisible pour les autres
        $outsider = $this->householdUser(User::HOUSEHOLD_ADMIN, Household::create(['name' => 'Autre foyer']));
        $this->actingAs($outsider)->get('/recettes/puree-de-carottes')->assertForbidden();
        $this->actingAs($outsider)->get('/recettes')->assertDontSee('Purée de carottes');

        // Seule l'autrice (ou un admin de l'appli) modifie
        $this->actingAs($outsider)->put('/recettes/puree-de-carottes', $this->payload(['title' => 'Piratée']))->assertForbidden();
        $this->actingAs($this->camille)->put('/recettes/puree-de-carottes', $this->payload(['title' => 'Purée de carottes au cumin']))
            ->assertRedirect('/recettes/puree-de-carottes');
        $this->assertSame('Purée de carottes au cumin', $recipe->fresh()->title);

        $admin = $this->householdUser();
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();
        $this->actingAs($admin)->get('/recettes/puree-de-carottes/modifier')->assertOk();

        // Les recettes du lot de départ ne sont modifiables que par un admin
        $this->actingAs($this->camille)->get('/recettes/chili-sin-carne/modifier')->assertForbidden();
    }

    public function test_duplication_favoris_suppression(): void
    {
        $this->actingAs($this->camille);

        $this->post('/recettes/chili-sin-carne/dupliquer')->assertRedirect('/recettes/chili-sin-carne-ma-version/modifier');
        $copy = Recipe::with(['ingredients', 'steps', 'tags'])->firstWhere('slug', 'chili-sin-carne-ma-version');
        $original = Recipe::with(['ingredients', 'steps', 'tags'])->firstWhere('slug', 'chili-sin-carne');
        $this->assertSame('brouillon', $copy->status);
        $this->assertSame($original->id, $copy->parent_id);
        $this->assertSame($this->camille->id, $copy->author_id);
        $this->assertCount($original->ingredients->count(), $copy->ingredients);
        $this->assertCount($original->steps->count(), $copy->steps);
        $this->assertEquals($original->tags->pluck('id'), $copy->tags->pluck('id'));

        $this->post('/recettes/chili-sin-carne/favori')->assertRedirect();
        $this->assertTrue($this->camille->favoriteRecipes()->whereKey($original->id)->exists());
        $this->get('/recettes?favoris=1')->assertSee('Chili sin carne')->assertDontSee('Cookies');
        $this->post('/recettes/chili-sin-carne/favori');
        $this->assertFalse($this->camille->favoriteRecipes()->whereKey($original->id)->exists());

        $this->delete('/recettes/chili-sin-carne-ma-version')->assertRedirect('/recettes');
        $this->assertModelMissing($copy);
        $this->assertModelExists($original);
    }

    public function test_photo_redimensionnee(): void
    {
        Storage::fake('public');

        $this->actingAs($this->louis)->post('/recettes', $this->payload([
            'photo' => UploadedFile::fake()->image('puree.jpg', 3000, 2000),
        ]))->assertRedirect();

        $recipe = Recipe::firstWhere('slug', 'puree-de-carottes');
        Storage::disk('public')->assertExists($recipe->photo_path);
        Storage::disk('public')->assertExists($recipe->thumb_path);
        [$width] = getimagesizefromstring(Storage::disk('public')->get($recipe->photo_path));
        [$thumbWidth] = getimagesizefromstring(Storage::disk('public')->get($recipe->thumb_path));
        $this->assertSame(1600, $width);
        $this->assertSame(640, $thumbWidth);

        // Suppression de la photo à la modification
        $this->put('/recettes/puree-de-carottes', $this->payload(['remove_photo' => '1']))->assertRedirect();
        Storage::disk('public')->assertMissing($recipe->photo_path);
        $this->assertNull($recipe->fresh()->photo_path);
    }
}
