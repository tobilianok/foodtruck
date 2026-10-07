<?php

namespace App\Http\Controllers;

use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Support\AntiWaste;
use App\Support\ListReconciliation;
use App\Support\MealPlanner;
use App\Support\WeekFlow;
use Illuminate\Http\Request;

/** Accueil : le parcours de la semaine en 4 étapes, avec toujours un bouton pour la suivante. */
class HomeController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $household = $user->household->loadMissing('members');
        // v0.20.0 : une liste vide ne compte jamais (elle faisait croire que la liste et les courses étaient en cours)
        \App\Models\ShoppingList::purgeEmpty($household);
        $start = MealPlanner::weekStart();
        $today = now('Europe/Paris')->toDateString();

        $week = $household->mealPlanEntries()
            ->with([...PlanningController::COST_RELATIONS, 'source.recipe'])
            ->where('is_frozen', false)->confirmed()
            ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $start->copy()->addDays(6)->toDateString())
            ->get();
        foreach ($week as $entry) {
            $entry->setRelation('household', $household);
            $entry->source?->setRelation('household', $household);
        }

        $billed = $household->shoppingLists()->whereHas('receipts')->first();
        $cost = MealPlanner::cost($week, $household);
        $todayMeals = $week->filter(fn (MealPlanEntry $e) => $e->date->toDateString() === $today)
            ->sortBy(fn ($e) => array_search($e->slot, MealPlanEntry::slotCodes(), true));

        return view('home', [
            'household' => $household,
            'flow' => WeekFlow::for($household),
            'todayMeals' => $todayMeals,
            'weekCount' => $week->where('kind', 'recette')->count(),
            'costCents' => (int) round($cost['total_cents']),
            'budgetCents' => (int) $household->weekly_budget_cents,
            'soon' => AntiWaste::expiring($household)->take(4),
            'recipeCount' => Recipe::visibleTo($user)->count(),
            'billed' => $billed,
            'billedCmp' => $billed ? ListReconciliation::compare($billed->setRelation('household', $household)) : null,
        ]);
    }
}
