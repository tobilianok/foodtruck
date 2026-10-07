<?php

namespace App\Http\Controllers;

use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\SavedMenu;
use App\Support\AntiWaste;
use App\Support\MealPlanner;
use App\Support\Savings;
use App\Support\RecipeServing;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Planning des repas de la semaine (lundi → dimanche), partagé par tout le foyer.
 *
 * v0.22.0 : repas composé : un repas peut réunir plusieurs recettes (plat + accompagnement, entrée, dessert), calculées
 * pour les mêmes convives ; ajout de plusieurs recettes d'un coup, depuis un menu enregistré ou à la suite d'un plat
 * déjà prévu ; modification appliquée à tout le repas.
 */
class PlanningController extends Controller
{
    public const COST_RELATIONS = ['recipe.ingredients.ingredient.packs.prices'];

    public function index(Request $request, ?string $week = null)
    {
        $household = $request->user()->household->loadMissing('members');
        $start = MealPlanner::weekStart($week);

        // Adresse canonique : toujours le lundi
        if ($week !== null && $week !== $start->toDateString()) {
            return redirect()->route('planning.week', $start->toDateString());
        }

        $end = $start->copy()->addDays(6);
        $entries = $household->mealPlanEntries()
            ->with([...self::COST_RELATIONS, 'source.recipe', 'savedMenu'])
            ->where('is_frozen', false)
            ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())
            ->orderBy('position')->orderBy('id')
            ->get();

        $frozen = $household->mealPlanEntries()->with('source.recipe', 'recipe')->where('is_frozen', true)->orderBy('date')->get();
        foreach ($entries->concat($frozen) as $entry) {
            $entry->setRelation('household', $household);
            $entry->source?->setRelation('household', $household);
        }
        $cost = MealPlanner::cost($entries, $household);
        $today = now('Europe/Paris')->toDateString();

        // Présences de la semaine type affichées dans les cases (seulement si tout le foyer n'est pas là)
        $usual = [];
        foreach (MealPlanner::days($start) as $day) {
            foreach ($household->eatingSlots() as $slot) {
                $ids = $household->usualEaters($day, $slot);
                if ($ids !== null) {
                    $usual[$day->toDateString().'|'.$slot] = $household->members->whereIn('id', $ids)->pluck('name')->all();
                }
            }
        }

        return view('planning.index', [
            'household' => $household,
            'start' => $start,
            'days' => MealPlanner::days($start),
            'slots' => $household->mealSlots(),
            'grid' => $entries->groupBy(fn (MealPlanEntry $e) => $e->date->toDateString().'|'.$e->slot),
            'usual' => $usual,
            'entries' => $entries,
            'frozen' => $frozen,
            'cost' => $cost,
            'budget' => $household->weekly_budget_cents,
            'today' => $today,
            'soonLots' => AntiWaste::expiring($household),
            'swaps' => Savings::replacements($household, $request->user(), $entries, $cost, $today),
        ]);
    }

    public function create(Request $request)
    {
        $household = $request->user()->household->loadMissing('members');
        $recipe = $request->filled('recette') ? Recipe::visibleTo($request->user())->where('slug', $request->query('recette'))->first() : null;

        $menu = $request->filled('menu') ? $household->savedMenus()->with('recipes')->find((int) $request->query('menu')) : null;

        $entry = new MealPlanEntry([
            'household_id' => $household->id,
            'date' => $this->dateOrToday($request->query('date')),
            'slot' => in_array($request->query('creneau'), MealPlanEntry::slotCodes(), true) ? $request->query('creneau') : 'diner',
            'kind' => MealPlanEntry::KIND_RECIPE,
            'recipe_id' => $recipe?->id ?? $menu?->recipes->first()?->id,
            'meals' => 1,
        ]);

        // v0.22.0 : ajout à un repas déjà prévu : mêmes convives que son plat (purée calculée comme le jarret)
        $companion = $entry->mealSiblings()->load('recipe')->first(fn (MealPlanEntry $e) => $e->recipe?->yield_unit === 'personnes' && ! $e->isProposal())
            ?? $entry->mealSiblings()->load('recipe')->first(fn (MealPlanEntry $e) => $e->recipe?->yield_unit === 'personnes');
        if ($companion) {
            $entry->fill($companion->only(['eaters', 'guest_adults', 'guest_children', 'meals']));
        }

        return view('planning.form', array_merge($this->formData($request, $entry), [
            'menu' => $menu,
            'extras' => $menu ? array_slice($menu->recipeIds(), 1) : [],
        ]));
    }

    public function store(Request $request)
    {
        $household = $request->user()->household;
        $data = $this->validated($request);
        $extras = $this->extras($request, $data);
        $menuId = $this->menuFor($request, $data, $extras);

        [$entry, $unplaced, $added, $kept] = DB::transaction(function () use ($household, $data, $extras, $menuId, $request) {
            // Ajout à un repas que le menu automatique a proposé : la proposition est gardée (sinon le plat ajouté
            // compterait seul dans les courses, et resterait seul si l'on efface les propositions)
            $kept = [];
            if ($data['kind'] === MealPlanEntry::KIND_RECIPE && $data['batch_quantity'] === null) {
                $probe = new MealPlanEntry(['household_id' => $household->id, 'date' => $data['date'], 'slot' => $data['slot']]);
                foreach ($probe->mealSiblings()->filter(fn (MealPlanEntry $e) => $e->isProposal()) as $proposal) {
                    \App\Support\MenuGenerator::keep($proposal);
                    $kept[] = $proposal->recipe?->title;
                }
            }

            $position = (int) $household->mealPlanEntries()->whereDate('date', $data['date'])->where('slot', $data['slot'])->max('position');
            $created = [];
            foreach ([$data['recipe_id'], ...$extras->pluck('id')] as $i => $recipeId) {
                $position += 10;
                // Recettes ajoutées au plat : mêmes convives et même nombre de repas, sans réglage de parts ni fournée
                $row = $i === 0 ? $data : array_merge($data, ['recipe_id' => $recipeId, 'parts_manual' => null, 'batch_quantity' => null]);
                $created[] = MealPlanEntry::create($row + [
                    'household_id' => $household->id,
                    'saved_menu_id' => $menuId,
                    'created_by' => $request->user()->id,
                    'position' => $position,
                ]);
            }
            $unplaced = 0;
            foreach ($created as $entry) {
                $entry->setRelation('household', $household);
                $unplaced += MealPlanner::placeLeftovers($entry);
            }

            return [$created[0], $unplaced, $created, array_filter($kept)];
        });

        $headline = count($added) > 1 ? collect($added)->map(fn (MealPlanEntry $e) => '« '.$e->recipe->title.' »')->join(', ', ' et ').' ajoutés' : null;
        $message = $this->message($entry, 'ajouté', $unplaced, $headline);
        if ($kept !== []) {
            $message .= ' Plat proposé gardé avec : « '.implode(' », « ', $kept).' ».';
        }

        return $this->backToWeek($entry, $message);
    }

    /**
     * v0.22.0 : recettes servies avec le plat (accompagnement, entrée, dessert), dans l'ordre choisi. Seulement des
     * recettes en portions, visibles du foyer, sans doublon, et seulement quand le plat lui-même est en portions.
     *
     * @return \Illuminate\Support\Collection<int, Recipe>
     */
    private function extras(Request $request, array $data): \Illuminate\Support\Collection
    {
        $request->validate([
            'avec' => ['nullable', 'array', 'max:'.(SavedMenu::MAX_RECIPES - 1)],
            'avec.*' => ['integer'],
        ], ['avec.max' => 'Au plus '.(SavedMenu::MAX_RECIPES - 1).' recettes en plus du plat.']);

        $ids = collect($request->input('avec', []))->map(fn ($id) => (int) $id)->reject(fn ($id) => $id === (int) $data['recipe_id'])->unique()->values();
        if ($ids->isEmpty() || $data['kind'] !== MealPlanEntry::KIND_RECIPE || $data['batch_quantity'] !== null) {
            return collect();
        }

        $recipes = Recipe::visibleTo($request->user())->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            $recipe = $recipes->get($id);
            if ($recipe === null) {
                throw ValidationException::withMessages(['avec' => 'Recette introuvable parmi celles ajoutées au repas.']);
            }
            if ($recipe->yield_unit !== 'personnes') {
                throw ValidationException::withMessages(['avec' => '« '.$recipe->title.' » se prépare en fournée ('.$recipe->yieldLabel().') : ajoute-la à part, dans « À préparer ».']);
            }
        }

        return $ids->map(fn ($id) => $recipes->get($id));
    }

    /** Menu enregistré d'où viennent les recettes, s'il correspond toujours (mêmes recettes). */
    private function menuFor(Request $request, array $data, \Illuminate\Support\Collection $extras): ?int
    {
        if (! $request->filled('menu_id') || $data['kind'] !== MealPlanEntry::KIND_RECIPE) {
            return null;
        }
        $menu = $request->user()->household->savedMenus()->with('recipes')->find((int) $request->input('menu_id'));
        if ($menu === null) {
            return null;
        }
        $chosen = [(int) $data['recipe_id'], ...$extras->pluck('id')->map(fn ($id) => (int) $id)];
        $wanted = $menu->recipeIds();
        sort($chosen);
        sort($wanted);

        return $chosen === $wanted ? $menu->id : null;
    }

    public function edit(Request $request, MealPlanEntry $entry)
    {
        $this->authorizeEntry($request, $entry);

        return view('planning.form', $this->formData($request, $entry));
    }

    public function update(Request $request, MealPlanEntry $entry)
    {
        $this->authorizeEntry($request, $entry);

        // Restes : seuls le jour et le créneau changent (ou la sortie du congélateur)
        if ($entry->isLeftover()) {
            $data = $request->validate([
                'date' => ['required', 'date_format:Y-m-d', ...$this->dateBounds()],
                'slot' => ['required', Rule::in(MealPlanEntry::slotCodes())],
            ], [], ['date' => 'jour', 'slot' => 'repas']);
            $entry->update($data + ['is_frozen' => false]);

            return $this->backToWeek($entry, 'Restes déplacés au '.$this->when($entry).'.');
        }

        $data = $this->validated($request);
        // v0.22.0 : les autres plats du repas suivent (jour, repas, convives), sauf si on décoche « tout le repas »
        $siblings = $entry->isRecipe() && $request->boolean('tout_le_repas') ? $entry->mealSiblings() : collect();

        $unplaced = DB::transaction(function () use ($entry, $data, $siblings) {
            // Modifier un plat proposé par le menu automatique, c'est le garder ; changer de recette le détache du menu
            $entry->update($data + ['proposed_at' => null, 'proposal_reason' => null]
                + ((int) $data['recipe_id'] !== (int) $entry->recipe_id ? ['saved_menu_id' => null] : []));

            // Tout le repas d'abord, les restes ensuite : ceux d'un repas composé restent ensemble
            foreach ($siblings as $sibling) {
                $shared = ['date' => $data['date'], 'slot' => $data['slot'], 'proposed_at' => null, 'proposal_reason' => null];
                if ($data['kind'] === MealPlanEntry::KIND_RECIPE && $data['batch_quantity'] === null) {
                    $shared += ['eaters' => $data['eaters'], 'guest_adults' => $data['guest_adults'], 'guest_children' => $data['guest_children']];
                }
                $sibling->update($shared);
                $sibling->leftovers()->update(['proposed_at' => null, 'proposal_reason' => null]);
            }

            $unplaced = 0;
            foreach ([$entry, ...$siblings] as $dish) {
                $unplaced += MealPlanner::placeLeftovers($dish->fresh());
            }

            return $unplaced;
        });

        $message = $this->message($entry->fresh(), 'modifié', $unplaced);
        if ($siblings->isNotEmpty()) {
            $message .= ' Aussi appliqué à '.$siblings->map(fn (MealPlanEntry $e) => '« '.$e->recipe?->title.' »')->join(', ', ' et ').'.';
        }

        return $this->backToWeek($entry, $message);
    }

    /** Remplace la recette d'un plat par une autre (plat trop cher) : convives, jour et créneau sont conservés. */
    public function replace(Request $request, MealPlanEntry $entry)
    {
        $this->authorizeEntry($request, $entry);
        abort_unless($entry->isRecipe() && ! $entry->isLeftover() && $entry->batch_quantity === null, 404);

        $data = $request->validate(['recipe_id' => ['required', 'integer']]);
        $recipe = Recipe::visibleTo($request->user())->where('status', Recipe::STATUS_PUBLISHED)->where('yield_unit', 'personnes')->find($data['recipe_id']);
        abort_if($recipe === null, 404);

        $old = $entry->recipe;
        $unplaced = DB::transaction(function () use ($entry, $recipe) {
            $entry->update(['recipe_id' => $recipe->id, 'saved_menu_id' => null]);
            $entry->leftovers()->update(['recipe_id' => $recipe->id]);

            return MealPlanner::placeLeftovers($entry->fresh());
        });

        return $this->backToWeek($entry, '« '.$old->title.' » remplacé par « '.$recipe->title.' » ('.$this->when($entry->fresh()).'). La liste de courses se met à jour avec le planning.'
            .($unplaced > 0 ? ' '.$unplaced.' reste'.($unplaced > 1 ? 's' : '').' mis de côté faute de créneau libre.' : ''));
    }

    public function destroy(Request $request, MealPlanEntry $entry)
    {
        $this->authorizeEntry($request, $entry);

        $composed = $entry->isMealDish() && $entry->mealSiblings()->isNotEmpty();
        DB::transaction(function () use ($entry) {
            $entry->leftovers()->delete();
            $entry->delete();
        });

        return $this->backToWeek($entry, match (true) {
            $entry->isLeftover() => 'Restes retirés du planning.',
            $composed => '« '.$entry->recipe->title.' » retiré de ce repas (avec ses restes) ; le reste du repas est conservé.',
            default => 'Repas retiré du planning (avec ses restes).',
        });
    }

    /** Restes mis au congélateur (ils quittent la grille) ou ressortis sur leur créneau. */
    public function freeze(Request $request, MealPlanEntry $entry)
    {
        $this->authorizeEntry($request, $entry);
        abort_unless($entry->isLeftover(), 404);

        $entry->update(['is_frozen' => ! $entry->is_frozen]);

        return $this->backToWeek($entry, $entry->is_frozen
            ? 'Restes mis au congélateur : planifie-les quand tu veux depuis « Restes mis de côté ».'
            : 'Restes replacés au '.$this->when($entry).'.');
    }

    private function validated(Request $request): array
    {
        $household = $request->user()->household->loadMissing('members');
        $memberIds = $household->members->pluck('id')->all();

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', ...$this->dateBounds()],
            'slot' => ['required', Rule::in(MealPlanEntry::slotCodes())],
            'kind' => ['required', Rule::in([MealPlanEntry::KIND_RECIPE, MealPlanEntry::KIND_OUT, MealPlanEntry::KIND_NOTE])],
            'recipe_id' => ['nullable', 'required_if:kind,'.MealPlanEntry::KIND_RECIPE, 'integer'],
            'qui' => ['nullable', 'array'],
            'qui.*' => ['integer', Rule::in($memberIds)],
            'adultes' => ['nullable', 'integer', 'min:0', 'max:'.RecipeServing::MAX_GUESTS],
            'enfants' => ['nullable', 'integer', 'min:0', 'max:'.RecipeServing::MAX_GUESTS],
            'repas' => ['nullable', 'integer', 'min:1', 'max:'.RecipeServing::MAX_MEALS],
            'parts' => ['nullable', 'numeric', 'min:0.5', 'max:50'],
            'quantite' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:120', 'required_if:kind,'.MealPlanEntry::KIND_NOTE],
        ], [
            'recipe_id.required_if' => 'Choisis une recette.',
            'note.required_if' => 'Écris la note (ex. « pizza surgelée »).',
        ], ['date' => 'jour', 'slot' => 'repas', 'adultes' => 'invités adultes', 'enfants' => 'enfants invités', 'parts' => 'parts par repas', 'quantite' => 'quantité']);

        $recipe = null;
        if ($data['kind'] === MealPlanEntry::KIND_RECIPE) {
            $recipe = Recipe::visibleTo($request->user())->find($data['recipe_id']);
            if ($recipe === null) {
                throw ValidationException::withMessages(['recipe_id' => 'Recette introuvable.']);
            }
        }

        $portions = $recipe?->yield_unit === 'personnes';
        $eaters = array_values(array_map('intval', $data['qui'] ?? []));
        sort($eaters);
        // Convives identiques à la semaine type (ou à tout le foyer) : rien d'enregistré, le repas suit la semaine type
        $usual = $household->usualEaters(Carbon::createFromFormat('Y-m-d', $data['date']), $data['slot']) ?? $memberIds;
        $usual = array_values(array_map('intval', $usual));
        sort($usual);

        return [
            'date' => $data['date'],
            'slot' => $data['slot'],
            'kind' => $data['kind'],
            'recipe_id' => $recipe?->id,
            'eaters' => $portions && $request->boolean('ajuste') && $eaters !== $usual ? $eaters : null,
            'guest_adults' => $portions ? (int) ($data['adultes'] ?? 0) : 0,
            'guest_children' => $portions ? (int) ($data['enfants'] ?? 0) : 0,
            'meals' => $portions ? (int) ($data['repas'] ?? 1) : 1,
            'parts_manual' => $portions && ! empty($data['parts']) ? round((float) $data['parts'] * 2) / 2 : null,
            'batch_quantity' => $recipe && ! $portions ? (float) ($data['quantite'] ?? $recipe->yield_quantity) : null,
            'note' => $data['kind'] === MealPlanEntry::KIND_RECIPE ? null : (trim((string) ($data['note'] ?? '')) ?: null),
        ];
    }

    private function formData(Request $request, MealPlanEntry $entry): array
    {
        $household = $request->user()->household->loadMissing('members');
        $recipes = Recipe::visibleTo($request->user())->orderBy('title')->get(['id', 'title', 'slug', 'category', 'yield_quantity', 'yield_unit', 'status']);

        $all = $household->members->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Semaine type : membres présents par repas et jour, pour pré-cocher les convives quand on change de jour ou de repas
        $usualMap = [];
        foreach (MealPlanEntry::EATING_SLOTS as $slot) {
            foreach (range(1, 7) as $weekday) {
                $usualMap[$slot][$weekday] = array_values(array_diff($all, array_map('intval', $household->usual_absences[$slot][(string) $weekday] ?? [])));
            }
        }

        $usualEaters = $entry->date ? $household->usualEaters($entry->date, (string) $entry->slot) : null;

        // v0.22.0 : recettes proposées « avec » le plat (portions seulement), accompagnements et entrées d'abord
        $order = array_flip(['accompagnement', 'entree', 'dessert', 'plat', 'petit-dejeuner', 'gouter', 'boisson', 'base']);
        $companions = $recipes->where('yield_unit', 'personnes')->groupBy('category')
            ->sortBy(fn ($items, $category) => $order[$category] ?? 99);

        return [
            'entry' => $entry,
            'household' => $household,
            'recipes' => $recipes->groupBy('category'),
            'companions' => $companions,
            'menus' => $household->savedMenus()->with('recipes:id,title')->get(),
            'siblings' => $entry->date && $entry->slot ? $entry->mealSiblings()->load('recipe:id,title') : collect(),
            'menu' => null,
            'extras' => [],
            // Repas affichés par le foyer (plus celui du repas modifié s'il a été masqué depuis)
            'slots' => array_filter(MealPlanEntry::SLOTS, fn ($code) => in_array($code, $household->mealSlots(), true) || $code === $entry->slot, ARRAY_FILTER_USE_KEY),
            'eaters' => $entry->eaters ?? $usualEaters ?? $all,
            'followsUsual' => $entry->eaters === null && $usualEaters !== null,
            'usualMap' => $usualMap,
            'allMembers' => $all,
        ];
    }

    private function authorizeEntry(Request $request, MealPlanEntry $entry): void
    {
        abort_unless($entry->household_id === $request->user()->household_id, 404);
    }

    /** Planning limité à une fenêtre raisonnable (historique récent, un trimestre à venir). */
    private function dateBounds(): array
    {
        $today = now('Europe/Paris');

        return ['after_or_equal:'.$today->copy()->subDays(90)->toDateString(), 'before_or_equal:'.$today->copy()->addDays(120)->toDateString()];
    }

    private function dateOrToday(?string $value): string
    {
        try {
            return $value ? Carbon::createFromFormat('Y-m-d', $value)->toDateString() : now('Europe/Paris')->toDateString();
        } catch (\Throwable) {
            return now('Europe/Paris')->toDateString();
        }
    }

    private function when(MealPlanEntry $entry): string
    {
        return mb_strtolower($entry->slotLabel()).' du '.$entry->date->locale('fr')->isoFormat('dddd D MMMM');
    }

    private function message(MealPlanEntry $entry, string $verb, int $unplaced, ?string $headline = null): string
    {
        $what = match ($entry->kind) {
            MealPlanEntry::KIND_RECIPE => '« '.$entry->recipe->title.' »',
            MealPlanEntry::KIND_OUT => 'Repas hors maison',
            default => 'Note',
        };
        $message = ($headline ?? "{$what} {$verb}").' : '.$this->when($entry).'.';

        $leftovers = $entry->leftovers()->where('is_frozen', false)->get();
        if ($leftovers->isNotEmpty()) {
            $message .= ' Restes prévus : '.$leftovers->map(fn ($l) => $this->when($l))->join(', ', ' et ').'.';
        }
        if ($unplaced > 0) {
            $message .= " {$unplaced} repas de restes sans créneau libre : mis de côté dans « Restes à placer », à planifier quand tu veux.";
        }

        return $message;
    }

    private function backToWeek(MealPlanEntry $entry, string $message)
    {
        return redirect()->route('planning.week', MealPlanner::weekStart($entry->date->toDateString())->toDateString())
            ->with('status', $message);
    }
}
