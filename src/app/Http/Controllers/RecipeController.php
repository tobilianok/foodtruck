<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeImport;
use App\Models\Tag;
use App\Support\RecipeCost;
use App\Support\RecipeServing;
use App\Support\RecipePhoto;
use App\Support\RecipeScan\ScanImporter;
use App\Support\RecipeWriter;
use App\Support\Units;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Recettes : publiques pour tous les comptes, saisie par n'importe qui,
 * modification par l'auteur ou un admin (les autres dupliquent).
 */
class RecipeController extends Controller
{
    private const RELATIONS = ['tags', 'equipment', 'author', 'ingredients.ingredient.packs.prices', 'ingredients.ingredient.units'];

    public function index(Request $request)
    {
        $user = $request->user();
        $household = $user->household->loadMissing('equipment');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'categorie' => ['nullable', 'string', 'max:30'],
            'etiquette' => ['nullable', 'string', 'max:40'],
            'saison' => ['nullable', 'boolean'],
            'rapide' => ['nullable', 'boolean'],
            'appareils' => ['nullable', 'boolean'],
            'favoris' => ['nullable', 'boolean'],
            'brouillons' => ['nullable', 'boolean'],
        ]);

        $month = (int) now('Europe/Paris')->month;
        $search = Str::lower(Str::ascii(trim($filters['q'] ?? '')));
        $favorites = $user->favoriteRecipes()->pluck('recipes.id')->all();

        $recipes = Recipe::visibleTo($user)
            ->with(self::RELATIONS)
            ->when(! empty($filters['categorie']), fn ($q) => $q->where('category', $filters['categorie']))
            ->when(! empty($filters['etiquette']), fn ($q) => $q->whereHas('tags', fn ($t) => $t->where('slug', $filters['etiquette'])))
            ->when(! empty($filters['brouillons']), fn ($q) => $q->where('status', Recipe::STATUS_DRAFT)->where('author_id', $user->id))
            ->get()
            ->filter(fn (Recipe $r) => $search === '' || str_contains(Str::lower(Str::ascii($r->title.' '.$r->description)), $search))
            ->filter(fn (Recipe $r) => empty($filters['rapide']) || ($r->totalMinutes() > 0 && $r->totalMinutes() <= 30))
            ->filter(fn (Recipe $r) => empty($filters['saison']) || $r->isInSeason($month) === true)
            ->filter(fn (Recipe $r) => empty($filters['appareils']) || $r->missingEquipment($household)->isEmpty())
            ->filter(fn (Recipe $r) => empty($filters['favoris']) || in_array($r->id, $favorites, true))
            ->sortBy(fn (Recipe $r) => Str::lower(Str::ascii($r->title)))
            ->values();

        $costs = $recipes->mapWithKeys(fn (Recipe $r) => [$r->id => RecipeCost::compute($r)]);

        return view('recipes.index', [
            'recipes' => $recipes,
            'costs' => $costs,
            'filters' => $filters,
            'month' => $month,
            'favorites' => $favorites,
            'household' => $household,
            'tags' => Tag::ordered(),
            'total' => Recipe::visibleTo($user)->count(),
            'importCount' => RecipeImport::where('household_id', $user->household_id)
                ->where(fn ($q) => $q->where('status', RecipeImport::STATUS_TO_REVIEW)
                    ->orWhere(fn ($q) => $q->where('status', RecipeImport::STATUS_CREATED)
                        ->whereHas('recipe', fn ($r) => $r->where('status', Recipe::STATUS_DRAFT))))
                ->count(),
        ]);
    }

    public function show(Request $request, Recipe $recipe)
    {
        Gate::authorize('view', $recipe);

        $recipe->load([...self::RELATIONS, 'steps.equipment', 'parent']);
        $household = $request->user()->household->loadMissing(['equipment', 'members']);

        // Pour combien cuisiner : parts du foyer (qui mange, invités, repas) ou fournée
        $serving = RecipeServing::for($recipe, $household, $request->query());
        $baseCost = RecipeCost::compute($recipe);
        $cost = $serving->isScaled() ? RecipeCost::compute($recipe, $serving->factor) : $baseCost;

        return view('recipes.show', [
            'recipe' => $recipe,
            'serving' => $serving,
            'cost' => $cost,
            'perYield' => RecipeCost::perYield($recipe, $baseCost),
            'cheap' => RecipeCost::isCheap($recipe, $baseCost),
            'season' => $recipe->isInSeason((int) now('Europe/Paris')->month),
            'missingEquipment' => $recipe->missingEquipment($household),
            'isFavorite' => $request->user()->favoriteRecipes()->whereKey($recipe->id)->exists(),
            'variants' => Recipe::visibleTo($request->user())->where('parent_id', $recipe->id)->orderBy('title')->get(),
            'canEdit' => $request->user()->can('update', $recipe),
        ]);
    }

    public function create()
    {
        return view('recipes.form', $this->formData(new Recipe([
            'category' => 'plat', 'yield_quantity' => 4, 'yield_unit' => 'personnes', 'difficulty' => 'facile',
        ])));
    }

    public function store(Request $request)
    {
        $data = RecipeWriter::validate($request);

        $recipe = RecipeWriter::save(new Recipe(['author_id' => $request->user()->id]), $data);
        $this->handlePhoto($request, $recipe);
        $this->finishImport($request, $recipe);

        return redirect()->route('recipes.show', $recipe)->with('status', $recipe->isPublished()
            ? 'Recette publiée : elle est visible par tous les comptes.'
            : 'Recette enregistrée en brouillon : toi seul la vois.');
    }

    public function edit(Recipe $recipe)
    {
        Gate::authorize('update', $recipe);

        return view('recipes.form', $this->formData($recipe->load(['ingredients.ingredient', 'steps', 'tags', 'equipment'])));
    }

    public function update(Request $request, Recipe $recipe)
    {
        Gate::authorize('update', $recipe);

        $data = RecipeWriter::validate($request);
        RecipeWriter::save($recipe, $data);
        $this->handlePhoto($request, $recipe);

        return redirect()->route('recipes.show', $recipe)->with('status', 'Recette enregistrée.');
    }

    public function destroy(Recipe $recipe)
    {
        Gate::authorize('delete', $recipe);

        RecipePhoto::delete($recipe->photo_path, $recipe->thumb_path);
        $recipe->delete();

        return redirect()->route('recipes.index')->with('status', "Recette « {$recipe->title} » supprimée.");
    }

    /** Copie modifiable d'une recette (brouillon, rattachée à l'originale). */
    public function duplicate(Request $request, Recipe $recipe)
    {
        Gate::authorize('view', $recipe);
        $recipe->load(['ingredients', 'steps', 'tags', 'equipment']);

        $copy = DB::transaction(function () use ($recipe, $request) {
            $title = Str::limit($recipe->title, 100, '').' (ma version)';
            $copy = $recipe->replicate(['slug', 'photo_path', 'thumb_path', 'status', 'author_id', 'parent_id']);
            $copy->fill([
                'title' => $title,
                'slug' => RecipeWriter::uniqueSlug($title),
                'status' => Recipe::STATUS_DRAFT,
                'author_id' => $request->user()->id,
                'parent_id' => $recipe->id,
            ]);
            $copy->photo_path = RecipePhoto::copy($recipe->photo_path, $copy->slug);
            $copy->thumb_path = RecipePhoto::copy($recipe->thumb_path, $copy->slug.'-mini');
            $copy->save();

            foreach ($recipe->ingredients as $line) {
                $copy->ingredients()->create($line->only(['position', 'group_label', 'ingredient_id', 'quantity', 'unit', 'note', 'is_optional']));
            }
            foreach ($recipe->steps as $step) {
                $copy->steps()->create($step->only(['position', 'body', 'timer_minutes', 'equipment_id']));
            }
            $copy->tags()->sync($recipe->tags->pluck('id'));
            $copy->equipment()->sync($recipe->equipment->pluck('id'));

            return $copy;
        });

        return redirect()->route('recipes.edit', $copy)->with('status', 'Copie créée en brouillon : adapte-la puis publie-la si tu veux la partager.');
    }

    public function favorite(Request $request, Recipe $recipe)
    {
        Gate::authorize('view', $recipe);
        $result = $request->user()->favoriteRecipes()->toggle([$recipe->id => ['created_at' => now()]]);

        return back()->with('status', $result['attached'] ? 'Ajoutée à tes favoris.' : 'Retirée de tes favoris.');
    }

    private function handlePhoto(Request $request, Recipe $recipe): void
    {
        if ($request->boolean('remove_photo') || $request->hasFile('photo')) {
            RecipePhoto::delete($recipe->photo_path, $recipe->thumb_path);
            $recipe->forceFill(['photo_path' => null, 'thumb_path' => null])->save();
        }

        if ($request->hasFile('photo')) {
            try {
                $recipe->forceFill(RecipePhoto::store($request->file('photo'), $recipe->slug))->save();
            } catch (Throwable $e) {
                report($e);
                throw ValidationException::withMessages(['photo' => 'La recette est enregistrée mais la photo n\'a pas pu être traitée. Essaie une image JPEG ou PNG.']);
            }
        }
    }

    /** Fiche Paperless relue à la main : elle est rattachée à la recette créée et les rapprochements sont retenus. */
    private function finishImport(Request $request, Recipe $recipe): void
    {
        $importId = (int) $request->input('import_id');
        if ($importId <= 0) {
            return;
        }

        $import = RecipeImport::where('household_id', $request->user()->household_id)->find($importId);
        if (! $import || $import->recipe_id) {
            return;
        }

        $import->forceFill(['recipe_id' => $recipe->id, 'status' => RecipeImport::STATUS_CREATED, 'auto_published' => false])->save();
        ScanImporter::learn((array) $request->input('ingredients', []));
    }

    /**
     * @param  array{ingredients?: array, steps?: array}  $prefill  lignes proposées (relecture d'une fiche Paperless)
     */
    public static function formData(Recipe $recipe, array $prefill = []): array
    {
        $ingredients = old('ingredients', $prefill['ingredients'] ?? ($recipe->exists
            ? $recipe->ingredients->map(fn ($line) => [
                'group' => $line->group_label,
                'name' => $line->ingredient->name,
                'quantity' => $line->quantity === null ? '' : Units::number($line->quantity, 2),
                'unit' => $line->unit,
                'note' => $line->note,
                'optional' => $line->is_optional,
            ])->values()->all()
            : [[], [], []]));

        $steps = old('steps', $prefill['steps'] ?? ($recipe->exists
            ? $recipe->steps->map(fn ($s) => ['body' => $s->body, 'timer' => $s->timer_minutes, 'equipment_id' => $s->equipment_id])->values()->all()
            : [[], []]));

        return [
            'recipe' => $recipe,
            'ingredientRows' => $ingredients ?: [[]],
            'stepRows' => $steps ?: [[]],
            'ingredientNames' => Ingredient::orderBy('name')->pluck('name'),
            // v0.16.0 : unités propres par ingrédient (« Ail » → gousse, tête), pour la liste des unités de chaque ligne
            'ingredientUnits' => Ingredient::has('units')->with('units')->get()
                ->mapWithKeys(fn (Ingredient $i) => [$i->name => $i->units->mapWithKeys(fn ($u) => [$u->code() => $u->name])->all()])
                ->all(),
            // v0.17.0 : catalogue pour les corrections en fenêtre (slug, unité de base, poids d'une pièce, unités propres)
            'ingredientCatalog' => Ingredient::with('units')->orderBy('name')->get()
                ->mapWithKeys(fn (Ingredient $i) => [$i->name => \App\Http\Controllers\IngredientQuickController::payload($i)])
                ->all(),
            'aisles' => \App\Models\Aisle::ordered(),
            'tags' => Tag::ordered(),
            'equipment' => Equipment::ordered(),
            'selectedTags' => array_map('intval', old('tags', $recipe->exists ? $recipe->tags->pluck('id')->all() : [])),
            'selectedEquipment' => array_map('intval', old('equipment', $recipe->exists ? $recipe->equipment->pluck('id')->all() : [])),
        ];
    }
}
