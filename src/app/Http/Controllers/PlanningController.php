<?php

namespace App\Http\Controllers;

use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Support\AntiWaste;
use App\Support\MealPlanner;
use App\Support\RecipeServing;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Planning des repas de la semaine (lundi → dimanche), partagé par tout le foyer.
 */
class PlanningController extends Controller
{
    private const COST_RELATIONS = ['recipe.ingredients.ingredient.packs.prices'];

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
            ->with([...self::COST_RELATIONS, 'source.recipe'])
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
            'today' => now('Europe/Paris')->toDateString(),
            'soonLots' => AntiWaste::expiring($household),
        ]);
    }

    public function create(Request $request)
    {
        $household = $request->user()->household->loadMissing('members');
        $recipe = $request->filled('recette') ? Recipe::visibleTo($request->user())->where('slug', $request->query('recette'))->first() : null;

        $entry = new MealPlanEntry([
            'date' => $this->dateOrToday($request->query('date')),
            'slot' => in_array($request->query('creneau'), MealPlanEntry::slotCodes(), true) ? $request->query('creneau') : 'diner',
            'kind' => MealPlanEntry::KIND_RECIPE,
            'recipe_id' => $recipe?->id,
            'meals' => 1,
        ]);

        return view('planning.form', $this->formData($request, $entry));
    }

    public function store(Request $request)
    {
        $household = $request->user()->household;
        $data = $this->validated($request);

        $entry = DB::transaction(function () use ($household, $data, $request) {
            $entry = MealPlanEntry::create($data + [
                'household_id' => $household->id,
                'created_by' => $request->user()->id,
                'position' => (int) $household->mealPlanEntries()->whereDate('date', $data['date'])->where('slot', $data['slot'])->max('position') + 10,
            ]);
            $entry->setRelation('household', $household);
            $unplaced = MealPlanner::placeLeftovers($entry);

            return [$entry, $unplaced];
        });

        return $this->backToWeek($entry[0], $this->message($entry[0], 'ajouté', $entry[1]));
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
        $unplaced = DB::transaction(function () use ($entry, $data) {
            $entry->update($data);

            return MealPlanner::placeLeftovers($entry->fresh());
        });

        return $this->backToWeek($entry, $this->message($entry->fresh(), 'modifié', $unplaced));
    }

    public function destroy(Request $request, MealPlanEntry $entry)
    {
        $this->authorizeEntry($request, $entry);

        DB::transaction(function () use ($entry) {
            $entry->leftovers()->delete();
            $entry->delete();
        });

        return $this->backToWeek($entry, $entry->isLeftover() ? 'Restes retirés du planning.' : 'Repas retiré du planning (avec ses restes).');
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

        return [
            'entry' => $entry,
            'household' => $household,
            'recipes' => $recipes->groupBy('category'),
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

    private function message(MealPlanEntry $entry, string $verb, int $unplaced): string
    {
        $what = match ($entry->kind) {
            MealPlanEntry::KIND_RECIPE => '« '.$entry->recipe->title.' »',
            MealPlanEntry::KIND_OUT => 'Repas hors maison',
            default => 'Note',
        };
        $message = "{$what} {$verb} : ".$this->when($entry).'.';

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
