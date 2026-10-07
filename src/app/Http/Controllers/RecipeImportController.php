<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Models\RecipeImport;
use App\Support\RecipeScan\ImportDiscarder;
use App\Support\RecipeScan\PagesClient;
use App\Support\RecipeScan\VisionClient;
use App\Support\Receipts\PaperlessClient;
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
            'busy' => self::busy(),
            'done' => $imports->filter(fn (RecipeImport $i) => $i->status === RecipeImport::STATUS_CREATED && ! self::needsReview($i))->values(),
        ]);
    }

    /**
     * v0.18.0 : avancement des lectures en cours (lu toutes les 3 secondes par la page « Fiches Paperless »).
     * Les fiches en attente derrière celle qui est lue ont « progress » à null.
     */
    public function progress(Request $request)
    {
        $reading = $request->user()->household->recipeImports()
            ->where('layout_status', RecipeImport::LAYOUT_PENDING)->whereNull('recipe_id')->orderBy('id')->get()
            ->filter->isReading()->values();

        return response()->json(['reading' => $reading->map(fn (RecipeImport $i) => [
            'id' => $i->id,
            'progress' => $i->layout_progress,
            'step' => $i->layout_step ?? self::waiting($i),
            'since' => $i->layout_started_at ? (int) abs($i->layout_started_at->diffInSeconds(now())) : null,
        ])->all()]);
    }

    /** Fiche envoyée mais pas encore prise en charge (le planificateur passe chaque minute). */
    public static function waiting(RecipeImport $import): string
    {
        return 'Envoyée : l\'analyse démarre dans moins d\'une minute';
    }

    /** v0.18.0 : un seul document à la fois chez Ollama (tous foyers confondus : un seul PC) ; tickets compris depuis la v0.19.0. */
    public static function busy(?RecipeImport $except = null): RecipeImport|\App\Models\Receipt|null
    {
        return \App\Support\VisionQueue::busy($except);
    }

    /**
     * v0.18.0 : page de contrôle avant l'envoi à l'IA : destination, modèle, images exactes des pages (cases à cocher),
     * consigne et format de réponse. Rien n'est envoyé à Ollama ici.
     */
    public function ai(Request $request, RecipeImport $recipeImport)
    {
        $this->authorizeImport($request, $recipeImport);
        abort_if($recipeImport->recipe_id !== null || ! VisionClient::ready(), 404);
        if ($recipeImport->isReading()) {
            return redirect()->route('recipes.imports.index')->with('status', 'Cette fiche est déjà envoyée : suis son avancement ici.');
        }

        $pages = [];
        $error = null;
        try {
            $file = PaperlessClient::for($recipeImport->household)->download((int) $recipeImport->paperless_document_id);
            // Aperçu à 100 dpi : mêmes pages, envoyées à Ollama en 200 dpi
            $pages = PagesClient::make()->pages($file['body'], $file['mime'], 100);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $vision = VisionClient::make();

        return view('recipes.ai', [
            'import' => $recipeImport,
            'pages' => $pages,
            'error' => $error,
            'busy' => self::busy($recipeImport),
            'destination' => (string) config('foodtruck.vision_url'),
            'model' => $vision->model(),
            'dpi' => (int) config('foodtruck.vision_dpi', 200),
            'prompt' => VisionClient::PROMPT,
            'schema' => json_encode(VisionClient::schema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'selected' => (array) ($recipeImport->layout_pages ?? []),
        ]);
    }

    /** Envoi confirmé par Louis : la fiche part en file (le planificateur la prend dans la minute), pages choisies. */
    public function aiSend(Request $request, RecipeImport $recipeImport)
    {
        $this->authorizeImport($request, $recipeImport);
        abort_if($recipeImport->recipe_id !== null || ! VisionClient::ready(), 404);
        $data = $request->validate([
            'pages' => ['required', 'array', 'min:1', 'max:4'],
            'pages.*' => ['integer', 'between:0,3', 'distinct'],
        ], ['pages.required' => 'Coche au moins une page à envoyer.']);

        if ($recipeImport->isReading()) {
            return redirect()->route('recipes.imports.index')->with('status', 'Cette fiche est déjà envoyée.');
        }
        if ($other = self::busy($recipeImport)) {
            return back()->withErrors(['pages' => 'L\'IA est déjà occupée avec '.\App\Support\VisionQueue::describe($other).' : un document à la fois.']);
        }

        $pages = array_values(array_map('intval', $data['pages']));
        sort($pages);
        $recipeImport->forceFill([
            'layout_status' => RecipeImport::LAYOUT_PENDING, 'layout_pages' => $pages, 'layout_error' => null,
            'layout_progress' => null, 'layout_step' => null, 'layout_started_at' => null, 'status' => RecipeImport::STATUS_TO_REVIEW,
        ])->save();

        return redirect()->route('recipes.imports.index')->with('status', 'Fiche envoyée à l\'IA ('.count($pages).' page'.(count($pages) > 1 ? 's' : '').') : l\'analyse démarre dans moins d\'une minute.');
    }

    /** Annule un envoi tant que l'analyse n'a pas commencé. */
    public function aiCancel(Request $request, RecipeImport $recipeImport)
    {
        $this->authorizeImport($request, $recipeImport);
        $recipeImport->refresh();
        if ($recipeImport->layout_status !== RecipeImport::LAYOUT_PENDING || $recipeImport->layout_progress !== null) {
            return redirect()->route('recipes.imports.index')->withErrors(['paperless' => 'L\'analyse a déjà commencé : elle ne peut plus être annulée.']);
        }
        $recipeImport->forceFill(['layout_status' => RecipeImport::LAYOUT_TO_SEND])->save();

        return redirect()->route('recipes.imports.index')->with('status', 'Envoi annulé : rien n\'est parti vers l\'IA.');
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
            return redirect()->route('recipes.imports.index')->with('status', 'Cette fiche est en cours d\'analyse : suis son avancement ici.');
        }
        // v0.18.0 : fiche jamais lue par l'IA : page de contrôle avant l'envoi
        if ($recipeImport->recipe_id === null && $recipeImport->canBeSent() && ! ScanImporter::readByVision($recipeImport)
            && $recipeImport->layout_status !== RecipeImport::LAYOUT_FAILED) {
            return redirect()->route('recipes.imports.ai', $recipeImport);
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

        // Fiche pas encore lue par l'IA : rien n'est envoyé sans passer par la page de contrôle (v0.18.0)
        if (VisionClient::ready() && ! ScanImporter::readByVision($recipeImport)) {
            return redirect()->route('recipes.imports.ai', $recipeImport);
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
