<?php

namespace App\Http\Controllers;

use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\IngredientPack;
use App\Models\Price;
use App\Models\Store;
use App\Support\Units;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Référentiel des ingrédients : liste, fiche, conditionnements et prix.
 * Tous les membres d'un foyer peuvent ajouter et corriger (décision v0.3.0).
 */
class IngredientController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'rayon' => ['nullable', 'string', 'max:60'],
            'saison' => ['nullable', 'boolean'],
        ]);

        $month = (int) now('Europe/Paris')->month;
        $search = Str::lower(Str::ascii(trim($filters['q'] ?? '')));

        $ingredients = Ingredient::with(['aisle', 'packs.prices'])
            ->get()
            ->sortBy(fn (Ingredient $i) => Str::lower(Str::ascii($i->name)))
            ->filter(fn (Ingredient $i) => $search === '' || str_contains(Str::lower(Str::ascii($i->name)), $search))
            ->filter(fn (Ingredient $i) => empty($filters['rayon']) || $i->aisle->slug === $filters['rayon'])
            ->filter(fn (Ingredient $i) => empty($filters['saison']) || $i->isInSeason($month) === true);

        return view('ingredients.index', [
            'groups' => $ingredients->groupBy('aisle_id'),
            'aisles' => Aisle::ordered()->keyBy('id'),
            'stores' => Store::active()->keyBy('id'),
            'filters' => $filters,
            'month' => $month,
            'total' => Ingredient::count(),
        ]);
    }

    public function create(Request $request)
    {
        return view('ingredients.create', [
            'aisles' => Aisle::ordered(),
            'stores' => Store::active(),
            'ingredient' => new Ingredient(['base_unit' => 'g', 'name' => mb_substr(trim((string) $request->query('nom')), 0, 80) ?: null]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateIngredient($request);
        $pack = $request->validate([
            'pack_label' => ['nullable', 'string', 'max:60', 'required_with:pack_quantity'],
            'pack_quantity' => ['nullable', 'numeric', 'gt:0', 'max:1000000', 'required_with:pack_label'],
            'pack_bulk' => ['nullable', 'boolean'],
            'price' => ['nullable', 'string', 'max:12'],
            'price_store_id' => ['nullable', 'integer', 'exists:stores,id', 'required_with:price'],
        ], [], ['pack_label' => 'libellé du conditionnement', 'pack_quantity' => 'quantité', 'price' => 'prix', 'price_store_id' => 'magasin']);

        $slug = Str::slug($data['name']);
        if ($slug === '' || Ingredient::where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['name' => 'Cet ingrédient existe déjà (ou son nom est invalide).']);
        }

        $cents = Price::parseEuros($pack['price'] ?? null);
        if (! empty($pack['price']) && ($cents === null || empty($pack['pack_label']))) {
            throw ValidationException::withMessages(['price' => 'Indique un prix valide (ex. 1,05) et un conditionnement.']);
        }

        $ingredient = DB::transaction(function () use ($data, $pack, $slug, $cents, $request) {
            $ingredient = Ingredient::create($data + ['slug' => $slug, 'created_by' => $request->user()->id]);

            if (! empty($pack['pack_label'])) {
                $created = $ingredient->packs()->create([
                    'label' => trim($pack['pack_label']),
                    'quantity' => $pack['pack_quantity'],
                    'is_bulk' => (bool) ($pack['pack_bulk'] ?? false),
                    'position' => 10,
                ]);

                if ($cents !== null) {
                    $this->recordPrice($created, (int) $pack['price_store_id'], $cents, false, now('Europe/Paris')->toDateString(), $request->user()->id);
                }
            }

            return $ingredient;
        });

        return redirect()->route('ingredients.show', $ingredient)->with('status', "« {$ingredient->name} » ajouté au référentiel.");
    }

    public function show(Ingredient $ingredient)
    {
        $ingredient->load(['aisle', 'creator', 'packs.prices.store', 'packs.prices.creator']);

        $history = Price::with(['store', 'pack', 'creator'])
            ->whereIn('ingredient_pack_id', $ingredient->packs->pluck('id'))
            ->orderByDesc('observed_on')->orderByDesc('id')
            ->limit(15)->get();

        return view('ingredients.show', [
            'ingredient' => $ingredient,
            'aisles' => Aisle::ordered(),
            'stores' => Store::active(),
            'best' => $ingredient->bestOffer(),
            'history' => $history,
        ]);
    }

    public function update(Request $request, Ingredient $ingredient)
    {
        $data = $this->validateIngredient($request);

        if ($data['base_unit'] !== $ingredient->base_unit && $ingredient->packs()->exists()) {
            throw ValidationException::withMessages(['base_unit' => 'L\'unité de base ne peut plus changer : des conditionnements l\'utilisent déjà.']);
        }

        $ingredient->update($data);

        return back()->with('status', 'Ingrédient enregistré.');
    }

    public function storePack(Request $request, Ingredient $ingredient)
    {
        $data = $this->validatePack($request);

        $ingredient->packs()->create($data + ['position' => ((int) $ingredient->packs()->max('position')) + 10]);

        return back()->with('status', 'Conditionnement ajouté.');
    }

    public function updatePack(Request $request, Ingredient $ingredient, IngredientPack $pack)
    {
        abort_unless($pack->ingredient_id === $ingredient->id, 404);

        $pack->update($this->validatePack($request));

        return back()->with('status', 'Conditionnement enregistré.');
    }

    public function destroyPack(Ingredient $ingredient, IngredientPack $pack)
    {
        abort_unless($pack->ingredient_id === $ingredient->id, 404);

        $pack->delete();

        return back()->with('status', "Conditionnement « {$pack->label} » supprimé avec ses prix.");
    }

    public function storePrice(Request $request, Ingredient $ingredient)
    {
        $data = $request->validate([
            'ingredient_pack_id' => ['required', 'integer', Rule::exists('ingredient_packs', 'id')->where('ingredient_id', $ingredient->id)],
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'price' => ['required', 'string', 'max:12'],
            'is_promo' => ['nullable', 'boolean'],
            'observed_on' => ['required', 'date', 'before_or_equal:'.now('Europe/Paris')->toDateString(), 'after:2020-01-01'],
        ], [], ['ingredient_pack_id' => 'conditionnement', 'store_id' => 'magasin', 'price' => 'prix', 'observed_on' => 'date']);

        $cents = Price::parseEuros($data['price']);
        if ($cents === null || $cents > 100000) {
            throw ValidationException::withMessages(['price' => 'Indique un prix valide, par exemple 1,05.']);
        }

        $pack = $ingredient->packs()->findOrFail($data['ingredient_pack_id']);
        $this->recordPrice($pack, (int) $data['store_id'], $cents, (bool) ($data['is_promo'] ?? false), $data['observed_on'], $request->user()->id);

        return back()->with('status', 'Prix enregistré.');
    }

    private function recordPrice(IngredientPack $pack, int $storeId, int $cents, bool $promo, string $date, int $userId): Price
    {
        return Price::create([
            'ingredient_pack_id' => $pack->id,
            'store_id' => $storeId,
            'price_cents' => $cents,
            'source' => 'manuel',
            'is_promo' => $promo,
            'observed_on' => $date,
            'created_by' => $userId,
        ]);
    }

    private function validateIngredient(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'aisle_id' => ['required', 'integer', 'exists:aisles,id'],
            'base_unit' => ['required', Rule::in(array_keys(Units::BASE_CHOICES))],
            'piece_weight_g' => ['nullable', 'numeric', 'gt:0', 'max:20000'],
            'density' => ['nullable', 'numeric', 'min:0.05', 'max:5'],
            'season_months' => ['nullable', 'array'],
            'season_months.*' => ['integer', 'between:1,12'],
            'is_fresh' => ['nullable', 'boolean'],
            'is_staple' => ['nullable', 'boolean'],
        ], [], [
            'name' => 'nom', 'aisle_id' => 'rayon', 'base_unit' => 'unité de base',
            'piece_weight_g' => 'poids d\'une pièce', 'density' => 'densité', 'season_months' => 'mois de saison',
        ]);

        $months = array_values(array_unique(array_map('intval', $data['season_months'] ?? [])));
        sort($months);

        return [
            'name' => trim($data['name']),
            'aisle_id' => (int) $data['aisle_id'],
            'base_unit' => $data['base_unit'],
            'piece_weight_g' => $data['piece_weight_g'] ?? null,
            'density' => $data['density'] ?? null,
            // Les 12 mois cochés (ou aucun) = toute l'année.
            'season_months' => $months === [] || count($months) === 12 ? null : $months,
            'is_fresh' => (bool) ($data['is_fresh'] ?? false),
            'is_staple' => (bool) ($data['is_staple'] ?? false),
        ];
    }

    private function validatePack(Request $request): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'is_bulk' => ['nullable', 'boolean'],
        ], [], ['label' => 'libellé', 'quantity' => 'quantité']);

        return [
            'label' => trim($data['label']),
            'quantity' => $data['quantity'],
            'is_bulk' => (bool) ($data['is_bulk'] ?? false),
        ];
    }
}
