<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\PantryItem;
use App\Support\AntiWaste;
use App\Support\Pantry;
use App\Support\UnitConversionException;
use App\Support\Units;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Stock du foyer (placard, frigo, congélateur) : ce qu'il y a déjà à la maison, avec dates limites.
 */
class StockController extends Controller
{
    /** Unités proposées à la saisie. */
    public const UNITS = ['g', 'kg', 'ml', 'cl', 'l', 'piece'];

    public function index(Request $request)
    {
        $household = $request->user()->household;
        $lots = $household->pantryItems()->with('ingredient.aisle')->get()
            ->sortBy(fn (PantryItem $lot) => ($lot->expires_on?->toDateString() ?? '9999-12-31').'|'.Str::lower($lot->ingredient->name))->values();

        return view('stock.index', [
            'groups' => $lots->groupBy('location'),
            'soon' => $lots->filter(fn (PantryItem $lot) => $lot->isExpired() || $lot->isSoon())->values(),
            'count' => $lots->count(),
            'ingredients' => Ingredient::orderBy('name')->pluck('name'),
            'units' => self::unitChoices(),
        ]);
    }

    public function store(Request $request)
    {
        $household = $request->user()->household;
        $data = $this->validated($request, withIngredient: true);

        $lot = Pantry::add($household, $data['ingredient'], $data['quantity'], $data['expires_on'], $data['location'], 'manuel', null, $request->user()->id, $data['note']);

        return redirect()->route('stock.index')->with('status', '« '.$data['ingredient']->name.' » ajouté au stock ('.$lot->quantityLabel().' en tout).');
    }

    public function update(Request $request, PantryItem $lot)
    {
        $this->authorizeLot($request, $lot);
        $lot->loadMissing('ingredient');
        $data = $this->validated($request, ingredient: $lot->ingredient);

        $lot->update(['quantity' => $data['quantity'], 'expires_on' => $data['expires_on'], 'location' => $data['location'] ?? $lot->location, 'note' => $data['note']]);

        return redirect()->route('stock.index')->with('status', '« '.$lot->ingredient->name.' » mis à jour.');
    }

    public function destroy(Request $request, PantryItem $lot)
    {
        $this->authorizeLot($request, $lot);
        $name = $lot->ingredient->name;
        $lot->delete();

        return redirect()->route('stock.index')->with('status', "« {$name} » retiré du stock.");
    }

    /** « Que cuisiner ? » : recettes classées selon le stock et les produits à finir. */
    public function recipes(Request $request)
    {
        $household = $request->user()->household;

        return view('stock.recipes', [
            'suggestions' => AntiWaste::suggestions($household, $request->user()),
            'soon' => AntiWaste::expiring($household),
            'hasStock' => $household->pantryItems()->exists(),
        ]);
    }

    /** @return array<string, string> code => libellé */
    public static function unitChoices(): array
    {
        return collect(self::UNITS)->mapWithKeys(fn ($code) => [$code => Units::label($code)])->all();
    }

    private function validated(Request $request, bool $withIngredient = false, ?Ingredient $ingredient = null): array
    {
        // « 0,5 » comme « 0.5 »
        if (is_string($request->input('quantite'))) {
            $request->merge(['quantite' => str_replace(',', '.', trim($request->input('quantite')))]);
        }

        $rules = [
            'quantite' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'unite' => ['required', Rule::in(self::UNITS)],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.now('Europe/Paris')->subYear()->toDateString(), 'before_or_equal:'.now('Europe/Paris')->addYears(3)->toDateString()],
            'location' => ['nullable', Rule::in(array_keys(PantryItem::LOCATIONS))],
            'note' => ['nullable', 'string', 'max:80'],
        ];
        if ($withIngredient) {
            $rules['ingredient'] = ['required', 'string', 'max:120'];
        }

        $data = $request->validate($rules, [], ['quantite' => 'quantité', 'unite' => 'unité', 'expires_on' => 'date limite', 'ingredient' => 'ingrédient']);

        if ($withIngredient) {
            $name = trim($data['ingredient']);
            $ingredient = Ingredient::with('aisle')->whereRaw('lower(name) = ?', [mb_strtolower($name)])->orWhere('slug', Str::slug($name))->first();
            if ($ingredient === null) {
                throw ValidationException::withMessages(['ingredient' => "« {$name} » n'existe pas dans les ingrédients : crée-le d'abord dans la page Ingrédients."]);
            }
        }

        try {
            $quantity = Units::toBase((float) $data['quantite'], $data['unite'], $ingredient);
        } catch (UnitConversionException $e) {
            throw ValidationException::withMessages(['quantite' => $e->getMessage().' Utilise l\'unité de l\'ingrédient ('.Units::label(Units::BASE[Units::dimension($ingredient->base_unit)]).').']);
        }

        return [
            'ingredient' => $ingredient,
            'quantity' => round($quantity, 3),
            'expires_on' => $data['expires_on'] ?? null,
            'location' => $data['location'] ?? null,
            'note' => isset($data['note']) ? (trim($data['note']) ?: null) : null,
        ];
    }

    private function authorizeLot(Request $request, PantryItem $lot): void
    {
        abort_unless($lot->household_id === $request->user()->household_id, 404);
    }
}
