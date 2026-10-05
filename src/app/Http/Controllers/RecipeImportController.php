<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Models\RecipeImport;
use App\Support\RecipeScan\ImportDiscarder;
use App\Support\RecipeScan\RecipeScanSync;
use App\Support\RecipeScan\ScanImporter;
use Illuminate\Http\Request;

/**
 * Fiches de recettes venues de Paperless : liste, relecture avant création, relecture automatique, suppression.
 */
class RecipeImportController extends Controller
{
    public function index(Request $request)
    {
        $household = $request->user()->household;
        $imports = $household->recipeImports()->with('recipe')->get();

        $pending = $imports->filter(fn (RecipeImport $i) => self::needsReview($i))->values();

        return view('recipes.imports', [
            'household' => $household,
            'pending' => $pending,
            'reading' => $pending->filter->isReading()->count(),
            'done' => $imports->filter(fn (RecipeImport $i) => $i->status === RecipeImport::STATUS_CREATED && ! self::needsReview($i))->values(),
        ]);
    }

    public function sync(Request $request, RecipeScanSync $sync)
    {
        $household = $request->user()->household;

        if (! $household->hasPaperless()) {
            return back()->withErrors(['paperless' => 'Paperless n\'est pas encore relié : renseigne-le dans Foyer → Avancé.']);
        }

        $counts = $sync->run($household);

        return $counts['error']
            ? redirect()->route('recipes.imports.index')->withErrors(['paperless' => RecipeScanSync::summary($counts)])
            : redirect()->route('recipes.imports.index')->with('status', RecipeScanSync::summary($counts));
    }

    /** Relecture : le formulaire de recette, prérempli avec ce que le lecteur a compris. */
    public function show(Request $request, RecipeImport $recipeImport)
    {
        $this->authorizeImport($request, $recipeImport);
        $recipeImport->loadMissing('recipe');

        if ($recipeImport->isReading()) {
            return redirect()->route('recipes.imports.index')->with('status', 'Cette fiche est en cours de lecture (environ une minute par page) : la liste se met à jour toute seule.');
        }

        if ($recipeImport->recipe && $request->user()->can('update', $recipeImport->recipe)) {
            return redirect()->route('recipes.edit', $recipeImport->recipe)
                ->with('status', 'Recette créée à partir de la fiche Paperless : relis-la, corrige si besoin, puis décoche « brouillon » pour la publier.');
        }
        if ($recipeImport->recipe) {
            return redirect()->route('recipes.show', $recipeImport->recipe);
        }

        $parsed = $recipeImport->parsed ?? [];
        $fields = $parsed['recipe'] ?? [];
        $recipe = new Recipe([
            'title' => $fields['title'] ?? $recipeImport->title,
            'description' => $fields['description'] ?? null,
            'category' => $fields['category'] ?? 'plat',
            'yield_quantity' => $fields['yield_quantity'] ?? 4,
            'yield_unit' => $fields['yield_unit'] ?? 'personnes',
            'prep_minutes' => $fields['prep_minutes'] ?? null,
            'cook_minutes' => $fields['cook_minutes'] ?? null,
            'rest_minutes' => $fields['rest_minutes'] ?? null,
            'difficulty' => $fields['difficulty'] ?? 'facile',
            'source' => $fields['source'] ?? null,
        ]);

        $form = ScanImporter::formRows($parsed);
        $tagIds = \App\Support\RecipeWriter::tagIds($fields['tags'] ?? []);

        return view('recipes.form', RecipeController::formData($recipe, $form) + [
            'import' => $recipeImport,
            'importText' => ScanImporter::readText($recipeImport),
            'importIssues' => $recipeImport->issues ?? [],
            'importRows' => $form['ingredients'],
            'selectedTags' => array_map('intval', old('tags', $tagIds)),
        ]);
    }

    /** Relit le texte enregistré avec les règles à jour (après l'ajout d'un ingrédient au référentiel, par exemple). */
    public function reanalyse(Request $request, RecipeImport $recipeImport, ScanImporter $importer)
    {
        $this->authorizeImport($request, $recipeImport);
        abort_if($recipeImport->recipe_id !== null, 404);

        // Lecture du scan pas encore faite ou impossible : on la relance (en arrière-plan)
        if (\App\Support\RecipeScan\OcrClient::enabled() && $recipeImport->layout_status !== RecipeImport::LAYOUT_DONE) {
            $recipeImport->forceFill(['layout_status' => RecipeImport::LAYOUT_PENDING, 'layout_error' => null, 'status' => RecipeImport::STATUS_TO_REVIEW])->save();

            return redirect()->route('recipes.imports.index')->with('status', 'Lecture du scan relancée : la fiche revient dans une minute environ.');
        }

        $recipeImport->status = RecipeImport::STATUS_TO_REVIEW;
        $importer->ingest($recipeImport, $request->user()->household);
        $recipeImport->refresh();

        if ($recipeImport->recipe_id) {
            $recipe = $recipeImport->recipe;

            return redirect()->route('recipes.show', $recipe)->with('status', $recipe->isPublished()
                ? 'Fiche relue : tout est reconnu, la recette est publiée.'
                : 'Fiche relue : la recette est créée en brouillon, à relire.');
        }

        return redirect()->route('recipes.imports.show', $recipeImport)->with('status', 'Fiche relue : il reste des ingrédients à compléter.');
    }

    /** « Supprimer » : la fiche est effacée ; « Chercher dans Paperless » la relira depuis zéro tant que le document porte l'étiquette. */
    public function ignore(Request $request, RecipeImport $recipeImport)
    {
        $this->authorizeImport($request, $recipeImport);
        $recipeImport->loadMissing('recipe');

        $reason = ImportDiscarder::discard($recipeImport, $request->user());

        return $reason === null
            ? redirect()->route('recipes.imports.index')->with('status', 'Fiche supprimée. « Chercher dans Paperless » la relira depuis le début tant que le document porte l\'étiquette « '.$request->user()->household->paperlessRecipeTag().' ».')
            : redirect()->route('recipes.imports.index')->withErrors(['paperless' => $reason]);
    }

    /** Supprime d'un coup toutes les fiches de la liste « À relire ». */
    public function discardAll(Request $request)
    {
        $household = $request->user()->household;
        $removed = 0;
        $kept = [];

        foreach ($household->recipeImports()->with('recipe')->get()->filter(fn (RecipeImport $i) => self::needsReview($i)) as $import) {
            $reason = ImportDiscarder::discard($import, $request->user());
            $reason === null ? $removed++ : $kept[] = $reason;
        }

        $message = $removed === 0
            ? 'Aucune fiche à supprimer.'
            : $removed.' fiche'.($removed > 1 ? 's' : '').' supprimée'.($removed > 1 ? 's' : '').' : « Chercher dans Paperless » les relira depuis le début tant que leurs documents portent l\'étiquette.';

        return $kept === []
            ? redirect()->route('recipes.imports.index')->with('status', $message)
            : redirect()->route('recipes.imports.index')->with('status', $message)->withErrors(['paperless' => count($kept).' fiche'.(count($kept) > 1 ? 's' : '').' conservée'.(count($kept) > 1 ? 's' : '').' : '.implode(' ', $kept)]);
    }

    /** À relire : pas encore de recette, ou recette encore en brouillon. */
    public static function needsReview(RecipeImport $import): bool
    {
        return match ($import->status) {
            RecipeImport::STATUS_TO_REVIEW => true,
            RecipeImport::STATUS_CREATED => $import->recipe !== null && ! $import->recipe->isPublished(),
            default => false,
        };
    }

    private function authorizeImport(Request $request, RecipeImport $import): void
    {
        abort_unless($import->household_id === $request->user()->household_id, 404);
    }
}
