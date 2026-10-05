<?php

namespace App\Http\Controllers;

use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Store;
use App\Support\ShoppingListBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Liste de courses du foyer : calculée depuis le planning, partagée et cochable en direct par toute la famille.
 */
class ShoppingController extends Controller
{
    /** Période maximale d'une liste. */
    private const MAX_DAYS = 31;

    public function index(Request $request)
    {
        $household = $request->user()->household;
        $list = $household->shoppingLists()->whereNull('archived_at')->first();

        if ($list === null) {
            return view('shopping.empty', [
                'from' => now('Europe/Paris')->toDateString(),
                'to' => now('Europe/Paris')->addDays(6)->toDateString(),
                'previous' => $household->shoppingLists()->whereNotNull('archived_at')->limit(5)->get(),
            ]);
        }

        return $this->render($request, $list);
    }

    public function show(Request $request, ShoppingList $list)
    {
        $this->authorizeList($request, $list);

        return $this->render($request, $list);
    }

    public function store(Request $request)
    {
        $household = $request->user()->household;
        $period = $this->period($request);

        $list = DB::transaction(function () use ($household, $period, $request) {
            // Une seule liste en cours : la précédente est classée
            $household->shoppingLists()->whereNull('archived_at')->update(['archived_at' => now()]);

            return ShoppingList::create($period + ['household_id' => $household->id, 'created_by' => $request->user()->id]);
        });

        $list->setRelation('household', $household);
        ShoppingListBuilder::rebuild($list);

        $count = $list->items()->count();

        return redirect()->route('shopping.index')->with('status', $count === 0
            ? 'Liste créée, mais aucun plat n\'est prévu sur cette période : ajoute des repas dans le planning, puis mets la liste à jour.'
            : "Liste créée : {$count} articles calculés d'après le planning.");
    }

    /** Période et repas pris en compte. */
    public function update(Request $request, ShoppingList $list)
    {
        $this->authorizeList($request, $list);
        abort_if($list->isArchived(), 404);

        $period = $this->period($request);

        // Repas décochés = écartés ; ceux qui n'étaient pas affichés gardent leur état
        $shown = array_map('intval', (array) $request->input('vus', []));
        $kept = array_map('intval', (array) $request->input('inclus', []));
        $previous = array_map('intval', $list->excluded_entry_ids ?? []);
        $excluded = array_values(array_unique(array_merge(array_diff($previous, $shown), array_diff($shown, $kept))));

        $list->forceFill($period + ['excluded_entry_ids' => $excluded ?: null])->save();
        $list->setRelation('household', $request->user()->household);

        ShoppingListBuilder::rebuild($list);

        return redirect()->route('shopping.index')->with('status', 'Période et repas mis à jour : liste recalculée.');
    }

    public function refresh(Request $request, ShoppingList $list)
    {
        $this->authorizeList($request, $list);
        abort_if($list->isArchived(), 404);

        $list->setRelation('household', $request->user()->household);
        ShoppingListBuilder::rebuild($list);

        return redirect()->route('shopping.index')->with('status', 'Liste recalculée d\'après le planning (articles cochés et choix conservés).');
    }

    public function archive(Request $request, ShoppingList $list)
    {
        $this->authorizeList($request, $list);

        $list->update(['archived_at' => now()]);

        return redirect()->route('shopping.index')->with('status', 'Courses terminées : la liste est classée dans l\'historique.');
    }

    public function reopen(Request $request, ShoppingList $list)
    {
        $this->authorizeList($request, $list);

        DB::transaction(function () use ($request, $list) {
            $request->user()->household->shoppingLists()->whereNull('archived_at')->update(['archived_at' => now()]);
            $list->update(['archived_at' => null]);
        });

        return redirect()->route('shopping.index')->with('status', 'Liste rouverte.');
    }

    /** État léger pour la synchronisation en direct entre téléphones. */
    public function state(Request $request, ShoppingList $list): JsonResponse
    {
        $this->authorizeList($request, $list);

        $items = $list->items()->with('checker')->get();

        return response()->json([
            'revision' => $list->revision,
            'archived' => $list->isArchived(),
            'stale' => ! $list->isArchived() && ShoppingListBuilder::isStale($list->setRelation('household', $request->user()->household)),
            'checked' => $items->mapWithKeys(fn (ShoppingListItem $i) => [$i->id => [(int) $i->is_checked, $i->is_checked ? $i->checker?->firstName() : null]]),
            'done' => $items->where('section', ShoppingListItem::SECTION_BUY)->where('is_checked', true)->count(),
            'total' => $items->where('section', ShoppingListItem::SECTION_BUY)->count(),
        ]);
    }

    public function check(Request $request, ShoppingListItem $item)
    {
        $this->authorizeItem($request, $item);

        $checked = $request->has('checked') ? $request->boolean('checked') : ! $item->is_checked;
        $item->update([
            'is_checked' => $checked,
            'checked_by' => $checked ? $request->user()->id : null,
            'checked_at' => $checked ? now() : null,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['id' => $item->id, 'checked' => (int) $checked, 'by' => $checked ? $request->user()->firstName() : null]);
        }

        return redirect()->to(route('shopping.index').'#article-'.$item->id);
    }

    /** Changer un article de magasin, ou le passer de « à acheter » à « à vérifier chez vous » et inversement. */
    public function updateItem(Request $request, ShoppingListItem $item)
    {
        $this->authorizeItem($request, $item);
        $action = $request->validate(['action' => ['required', Rule::in(['store', 'section'])]])['action'];

        if ($action === 'store') {
            $storeId = $request->validate(['store_id' => ['required', 'integer', Rule::exists('stores', 'id')->where('is_active', true)]])['store_id'];
            ShoppingListBuilder::moveToStore($item, (int) $storeId);
            $message = '« '.$item->label.' » déplacé chez '.Store::find($storeId)->name.'.';
        } else {
            $toCheck = ! $item->isToCheck();
            $item->update(['section' => $toCheck ? ShoppingListItem::SECTION_CHECK : ShoppingListItem::SECTION_BUY, 'section_locked' => true]);
            $message = $toCheck
                ? '« '.$item->label.' » : déjà à la maison, passé dans « À vérifier chez vous » (hors budget).'
                : '« '.$item->label.' » ajouté aux courses.';
        }

        $item->list->bump();

        return redirect()->to(route('shopping.index').'#article-'.$item->id)->with('status', $message);
    }

    public function storeItem(Request $request, ShoppingList $list)
    {
        $this->authorizeList($request, $list);
        abort_if($list->isArchived(), 404);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'quantity_text' => ['nullable', 'string', 'max:60'],
            'aisle_id' => ['nullable', 'integer', 'exists:aisles,id'],
            'store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')->where('is_active', true)],
        ], [], ['label' => 'article', 'quantity_text' => 'quantité']);

        $household = $request->user()->household->loadMissing('mainStore', 'produceStore');
        $label = trim($data['label']);
        $storeIds = Store::active()->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Un article connu du référentiel garde son rayon et son prix ; sinon c'est une ligne libre
        $ingredient = Ingredient::with('packs.prices', 'aisle')
            ->whereRaw('lower(name) = ?', [mb_strtolower($label)])->orWhere('slug', Str::slug($label))->first();

        $attributes = [
            'shopping_list_id' => $list->id,
            'source' => ShoppingListItem::SOURCE_MANUAL,
            'section' => ShoppingListItem::SECTION_BUY,
            'label' => $label,
            'quantity_text' => $data['quantity_text'] ?? null,
            'aisle_id' => $data['aisle_id'] ?? $ingredient?->aisle_id,
        ];

        if ($ingredient !== null) {
            $storeId = ! empty($data['store_id']) ? (int) $data['store_id'] : ShoppingListBuilder::chooseStore($ingredient, null, $household, $storeIds);
            $attributes += ['ingredient_id' => $ingredient->id, 'base_unit' => $ingredient->base_unit, 'store_locked' => ! empty($data['store_id'])]
                + ShoppingListBuilder::priced($ingredient, null, $storeId, $storeIds);
        } else {
            $attributes += ['store_id' => $data['store_id'] ?? $household->main_store_id, 'store_locked' => ! empty($data['store_id'])];
        }

        $item = ShoppingListItem::create($attributes);
        $list->bump();

        return redirect()->to(route('shopping.index').'#article-'.$item->id)->with('status', '« '.$label.' » ajouté à la liste.');
    }

    public function destroyItem(Request $request, ShoppingListItem $item)
    {
        $this->authorizeItem($request, $item);
        abort_unless($item->isManual(), 404);

        $item->delete();
        $item->list->bump();

        return redirect()->route('shopping.index')->with('status', '« '.$item->label.' » retiré de la liste.');
    }

    // ------------------------------------------------------------------ affichage

    private function render(Request $request, ShoppingList $list)
    {
        $household = $request->user()->household->loadMissing('members', 'mainStore', 'produceStore');
        $list->setRelation('household', $household);

        $items = $list->items()->with('ingredient', 'aisle', 'store', 'bestStore', 'checker')->get();
        $buy = $items->where('section', ShoppingListItem::SECTION_BUY);
        $check = $items->where('section', ShoppingListItem::SECTION_CHECK);

        $stores = Store::active();
        $aisles = Aisle::ordered()->keyBy('id');

        $order = $stores->pluck('id')->map(fn ($id) => (int) $id)->all();
        // Magasin principal d'abord, puis le primeur, puis les autres (dans l'ordre des magasins)
        $position = array_flip($order);
        $rank = fn (int $id) => [$id === (int) $household->main_store_id ? 0 : ($id === (int) $household->produce_store_id ? 1 : 2), $position[$id]];
        usort($order, fn ($a, $b) => $rank($a) <=> $rank($b));

        $byStore = [];
        foreach ($order as $storeId) {
            $storeItems = $buy->where('store_id', $storeId);
            if ($storeItems->isNotEmpty()) {
                $byStore[] = $this->storeBlock($stores->firstWhere('id', $storeId), $storeItems, $aisles);
            }
        }
        $unplaced = $buy->filter(fn ($i) => $i->store_id === null || ! in_array((int) $i->store_id, $order, true));
        if ($unplaced->isNotEmpty()) {
            $byStore[] = $this->storeBlock(null, $unplaced, $aisles);
        }

        $total = (int) $buy->sum('estimated_cents');
        $used = (int) $buy->filter(fn ($i) => $i->estimated_cents !== null && $i->used_cents !== null)->sum('used_cents');
        $paidForUsed = (int) $buy->filter(fn ($i) => $i->estimated_cents !== null && $i->used_cents !== null)->sum('estimated_cents');

        return view('shopping.show', [
            'list' => $list,
            'household' => $household,
            'byStore' => $byStore,
            'check' => $check->sortBy([fn ($a, $b) => ($aisles->get($a->aisle_id)?->position ?? 9999) <=> ($aisles->get($b->aisle_id)?->position ?? 9999), fn ($a, $b) => strcmp($a->label, $b->label)])->values(),
            'stores' => $stores,
            'aisles' => $aisles->values(),
            'total' => $total,
            'unknown' => $buy->whereNull('estimated_cents')->count(),
            'surplus' => max(0, $paidForUsed - $used),
            'saving' => (int) $buy->where('is_checked', false)->sum(fn (ShoppingListItem $i) => $i->possibleSaving()),
            'budget' => $list->budgetCents(),
            'done' => $buy->where('is_checked', true)->count(),
            'count' => $buy->count(),
            'stale' => ! $list->isArchived() && ShoppingListBuilder::isStale($list),
            'entries' => ShoppingListBuilder::entries($list, withExcluded: true),
            'excluded' => array_map('intval', $list->excluded_entry_ids ?? []),
            'previous' => $household->shoppingLists()->where('id', '!=', $list->id)->whereNotNull('archived_at')->limit(5)->get(),
            'season' => (int) now('Europe/Paris')->month,
        ]);
    }

    /** @return array{store: ?Store, aisles: array<int, array{aisle: ?Aisle, items: Collection}>, total: int, unknown: int, count: int, done: int} */
    private function storeBlock(?Store $store, Collection $items, Collection $aisles): array
    {
        $groups = $items->groupBy(fn (ShoppingListItem $i) => $i->aisle_id ?? 0)
            ->sortBy(fn ($group, $aisleId) => $aisles->get($aisleId)?->position ?? 9999)
            ->map(fn ($group, $aisleId) => [
                'aisle' => $aisles->get($aisleId),
                'items' => $group->sortBy(fn ($i) => Str::lower($i->label))->values(),
            ])->values()->all();

        return [
            'store' => $store,
            'aisles' => $groups,
            'total' => (int) $items->sum('estimated_cents'),
            'unknown' => $items->whereNull('estimated_cents')->count(),
            'count' => $items->count(),
            'done' => $items->where('is_checked', true)->count(),
        ];
    }

    // ------------------------------------------------------------------ utilitaires

    /** @return array{date_from: string, date_to: string} */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ], [
            'date_to.after_or_equal' => 'La fin de la période doit être le même jour ou après le début.',
        ], ['date_from' => 'début', 'date_to' => 'fin']);

        $from = Carbon::createFromFormat('Y-m-d', $data['date_from']);
        $to = Carbon::createFromFormat('Y-m-d', $data['date_to']);
        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            $to = $from->copy()->addDays(self::MAX_DAYS - 1);
        }

        return ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()];
    }

    private function authorizeList(Request $request, ShoppingList $list): void
    {
        abort_unless($list->household_id === $request->user()->household_id, 404);
    }

    private function authorizeItem(Request $request, ShoppingListItem $item): void
    {
        abort_unless($item->list->household_id === $request->user()->household_id, 404);
    }
}
