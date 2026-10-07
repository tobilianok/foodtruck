<?php

namespace App\Http\Controllers;

use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\SavedMenu;
use App\Support\MealPlanner;
use App\Support\RecipeCost;
use App\Support\RecipeServing;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * v0.22.0 : menus enregistrés du foyer. Un menu naît d'un repas composé du planning (« Enregistrer comme menu ») ;
 * il se replanifie en un clic (le + d'une case, ou « Planifier » ici), se renomme et se supprime (les repas déjà
 * planifiés restent).
 */
class SavedMenuController extends Controller
{
    public function index(Request $request)
    {
        $household = $request->user()->household->loadMissing('members');
        $menus = $household->savedMenus()->with('recipes.ingredients.ingredient.packs.prices')->get();
        // Nombre de repas planifiés avec chaque menu (un repas = un jour + un créneau)
        $uses = MealPlanEntry::whereIn('saved_menu_id', $menus->modelKeys())->where('kind', MealPlanEntry::KIND_RECIPE)->get(['saved_menu_id', 'date', 'slot'])
            ->groupBy('saved_menu_id')->map(fn ($rows) => $rows->unique(fn ($e) => $e->date->toDateString().'|'.$e->slot)->count());
        $today = now('Europe/Paris');

        // Coût d'un repas pour tout le foyer (semaine type ignorée), au prorata des quantités
        $costs = [];
        foreach ($menus as $menu) {
            $costs[$menu->id] = $menu->recipes->sum(fn (Recipe $recipe) => RecipeCost::compute($recipe, RecipeServing::for($recipe, $household, [], $today)->factor)['total_cents']);
        }

        return view('planning.menus', ['menus' => $menus, 'costs' => $costs, 'uses' => $uses, 'household' => $household]);
    }

    /** Formulaire « Enregistrer comme menu » d'un repas composé (jour + créneau). */
    public function create(Request $request)
    {
        [$date, $slot, $dishes] = $this->meal($request, $request->query('date'), $request->query('creneau'));
        if ($dishes->count() < 2) {
            return redirect()->route('planning.week', MealPlanner::weekStart($date)->toDateString())
                ->with('status', 'Un menu réunit au moins deux recettes : ajoute d\'abord un accompagnement, une entrée ou un dessert à ce repas (le + de la case).');
        }

        return view('planning.menu-form', [
            'date' => $date,
            'slot' => $slot,
            'dishes' => $dishes,
            'name' => Str::limit($dishes->map(fn (MealPlanEntry $e) => $e->recipe->title)->join(' + '), 80, ''),
        ]);
    }

    public function store(Request $request)
    {
        $household = $request->user()->household;
        [$date, $slot, $dishes] = $this->meal($request, $request->input('date'), $request->input('creneau'));
        $ids = $dishes->pluck('recipe_id')->map(fn ($id) => (int) $id)->all();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('saved_menus', 'name')->where('household_id', $household->id)],
            'recettes' => ['required', 'array', 'min:2', 'max:'.SavedMenu::MAX_RECIPES],
            'recettes.*' => ['integer', Rule::in($ids)],
        ], [
            'name.unique' => 'Un menu porte déjà ce nom.',
            'recettes.min' => 'Garde au moins deux recettes dans le menu.',
        ], ['name' => 'nom du menu', 'recettes' => 'recettes']);

        $chosen = array_values(array_intersect($ids, array_map('intval', $data['recettes'])));

        $menu = DB::transaction(function () use ($household, $data, $chosen, $dishes, $request) {
            $menu = SavedMenu::create(['household_id' => $household->id, 'name' => trim($data['name']), 'created_by' => $request->user()->id]);
            foreach ($chosen as $i => $recipeId) {
                $menu->recipes()->attach($recipeId, ['position' => ($i + 1) * 10]);
            }
            // Les plats de ce repas rappellent désormais le menu
            MealPlanEntry::whereKey($dishes->whereIn('recipe_id', $chosen)->modelKeys())->update(['saved_menu_id' => $menu->id]);

            return $menu;
        });

        return redirect()->route('planning.week', MealPlanner::weekStart($date)->toDateString())
            ->with('status', 'Menu « '.$menu->name.' » enregistré. Pour le replanifier : le + d\'un repas, ou Planning → Mes menus.');
    }

    public function update(Request $request, SavedMenu $menu)
    {
        $this->authorizeMenu($request, $menu);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('saved_menus', 'name')->where('household_id', $menu->household_id)->ignore($menu->id)],
        ], ['name.unique' => 'Un menu porte déjà ce nom.'], ['name' => 'nom du menu']);

        $menu->update(['name' => trim($data['name'])]);

        return redirect()->route('planning.menus')->with('status', 'Menu renommé : « '.$menu->name.' ».');
    }

    public function destroy(Request $request, SavedMenu $menu)
    {
        $this->authorizeMenu($request, $menu);
        $menu->delete();

        return redirect()->route('planning.menus')->with('status', 'Menu « '.$menu->name.' » supprimé. Les repas déjà planifiés restent au planning.');
    }

    /**
     * Plats cuisinés d'un repas (ni restes ni congélateur), une fois chaque recette, dans l'ordre de la case.
     *
     * @return array{0: string, 1: string, 2: Collection<int, MealPlanEntry>}
     */
    private function meal(Request $request, mixed $date, mixed $slot): array
    {
        try {
            $date = Carbon::createFromFormat('Y-m-d', (string) $date, 'Europe/Paris')->toDateString();
        } catch (\Throwable) {
            abort(404);
        }
        abort_unless(in_array($slot, MealPlanEntry::EATING_SLOTS, true), 404);

        $dishes = $request->user()->household->mealPlanEntries()->with('recipe')
            ->whereDate('date', $date)->where('slot', $slot)->where('kind', MealPlanEntry::KIND_RECIPE)->where('is_frozen', false)
            ->whereNotNull('recipe_id')->orderBy('position')->orderBy('id')->get()
            ->filter(fn (MealPlanEntry $e) => $e->recipe?->yield_unit === 'personnes' && $e->isMealDish())->unique('recipe_id')->values();

        return [$date, $slot, $dishes];
    }

    private function authorizeMenu(Request $request, SavedMenu $menu): void
    {
        abort_unless($menu->household_id === $request->user()->household_id, 404);
    }
}
