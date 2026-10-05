<?php

namespace App\Support;

use App\Models\Household;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Menu automatique : propose les déjeuners et dîners libres d'une semaine.
 *
 * Les repas déjà au planning sont conservés et comptent (budget, protéines, végétarien). Les plats proposés sont
 * des entrées du planning marquées « proposed_at » : ils n'entrent dans aucune liste de courses tant qu'ils ne sont
 * pas gardés ou validés. Le choix de chaque repas est fait par MenuScorer, en suivant l'ordre de la semaine.
 */
class MenuGenerator
{
    /** Repas remplis automatiquement (dans l'ordre de la journée). */
    public const SLOTS = ['dejeuner', 'diner'];

    private const HISTORY_DAYS = 28;

    private const MAX_REASONS = 3;

    /**
     * (Re)propose la semaine : les anciennes propositions sont remplacées, tout le reste est conservé.
     *
     * @return array{created: int, unfilled: int, cost_cents: float, over_budget: bool, replaced: int, no_recipes: bool}
     */
    public static function generate(Household $household, User $user, Carbon $weekStart, ?int $veggyMin = null, ?int $seed = null): array
    {
        $household->loadMissing('members', 'equipment');
        $weekEnd = $weekStart->copy()->addDays(6);
        $seed ??= random_int(1, 1_000_000);
        $veggyMin ??= (int) $household->menu_veggy_min;
        $today = now('Europe/Paris')->startOfDay();
        $now = now();

        return DB::transaction(function () use ($household, $user, $weekStart, $weekEnd, $veggyMin, $seed, $today, $now) {
            $query = $household->mealPlanEntries()->whereNotNull('proposed_at')
                ->whereDate('date', '>=', $weekStart->toDateString())->whereDate('date', '<=', $weekEnd->toDateString());
            $replaced = (clone $query)->where('kind', MealPlanEntry::KIND_RECIPE)->count();
            $query->delete();

            $pool = self::pool($household, $user, $weekStart);
            $week = self::weekEntries($household, $weekStart, $weekEnd);
            $dishes = $week->filter(fn (MealPlanEntry $e) => $e->isRecipe());

            $used = $dishes->pluck('recipe_id')->all();
            $timeline = $dishes->map(fn (MealPlanEntry $e) => self::profile($e->recipe) + ['key' => $e->sortKey(), 'id' => $e->recipe_id])->values()->all();
            $spent = MealPlanner::cost($dishes, $household)['total_cents'];
            $budget = (int) $household->weekly_budget_cents;
            $slots = array_values(array_intersect(self::SLOTS, $household->mealSlots()));

            $created = 0;
            $chosenCost = 0.0;

            foreach (MealPlanner::days($weekStart) as $day) {
                if ($day->lt($today)) {
                    continue;
                }

                foreach ($slots as $slot) {
                    $occupied = self::occupied($household, $weekStart, $weekEnd);
                    $key = $day->toDateString().'|'.$slot;
                    $eaters = $household->usualEaters($day, $slot);

                    if (isset($occupied[$key]) || $eaters === []) {
                        continue;
                    }

                    $meals = self::leftoverFits($household, $day, $slot, $weekEnd, $occupied) ? 2 : 1;
                    $info = self::slotInfo($household, $day, $slot, $eaters, $meals);
                    $state = self::state($household, $day, $slot, $budget, $budget - $spent - $chosenCost, self::freeSlots($household, $weekStart, $weekEnd, $today, $slots, $occupied, $key), $veggyMin, $timeline);
                    $candidates = self::candidates($pool, $used, $day, $weekStart);

                    $ranked = MenuScorer::rank($candidates, $info, $state, $seed);
                    if ($ranked === []) {
                        continue;
                    }

                    $best = $ranked[0];
                    /** @var Recipe $recipe */
                    $recipe = $pool[$best['candidate']['id']]['recipe'];

                    $entry = MealPlanEntry::create([
                        'household_id' => $household->id,
                        'date' => $day->toDateString(),
                        'slot' => $slot,
                        'position' => 10,
                        'kind' => MealPlanEntry::KIND_RECIPE,
                        'recipe_id' => $recipe->id,
                        'meals' => $meals,
                        'created_by' => $user->id,
                        'proposed_at' => $now,
                        'proposal_reason' => self::reason($best['reasons']),
                    ]);
                    $entry->setRelation('household', $household);
                    MealPlanner::placeLeftovers($entry);

                    $used[] = $recipe->id;
                    $timeline[] = ['protein' => $best['candidate']['protein'], 'veggy' => $best['candidate']['veggy'], 'key' => $entry->sortKey(), 'id' => $recipe->id];
                    $chosenCost += $best['cost_cents'] ?? 0.0;
                    $created++;
                }
            }

            $occupied = self::occupied($household, $weekStart, $weekEnd);
            $unfilled = self::freeSlots($household, $weekStart, $weekEnd, $today, $slots, $occupied, null);
            $total = $spent + $chosenCost;

            return [
                'created' => $created,
                'unfilled' => $unfilled,
                'cost_cents' => $total,
                'over_budget' => $budget > 0 && $total > $budget,
                'replaced' => $replaced,
                'no_recipes' => $pool === [],
            ];
        });
    }

    /**
     * Remplace le plat proposé d'un repas par une autre idée (le meilleur des autres plats, avec un peu d'aléa).
     * Les restes de ce plat suivent. Renvoie la nouvelle recette, ou null si rien d'autre ne convient.
     */
    public static function reroll(MealPlanEntry $entry, User $user, ?int $seed = null): ?Recipe
    {
        $household = $entry->household->loadMissing('members', 'equipment');
        $day = $entry->date->copy();
        $weekStart = MealPlanner::weekStart($day->toDateString());
        $weekEnd = $weekStart->copy()->addDays(6);
        $seed ??= random_int(1, 1_000_000);

        $pool = self::pool($household, $user, $weekStart);
        $others = self::weekEntries($household, $weekStart, $weekEnd)
            ->reject(fn (MealPlanEntry $e) => $e->id === $entry->id || $e->source_entry_id === $entry->id);
        $dishes = $others->filter(fn (MealPlanEntry $e) => $e->isRecipe());

        $used = array_merge($dishes->pluck('recipe_id')->all(), [$entry->recipe_id]);
        $timeline = $dishes->map(fn (MealPlanEntry $e) => self::profile($e->recipe) + ['key' => $e->sortKey(), 'id' => $e->recipe_id])->values()->all();
        $spent = MealPlanner::cost($dishes, $household)['total_cents'];
        $budget = (int) $household->weekly_budget_cents;

        $eaters = $entry->eaters ?? $household->usualEaters($day, $entry->slot);
        $info = self::slotInfo($household, $day, $entry->slot, $eaters, max(1, (int) $entry->meals));
        $state = self::state($household, $day, $entry->slot, $budget, $budget - $spent, max(1, (int) $entry->meals), (int) $household->menu_veggy_min, $timeline, $entry->sortKey());

        $ranked = MenuScorer::rank(self::candidates($pool, $used, $day, $weekStart), $info, $state, $seed);
        if ($ranked === []) {
            return null;
        }

        $best = $ranked[0];
        $recipe = $pool[$best['candidate']['id']]['recipe'];
        $reason = self::reason($best['reasons']);

        DB::transaction(function () use ($entry, $recipe, $reason) {
            $entry->update(['recipe_id' => $recipe->id, 'proposal_reason' => $reason]);
            $entry->leftovers()->update(['recipe_id' => $recipe->id, 'proposal_reason' => $reason]);
        });

        return $recipe;
    }

    /** Valide toutes les propositions de la semaine : elles deviennent de vrais repas du planning. */
    public static function accept(Household $household, Carbon $weekStart): int
    {
        $count = self::proposals($household, $weekStart)->where('kind', MealPlanEntry::KIND_RECIPE)->count();
        self::proposals($household, $weekStart)->update(['proposed_at' => null, 'proposal_reason' => null]);

        return $count;
    }

    /** Efface toutes les propositions de la semaine (restes compris) ; les vrais repas ne sont pas touchés. */
    public static function clear(Household $household, Carbon $weekStart): int
    {
        $count = self::proposals($household, $weekStart)->where('kind', MealPlanEntry::KIND_RECIPE)->count();
        self::proposals($household, $weekStart)->delete();

        return $count;
    }

    /** Garde un plat proposé (et ses restes) : il devient un vrai repas. */
    public static function keep(MealPlanEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $entry->leftovers()->update(['proposed_at' => null, 'proposal_reason' => null]);
            $entry->update(['proposed_at' => null, 'proposal_reason' => null]);
        });
    }

    /** @return \Illuminate\Database\Eloquent\Builder<MealPlanEntry> */
    private static function proposals(Household $household, Carbon $weekStart)
    {
        return $household->mealPlanEntries()->whereNotNull('proposed_at')
            ->whereDate('date', '>=', $weekStart->toDateString())->whereDate('date', '<=', $weekStart->copy()->addDays(6)->toDateString());
    }

    // ------------------------------------------------------------------ données de la semaine

    /** Repas de la semaine (hors congélateur), avec ce qu'il faut pour estimer leur coût. @return Collection<int, MealPlanEntry> */
    private static function weekEntries(Household $household, Carbon $weekStart, Carbon $weekEnd): Collection
    {
        $entries = $household->mealPlanEntries()
            ->with([...\App\Http\Controllers\PlanningController::COST_RELATIONS, 'recipe.tags', 'source.recipe'])
            ->where('is_frozen', false)
            ->whereDate('date', '>=', $weekStart->toDateString())->whereDate('date', '<=', $weekEnd->toDateString())
            ->orderBy('date')->orderBy('id')->get();

        foreach ($entries as $entry) {
            $entry->setRelation('household', $household);
            $entry->source?->setRelation('household', $household);
        }

        return $entries;
    }

    /** Créneaux déjà pris de la semaine (« date|créneau » => true). @return array<string, true> */
    private static function occupied(Household $household, Carbon $weekStart, Carbon $weekEnd): array
    {
        return $household->mealPlanEntries()->where('is_frozen', false)
            ->whereDate('date', '>=', $weekStart->toDateString())->whereDate('date', '<=', $weekEnd->toDateString())
            ->get(['date', 'slot'])
            ->mapWithKeys(fn (MealPlanEntry $e) => [$e->date->toDateString().'|'.$e->slot => true])->all();
    }

    /**
     * Repas encore libres à partir d'un repas (celui-ci compris), où quelqu'un mange à la maison.
     *
     * @param  array<string, true>  $occupied
     */
    private static function freeSlots(Household $household, Carbon $weekStart, Carbon $weekEnd, Carbon $today, array $slots, array $occupied, ?string $from): int
    {
        $count = 0;
        $started = $from === null;

        foreach (MealPlanner::days($weekStart) as $day) {
            if ($day->lt($today)) {
                continue;
            }
            foreach ($slots as $slot) {
                $key = $day->toDateString().'|'.$slot;
                if (! $started) {
                    $started = $key === $from;
                }
                if ($started && ! isset($occupied[$key]) && $household->usualEaters($day, $slot) !== []) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /** Un dîner peut servir deux repas si le déjeuner du lendemain est libre, dans la semaine, avec quelqu'un à la maison. */
    private static function leftoverFits(Household $household, Carbon $day, string $slot, Carbon $weekEnd, array $occupied): bool
    {
        if ($slot !== 'diner' || ! in_array('dejeuner', $household->mealSlots(), true)) {
            return false;
        }

        $next = $day->copy()->addDay();

        return ! $next->gt($weekEnd)
            && $household->usualEaters($next, 'dejeuner') !== []
            && ! isset($occupied[$next->toDateString().'|dejeuner']);
    }

    // ------------------------------------------------------------------ préparation pour MenuScorer

    /** Recettes candidates : plats publiés pour des personnes, avec les appareils du foyer, et leurs informations fixes. */
    private static function pool(Household $household, User $user, Carbon $weekStart): array
    {
        $recipes = Recipe::visibleTo($user)->where('status', Recipe::STATUS_PUBLISHED)
            ->where('yield_unit', 'personnes')->where('category', 'plat')
            ->with(['ingredients.ingredient.packs.prices', 'tags', 'equipment'])->get()
            ->filter(fn (Recipe $r) => $r->missingEquipment($household)->isEmpty());

        $stock = [];
        foreach (AntiWaste::suggestions($household, $user, 500) as $suggestion) {
            $stock[$suggestion['recipe']->id] = $suggestion;
        }

        $favorites = $user->favoriteRecipes()->pluck('recipes.id')->flip()->all();

        $history = $household->mealPlanEntries()->where('kind', MealPlanEntry::KIND_RECIPE)->whereNotNull('recipe_id')
            ->whereNull('proposed_at')->where('is_frozen', false)
            ->whereDate('date', '<', $weekStart->toDateString())
            ->whereDate('date', '>=', $weekStart->copy()->subDays(self::HISTORY_DAYS)->toDateString())
            ->get(['recipe_id', 'date'])
            ->groupBy('recipe_id')->map(fn ($rows) => $rows->map(fn ($r) => $r->date->toDateString())->max());

        $pool = [];
        foreach ($recipes as $recipe) {
            $cost = RecipeCost::compute($recipe, 1);
            $minutes = ($recipe->prep_minutes === null && $recipe->cook_minutes === null) ? null : $recipe->totalMinutes();

            $pool[$recipe->id] = [
                'recipe' => $recipe,
                'fixed' => self::profile($recipe) + [
                    'id' => $recipe->id,
                    'title' => $recipe->title,
                    'minutes' => $minutes,
                    'difficulty' => $recipe->difficulty,
                    'cost1' => $cost['total_cents'] > 0 || $cost['complete'] ? (float) $cost['total_cents'] : null,
                    'yield' => (float) $recipe->yield_quantity,
                    'cost_complete' => $cost['complete'],
                    'expiring' => count($stock[$recipe->id]['expiring'] ?? []),
                    'expiring_names' => $stock[$recipe->id]['expiring'] ?? [],
                    'cover' => (float) ($stock[$recipe->id]['ratio'] ?? 0.0),
                    'favorite' => isset($favorites[$recipe->id]),
                    'last_cooked' => $history[$recipe->id] ?? null,
                ],
            ];
        }

        return $pool;
    }

    /** Protéine principale et caractère végétarien d'une recette. @return array{protein: ?string, veggy: bool} */
    private static function profile(Recipe $recipe): array
    {
        $slugs = $recipe->tags->pluck('slug')->all();

        return [
            'protein' => $recipe->protein ?: null,
            'veggy' => in_array('veggy', $slugs, true) || in_array('vegan', $slugs, true),
        ];
    }

    /** Candidats pour un jour donné : recettes pas encore utilisées cette semaine, avec saison et ancienneté à cette date. */
    private static function candidates(array $pool, array $used, Carbon $day, Carbon $weekStart): array
    {
        $used = array_flip($used);
        $candidates = [];

        foreach ($pool as $id => $row) {
            if (isset($used[$id])) {
                continue;
            }

            $last = $row['fixed']['last_cooked'];
            $candidates[] = $row['fixed'] + [
                'season' => $row['recipe']->isInSeason((int) $day->month),
                'last_cooked_days' => $last === null ? null : (int) max(0, intdiv(strtotime($day->toDateString()) - strtotime($last), 86400)),
            ];
        }

        return $candidates;
    }

    /** Description du repas pour MenuScorer : parts des convives et nombre de repas du plat. */
    private static function slotInfo(Household $household, Carbon $day, string $slot, ?array $eaters, int $meals): array
    {
        $members = $eaters === null ? $household->members : $household->members->whereIn('id', $eaters);

        return [
            'date' => $day->toDateString(),
            'weekday' => $day->isoWeekday(),
            'kind' => $slot,
            'parts' => (float) $members->sum(fn ($m) => $m->coefficientOn($day)),
            'meals' => $meals,
        ];
    }

    /**
     * État de la semaine pour MenuScorer.
     *
     * @param  array<int, array{protein: ?string, veggy: bool, key: string, id: int}>  $timeline  plats cuisinés de la semaine
     */
    private static function state(Household $household, Carbon $day, string $slot, int $budget, float $remaining, int $slotsLeft, int $veggyMin, array $timeline, ?string $key = null): array
    {
        $key ??= $day->toDateString().'-'.array_search($slot, MealPlanEntry::slotCodes(), true);

        $counts = [];
        $veggy = 0;
        $before = $after = null;

        usort($timeline, fn ($a, $b) => strcmp($a['key'], $b['key']));
        foreach ($timeline as $dish) {
            if ($dish['protein'] !== null && $dish['protein'] !== 'aucune') {
                $counts[$dish['protein']] = ($counts[$dish['protein']] ?? 0) + 1;
            }
            $veggy += $dish['veggy'] ? 1 : 0;

            if (strcmp($dish['key'], $key) < 0) {
                $before = $dish['protein'];
            } elseif ($after === null && strcmp($dish['key'], $key) > 0) {
                $after = $dish['protein'];
            }
        }

        return [
            'budget_cents' => $budget,
            'remaining_cents' => max(0.0, $remaining),
            'slots_left' => max(1, $slotsLeft),
            'veggy_min' => $veggyMin,
            'veggy_count' => $veggy,
            'protein_counts' => $counts,
            'neighbours' => array_values(array_filter([$before, $after], fn ($p) => $p !== null && $p !== 'aucune')),
        ];
    }

    /** « 4,20 € pour ce repas · de saison · prêt en 25 min » (3 raisons au plus). */
    private static function reason(array $reasons): ?string
    {
        $reasons = array_slice($reasons, 0, self::MAX_REASONS);

        return $reasons === [] ? null : mb_substr(implode(' · ', $reasons), 0, 200);
    }
}
