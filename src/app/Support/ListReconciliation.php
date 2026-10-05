<?php

namespace App\Support;

use App\Models\Price;
use App\Models\Receipt;
use App\Models\ReceiptLine;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Illuminate\Support\Collection;

/**
 * Rapprochement ticket de caisse ↔ liste de courses.
 *
 * - Un ticket traité est rattaché automatiquement à la liste dont la période correspond à sa date d'achat
 *   (modifiable à la main depuis la fiche du ticket).
 * - Les articles retrouvés sur le ticket sont cochés (sauf sur une liste déjà classée : le stock a alors été mis à jour sans eux).
 * - Comparaison payé / estimé article par article, achats hors liste, alertes de prix.
 *
 * Les prix eux-mêmes sont recalés par ReceiptProcessor::apply (prix « ticket » datés du jour d'achat).
 */
class ListReconciliation
{
    /** Courses faites jusqu'à 4 jours avant le début de la période, ou 1 jour après sa fin. */
    private const DAYS_BEFORE = 4;

    private const DAYS_AFTER = 1;

    /** Hausse à signaler : 10 % au moins et 10 centimes au moins. */
    private const RISE_RATIO = 0.10;

    private const RISE_MIN_CENTS = 10;

    /** Liste dont la période correspond le mieux à la date d'achat du ticket. */
    public static function candidate(Receipt $receipt): ?ShoppingList
    {
        if ($receipt->purchased_on === null) {
            return null;
        }

        $date = $receipt->purchased_on->copy()->startOfDay();

        return ShoppingList::where('household_id', $receipt->household_id)
            ->whereDate('date_from', '<=', $date->copy()->addDays(self::DAYS_BEFORE)->toDateString())
            ->whereDate('date_to', '>=', $date->copy()->subDays(self::DAYS_AFTER)->toDateString())
            ->get()
            ->sortBy(fn (ShoppingList $l) => [abs($l->date_from->diffInDays($date, false)), -$l->id])
            ->first();
    }

    /** Rattache un ticket traité à sa liste, s'il n'en a pas déjà une. Renvoie le nombre d'articles cochés. */
    public static function autoLink(Receipt $receipt): int
    {
        if ($receipt->shopping_list_id !== null || $receipt->status !== Receipt::STATUS_DONE) {
            return 0;
        }

        $list = self::candidate($receipt);

        return $list ? self::link($receipt, $list) : 0;
    }

    /** Rattache le ticket à la liste (null = détacher). Renvoie le nombre d'articles cochés. */
    public static function link(Receipt $receipt, ?ShoppingList $list): int
    {
        $receipt->forceFill(['shopping_list_id' => $list?->id])->save();

        return $list ? self::tick($list) : 0;
    }

    /** Coche les articles de la liste retrouvés sur ses tickets. */
    public static function tick(ShoppingList $list): int
    {
        if ($list->isArchived()) {
            return 0;
        }

        $bought = self::linesByIngredient($list->receipts()->with('lines.pack')->get());
        $ticked = 0;

        foreach ($list->items()->where('is_checked', false)->whereNotNull('ingredient_id')->where('section', '!=', ShoppingListItem::SECTION_STOCK)->get() as $item) {
            if ($bought->has($item->ingredient_id)) {
                $item->forceFill(['is_checked' => true, 'checked_by' => null, 'checked_at' => now()])->save();
                $ticked++;
            }
        }

        if ($ticked > 0) {
            $list->bump();
        }

        return $ticked;
    }

    /** Lignes alimentaires lues et associées d'un ticket, par ingrédient. @return Collection<int, Collection<int, ReceiptLine>> */
    public static function linesByIngredient(Collection $receipts): Collection
    {
        return $receipts->flatMap(fn (Receipt $r) => $r->lines->where('kind', 'produit'))
            ->filter(fn (ReceiptLine $l) => in_array($l->status, [ReceiptLine::STATUS_APPLIED, ReceiptLine::STATUS_KNOWN], true) && ! $l->isNonFood())
            ->filter(fn (ReceiptLine $l) => self::ingredientId($l) !== null)
            ->groupBy(fn (ReceiptLine $l) => self::ingredientId($l));
    }

    public static function ingredientId(ReceiptLine $line): ?int
    {
        $id = $line->pack?->ingredient_id ?? $line->ingredient_id;

        return $id !== null ? (int) $id : null;
    }

    /**
     * Payé / estimé pour une liste.
     *
     * @return array{receipts: Collection, rows: array<int, array<string, mixed>>, extras: array<int, array<string, mixed>>, estimated_cents: int, matched_estimated_cents: int, matched_paid_cents: int, extras_cents: int, paid_cents: int, receipts_total_cents: int, budget_cents: int, missing: int, matched: int}
     */
    public static function compare(ShoppingList $list): array
    {
        $receipts = $list->receipts()->with(['store', 'lines.pack.ingredient', 'lines.ingredient'])->get();
        $lines = self::linesByIngredient($receipts);
        $usedLineIds = [];
        $rows = [];

        foreach ($list->items()->with('store')->get() as $item) {
            if ($item->section === ShoppingListItem::SECTION_STOCK) {
                continue;
            }

            $matched = $item->ingredient_id ? ($lines->get($item->ingredient_id) ?? collect()) : collect();

            // Produit de base à vérifier : n'apparaît que s'il a été acheté
            if ($matched->isEmpty() && $item->section === ShoppingListItem::SECTION_CHECK) {
                continue;
            }

            foreach ($matched as $line) {
                $usedLineIds[$line->id] = true;
            }

            $rows[] = [
                'item' => $item,
                'estimated_cents' => $item->section === ShoppingListItem::SECTION_BUY ? $item->estimated_cents : null,
                'paid_cents' => $matched->isEmpty() ? null : (int) $matched->sum(fn (ReceiptLine $l) => $l->paidCents()),
                'found' => $matched->isNotEmpty(),
                'stores' => $matched->map(fn (ReceiptLine $l) => $l->receipt_id)->unique()
                    ->map(fn ($id) => $receipts->firstWhere('id', $id)?->store?->name)->filter()->unique()->values()->all(),
            ];
        }

        $extras = [];
        foreach ($receipts as $receipt) {
            foreach ($receipt->lines->where('kind', 'produit') as $line) {
                if ($line->status === ReceiptLine::STATUS_IGNORED || $line->isNonFood() || isset($usedLineIds[$line->id]) || $line->paidCents() <= 0) {
                    continue;
                }
                $extras[] = [
                    'label' => $line->ingredient?->name ?? $line->pack?->ingredient?->name ?? $line->raw_label,
                    'paid_cents' => $line->paidCents(),
                    'store' => $receipt->store?->name,
                ];
            }
        }

        $buy = collect($rows)->filter(fn ($r) => $r['item']->section === ShoppingListItem::SECTION_BUY);
        $found = collect($rows)->filter(fn ($r) => $r['found']);
        $matchedRows = $found->filter(fn ($r) => $r['estimated_cents'] !== null);
        $extrasCents = (int) collect($extras)->sum('paid_cents');
        $matchedPaid = (int) $found->sum('paid_cents');

        return [
            'receipts' => $receipts,
            'rows' => $rows,
            'extras' => $extras,
            'estimated_cents' => (int) $buy->sum(fn ($r) => (int) $r['estimated_cents']),
            'matched_estimated_cents' => (int) $matchedRows->sum('estimated_cents'),
            'matched_paid_cents' => (int) $matchedRows->sum('paid_cents'),
            'extras_cents' => $extrasCents,
            'paid_cents' => $matchedPaid + $extrasCents,
            'receipts_total_cents' => (int) $receipts->sum(fn (Receipt $r) => (int) $r->total_cents),
            'budget_cents' => $list->budgetCents(),
            'missing' => $buy->filter(fn ($r) => ! $r['found'] && $r['item']->ingredient_id !== null)->count(),
            'matched' => $found->count(),
        ];
    }

    /**
     * Prix relevés sur les tickets de la liste, comparés au prix précédent du même conditionnement dans le même magasin.
     *
     * - « hausses » : le prix a monté d'au moins 10 % (et 10 centimes) par rapport à un précédent prix réel (ticket ou saisi).
     * - « recalages » : le prix réel diffère d'au moins 10 % de l'estimation de départ, dans un sens ou dans l'autre.
     * Les promotions ne comptent pas.
     *
     * @return array{hausses: array<int, array<string, mixed>>, recalages: array<int, array<string, mixed>>}
     */
    public static function priceChanges(ShoppingList $list): array
    {
        $hausses = [];
        $recalages = [];
        $seen = [];

        $receipts = $list->receipts()->with(['store', 'lines.price', 'lines.pack.ingredient'])->get();

        foreach ($receipts as $receipt) {
            foreach ($receipt->lines as $line) {
                $price = $line->price;
                if ($price === null || $line->status !== ReceiptLine::STATUS_APPLIED || $price->is_promo || isset($seen[$price->id])) {
                    continue;
                }
                $seen[$price->id] = true;

                $previous = Price::where('ingredient_pack_id', $price->ingredient_pack_id)->where('store_id', $price->store_id)
                    ->where('id', '!=', $price->id)->where('is_promo', false)
                    ->where(function ($q) use ($price) {
                        $q->whereDate('observed_on', '<', $price->observed_on->toDateString())
                            ->orWhere(fn ($q) => $q->whereDate('observed_on', $price->observed_on->toDateString())->where('id', '<', $price->id));
                    })
                    ->orderByDesc('observed_on')->orderByDesc('id')->first();

                if ($previous === null) {
                    continue;
                }

                $diff = $price->price_cents - $previous->price_cents;
                $significant = abs($diff) >= self::RISE_MIN_CENTS && abs($diff) >= $previous->price_cents * self::RISE_RATIO;
                if (! $significant) {
                    continue;
                }

                $entry = [
                    'ingredient' => $line->pack?->ingredient?->name ?? $line->raw_label,
                    'pack' => $line->pack?->label,
                    'store' => $receipt->store?->name,
                    'from_cents' => $previous->price_cents,
                    'to_cents' => $price->price_cents,
                    'percent' => (int) round($diff / max(1, $previous->price_cents) * 100),
                ];

                if ($previous->source === 'estimation') {
                    $recalages[] = $entry;
                } elseif ($diff > 0) {
                    $hausses[] = $entry;
                }
            }
        }

        usort($hausses, fn ($a, $b) => $b['percent'] <=> $a['percent']);
        usort($recalages, fn ($a, $b) => abs($b['percent']) <=> abs($a['percent']));

        return ['hausses' => $hausses, 'recalages' => $recalages];
    }
}
