<?php

namespace App\Http\Controllers;

use App\Models\MealPlanEntry;
use App\Models\Price;
use App\Support\MealPlanner;
use App\Support\MenuGenerator;
use Illuminate\Http\Request;

/**
 * Menu automatique : propose la semaine, que la famille garde, change ou valide.
 * Les repas proposés sont des entrées du planning marquées « proposed_at » (hors liste de courses tant qu'ils ne sont pas gardés).
 */
class MenuController extends Controller
{
    public function generate(Request $request)
    {
        $data = $request->validate([
            'semaine' => ['nullable', 'date_format:Y-m-d'],
            'vegetarien' => ['nullable', 'integer', 'min:0', 'max:7'],
        ]);

        $user = $request->user();
        $household = $user->household->loadMissing('members', 'equipment');
        $start = MealPlanner::weekStart($data['semaine'] ?? null);

        $veggy = isset($data['vegetarien']) ? (int) $data['vegetarien'] : (int) $household->menu_veggy_min;
        if ($veggy !== (int) $household->menu_veggy_min) {
            $household->update(['menu_veggy_min' => $veggy]);
        }

        $result = MenuGenerator::generate($household, $user, $start, $veggy);

        if ($result['no_recipes']) {
            return $this->back($start)->with('status', 'Aucune recette de plat publiée ne convient à ton foyer (rendement « personnes », appareils disponibles) : ajoute des recettes pour que Foodtruck puisse composer un menu.');
        }

        if ($result['created'] === 0) {
            return $this->back($start)->with('status', $result['unfilled'] === 0
                ? 'Tous les repas de la semaine sont déjà prévus : rien à proposer.'
                : 'Aucun plat n\'a pu être proposé (toutes les recettes sont déjà utilisées cette semaine ?). Ajoute des recettes ou libère des repas.');
        }

        $message = $result['created'].' plat'.($result['created'] > 1 ? 's' : '').' proposé'.($result['created'] > 1 ? 's' : '')
            .' pour '.Price::formatCents($result['cost_cents']).' de courses estimées';
        $message .= $household->weekly_budget_cents > 0 ? ' (budget : '.Price::formatCents($household->weekly_budget_cents).').' : '.';
        if ($result['over_budget']) {
            $message .= ' Le budget est dépassé : change un plat par une idée moins chère.';
        }
        if ($result['unfilled'] > 0) {
            $message .= ' '.$result['unfilled'].' repas restent sans plat faute de recette adaptée.';
        }
        $message .= ' Garde ceux qui te plaisent, change les autres, puis valide la semaine.';

        return $this->back($start)->with('status', $message);
    }

    public function accept(Request $request)
    {
        $start = $this->week($request);
        $count = MenuGenerator::accept($request->user()->household, $start);

        return $this->back($start)->with('status', $count > 0
            ? 'Menu validé : '.$count.' plat'.($count > 1 ? 's' : '').' au planning. Ils comptent maintenant dans la liste de courses.'
            : 'Aucune proposition à valider cette semaine.');
    }

    public function clear(Request $request)
    {
        $start = $this->week($request);
        $count = MenuGenerator::clear($request->user()->household, $start);

        return $this->back($start)->with('status', $count > 0
            ? 'Propositions effacées ('.$count.' plat'.($count > 1 ? 's' : '').'). Les repas déjà au planning sont conservés.'
            : 'Aucune proposition à effacer cette semaine.');
    }

    public function keep(Request $request, MealPlanEntry $entry)
    {
        $entry = $this->proposal($request, $entry);
        MenuGenerator::keep($entry);

        return $this->back(MealPlanner::weekStart($entry->date->toDateString()))
            ->with('status', '« '.$entry->recipe->title.' » gardé : il compte maintenant dans la liste de courses.');
    }

    public function another(Request $request, MealPlanEntry $entry)
    {
        $entry = $this->proposal($request, $entry);
        $old = $entry->recipe->title;
        $recipe = MenuGenerator::reroll($entry, $request->user());

        return $this->back(MealPlanner::weekStart($entry->date->toDateString()))
            ->with('status', $recipe === null
                ? 'Pas d\'autre idée pour ce repas : toutes les autres recettes sont déjà utilisées cette semaine ou ne conviennent pas.'
                : '« '.$old.' » remplacé par « '.$recipe->title.' ».');
    }

    private function week(Request $request): \Illuminate\Support\Carbon
    {
        $data = $request->validate(['semaine' => ['nullable', 'date_format:Y-m-d']]);

        return MealPlanner::weekStart($data['semaine'] ?? null);
    }

    /** Plat proposé du foyer (les restes renvoient au plat dont ils viennent). */
    private function proposal(Request $request, MealPlanEntry $entry): MealPlanEntry
    {
        abort_unless($entry->household_id === $request->user()->household_id, 404);

        if ($entry->isLeftover()) {
            $entry = $entry->source ?? abort(404);
        }

        abort_unless($entry->isProposal() && $entry->isRecipe(), 404);
        $entry->loadMissing('recipe', 'household');

        return $entry;
    }

    private function back(\Illuminate\Support\Carbon $start)
    {
        return redirect()->route('planning.week', $start->toDateString());
    }
}
