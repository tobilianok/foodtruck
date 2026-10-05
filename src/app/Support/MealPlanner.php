<?php

namespace App\Support;

use App\Models\Household;
use App\Models\MealPlanEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Règles du planning : semaines du lundi au dimanche, restes placés automatiquement,
 * coût estimé de la semaine.
 */
class MealPlanner
{
    /** Au-delà de ce nombre de créneaux examinés, les restes non placés sont signalés. */
    private const LEFTOVER_SEARCH = 28;

    /** Lundi de la semaine contenant la date donnée (aujourd'hui par défaut). */
    public static function weekStart(?string $date = null): Carbon
    {
        try {
            $day = $date ? Carbon::createFromFormat('Y-m-d', $date, 'Europe/Paris') : now('Europe/Paris');
        } catch (\Throwable) {
            $day = now('Europe/Paris');
        }

        return $day->startOfDay()->startOfWeek(Carbon::MONDAY);
    }

    /** @return array<int, Carbon> les 7 jours de la semaine */
    public static function days(Carbon $start): array
    {
        return array_map(fn ($i) => $start->copy()->addDays($i), range(0, 6));
    }

    /**
     * (Re)place les restes d'un plat cuisiné pour plusieurs repas sur les prochains déjeuners / dîners libres
     * où quelqu'un mange à la maison (semaine type).
     * Les restes mis au congélateur sont conservés et comptent. Sans créneau libre, les restes sont mis de côté
     * (« Au congélateur ») pour être planifiés plus tard. Renvoie le nombre de restes mis de côté ainsi.
     */
    public static function placeLeftovers(MealPlanEntry $entry): int
    {
        $entry->loadMissing('recipe', 'household');
        $existing = $entry->leftovers()->get();

        $wanted = $entry->isRecipe() && $entry->batch_quantity === null && $entry->recipe->yield_unit === 'personnes'
            ? max(0, $entry->meals - 1)
            : 0;

        $frozen = $existing->where('is_frozen', true);
        foreach ($existing->where('is_frozen', false) as $leftover) {
            $leftover->delete();
        }
        foreach ($frozen->slice($wanted) as $extra) {
            $extra->delete();
        }

        $toPlace = max(0, $wanted - min($wanted, $frozen->count()));
        if ($toPlace === 0) {
            return 0;
        }

        $slots = array_values(array_intersect(MealPlanEntry::LEFTOVER_SLOTS, $entry->household->mealSlots()));

        $order = MealPlanEntry::slotCodes();
        $rank = array_search($entry->slot, $order, true);
        $date = $entry->date->copy();
        $examined = 0;

        while ($toPlace > 0 && $examined < self::LEFTOVER_SEARCH) {
            foreach ($slots as $slot) {
                // Seulement après le plat : même jour plus tard, puis les jours suivants
                if ($date->isSameDay($entry->date) && array_search($slot, $order, true) <= $rank) {
                    continue;
                }
                $examined++;

                // Personne à la maison d'après la semaine type (ex. midi en semaine) : créneau sauté
                if ($entry->household->usualEaters($date, $slot) === []) {
                    continue;
                }

                $busy = MealPlanEntry::where('household_id', $entry->household_id)
                    ->whereDate('date', $date->toDateString())->where('slot', $slot)->where('is_frozen', false)->exists();
                if ($busy) {
                    continue;
                }

                MealPlanEntry::create([
                    'household_id' => $entry->household_id,
                    'date' => $date->toDateString(),
                    'slot' => $slot,
                    'kind' => MealPlanEntry::KIND_LEFTOVER,
                    'recipe_id' => $entry->recipe_id,
                    'source_entry_id' => $entry->id,
                    'created_by' => $entry->created_by,
                    'proposed_at' => $entry->proposed_at,
                    'proposal_reason' => $entry->proposal_reason,
                ]);

                if (--$toPlace === 0) {
                    break;
                }
            }
            $date->addDay();
            if ($slots === []) {
                break;
            }
        }

        for ($i = 0; $i < $toPlace; $i++) {
            MealPlanEntry::create([
                'household_id' => $entry->household_id,
                'date' => $entry->date->toDateString(),
                'slot' => $entry->slot,
                'kind' => MealPlanEntry::KIND_LEFTOVER,
                'recipe_id' => $entry->recipe_id,
                'source_entry_id' => $entry->id,
                'is_frozen' => true,
                'created_by' => $entry->created_by,
                'proposed_at' => $entry->proposed_at,
                'proposal_reason' => $entry->proposal_reason,
            ]);
        }

        return $toPlace;
    }

    /**
     * Coût estimé des plats cuisinés (les restes ne comptent pas une seconde fois).
     * Relations attendues : recipe.ingredients.ingredient.packs.prices, household.members
     *
     * @param  Collection<int, MealPlanEntry>  $entries
     * @return array{total_cents: float, per_entry: array<int, float>, incomplete: array<int, string>}
     */
    public static function cost(Collection $entries, Household $household): array
    {
        $total = 0.0;
        $perEntry = [];
        $incomplete = [];

        foreach ($entries as $entry) {
            if (! $entry->isRecipe()) {
                continue;
            }

            $serving = RecipeServing::for($entry->recipe, $household, $entry->servingInput(), $entry->date);
            $cost = RecipeCost::compute($entry->recipe, $serving->factor);
            $perEntry[$entry->id] = $cost['total_cents'];
            $total += $cost['total_cents'];

            if (! $cost['complete']) {
                $incomplete[] = $entry->recipe->title;
            }
        }

        return ['total_cents' => $total, 'per_entry' => $perEntry, 'incomplete' => array_values(array_unique($incomplete))];
    }
}
