<?php

namespace App\Http\Controllers;

use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\IngredientPack;
use App\Models\Price;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mise à jour rapide des prix d'un magasin : tous les conditionnements sur une page,
 * seuls les montants modifiés sont enregistrés (avec historique).
 */
class PriceController extends Controller
{
    public function index(Request $request)
    {
        $household = $request->user()->household;
        $store = $household->mainStore ?? Store::active()->first();

        return redirect()->route('prices.edit', $store);
    }

    public function edit(Request $request, Store $store)
    {
        $rayon = $request->query('rayon');
        $all = $request->boolean('tous');

        $ingredients = Ingredient::with(['aisle', 'packs.prices' => fn ($q) => $q->where('store_id', $store->id)])
            ->when($rayon, fn ($q) => $q->whereHas('aisle', fn ($a) => $a->where('slug', $rayon)))
            ->get()
            ->sortBy(fn (Ingredient $i) => Str::lower(Str::ascii($i->name)))
            ->filter(fn (Ingredient $i) => $i->packs->isNotEmpty());

        // Par défaut : seulement ce qui a déjà un prix dans ce magasin (liste courte après les courses).
        $sold = $ingredients
            ->map(fn (Ingredient $i) => $i->setRelation('packs', $i->packs->filter(fn ($p) => $p->prices->isNotEmpty())->values()))
            ->filter(fn (Ingredient $i) => $i->packs->isNotEmpty());

        if (! $all && $sold->isNotEmpty()) {
            $ingredients = $sold;
        } else {
            $all = true;
            $ingredients = Ingredient::with(['aisle', 'packs.prices' => fn ($q) => $q->where('store_id', $store->id)])
                ->when($rayon, fn ($q) => $q->whereHas('aisle', fn ($a) => $a->where('slug', $rayon)))
                ->get()
                ->sortBy(fn (Ingredient $i) => Str::lower(Str::ascii($i->name)))
                ->filter(fn (Ingredient $i) => $i->packs->isNotEmpty());
        }

        return view('prices.edit', [
            'store' => $store,
            'stores' => Store::active(),
            'aisles' => Aisle::ordered()->keyBy('id'),
            'groups' => $ingredients->groupBy('aisle_id'),
            'rayon' => $rayon,
            'all' => $all,
            'today' => now('Europe/Paris')->toDateString(),
        ]);
    }

    public function update(Request $request, Store $store)
    {
        $data = $request->validate([
            'prices' => ['nullable', 'array'],
            'prices.*' => ['nullable', 'string', 'max:12'],
            'promo' => ['nullable', 'array'],
            'observed_on' => ['required', 'date', 'before_or_equal:'.now('Europe/Paris')->toDateString(), 'after:2020-01-01'],
        ], [], ['observed_on' => 'date des prix']);

        $input = collect($data['prices'] ?? [])->filter(fn ($value) => trim((string) $value) !== '');
        $invalid = [];
        $changed = 0;

        DB::transaction(function () use ($input, $data, $store, $request, &$invalid, &$changed) {
            $packs = IngredientPack::with(['ingredient', 'prices' => fn ($q) => $q->where('store_id', $store->id)])
                ->whereIn('id', $input->keys()->map(fn ($id) => (int) $id))
                ->get()
                ->keyBy('id');

            foreach ($input as $packId => $value) {
                $pack = $packs->get((int) $packId);
                $cents = Price::parseEuros($value);

                if (! $pack || $cents === null || $cents > 100000) {
                    $invalid[] = $pack ? "{$pack->ingredient->name} ({$pack->label})" : "#{$packId}";

                    continue;
                }

                $promo = isset($data['promo'][$packId]);
                $current = $pack->currentPriceFor($store->id);

                if ($current && $current->price_cents === $cents && $current->is_promo === $promo && $current->source !== 'estimation') {
                    continue;
                }

                Price::create([
                    'ingredient_pack_id' => $pack->id,
                    'store_id' => $store->id,
                    'price_cents' => $cents,
                    'source' => 'manuel',
                    'is_promo' => $promo,
                    'observed_on' => $data['observed_on'],
                    'created_by' => $request->user()->id,
                ]);
                $changed++;
            }
        });

        $redirect = redirect()->route('prices.edit', ['store' => $store, 'rayon' => $request->input('rayon') ?: null, 'tous' => $request->boolean('tous') ? 1 : null]);

        if ($invalid !== []) {
            $redirect->withErrors(['prices' => 'Montants non compris, ignorés : '.implode(', ', $invalid).'.']);
        }

        return $redirect->with('status', $changed === 0
            ? 'Aucun prix modifié.'
            : "{$changed} prix enregistré".($changed > 1 ? 's' : '')." pour {$store->name}.");
    }
}
