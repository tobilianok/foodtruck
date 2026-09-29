<?php

namespace App\Support\Receipts;

use App\Models\IngredientPack;
use App\Models\Price;
use App\Models\Receipt;
use App\Models\ReceiptAlias;
use App\Models\ReceiptLine;
use App\Models\Store;
use App\Models\User;
use App\Support\UnitConversionException;
use App\Support\Units;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cycle de vie d'un ticket : lecture des lignes, rapprochement, puis application des prix
 * (prix « ticket » datés du jour d'achat) et mémorisation des libellés.
 */
class ReceiptProcessor
{
    private ?ReceiptMatcher $matcher = null;

    public function __construct(private readonly ReceiptParser $parser = new ReceiptParser)
    {
    }

    /** (Re)lit le texte du ticket et recrée ses lignes. Applique automatiquement si tout est reconnu. */
    public function ingest(Receipt $receipt): Receipt
    {
        $parsed = $this->parser->parse((string) $receipt->raw_text);

        DB::transaction(function () use ($receipt, $parsed) {
            $receipt->total_cents ??= $parsed['total_cents'];
            $receipt->purchased_on ??= $parsed['date'];
            $receipt->store_id ??= $this->guessStore($receipt->correspondent ?: $receipt->title ?: $receipt->raw_text)?->id;
            $receipt->status = Receipt::STATUS_TO_REVIEW;
            $receipt->save();

            $receipt->lines()->delete();
            foreach ($parsed['lines'] as $i => $line) {
                $normalized = ReceiptParser::normalize($line['label']);
                $match = $line['kind'] === 'produit'
                    ? $this->matcher()->match($normalized, $receipt->store_id, $line['quantity_unit'] === 'kg', $line['label'])
                    : ['status' => ReceiptLine::STATUS_IGNORED, 'pack' => null];

                // TVA à 20 % : produit d'entretien, hygiène, alcool… ignoré sauf libellé déjà associé
                $vat = $line['vat_rate'] ?? null;
                if ($vat !== null && $vat >= ReceiptLine::NON_FOOD_VAT && $match['status'] !== ReceiptLine::STATUS_KNOWN) {
                    $match = ['status' => ReceiptLine::STATUS_IGNORED, 'pack' => null];
                }

                $receipt->lines()->create([
                    'position' => ($i + 1) * 10,
                    'kind' => $line['kind'],
                    'raw_label' => $line['label'],
                    'normalized_label' => $normalized,
                    'quantity' => $line['quantity'],
                    'quantity_unit' => $line['quantity_unit'],
                    'unit_price_cents' => $line['unit_price_cents'],
                    'total_cents' => $line['total_cents'],
                    'discount_cents' => $line['discount_cents'],
                    'vat_rate' => $vat,
                    'status' => $match['status'],
                    'ingredient_id' => ($match['ingredient'] ?? $match['pack']?->ingredient)?->id,
                    'ingredient_pack_id' => $match['pack']?->id,
                ]);
            }
        });

        $receipt->load('lines.pack.ingredient');

        if ($this->isFullyKnown($receipt)) {
            $this->apply($receipt, null);
            $receipt->forceFill(['auto_applied' => true])->save();
        }

        return $receipt;
    }

    /** Tout est reconnu grâce aux libellés mémorisés : le ticket peut être traité sans intervention. */
    public function isFullyKnown(Receipt $receipt): bool
    {
        $products = $receipt->lines->where('kind', 'produit');

        return $receipt->store_id !== null
            && $receipt->purchased_on !== null
            && $products->isNotEmpty()
            && $products->every(fn (ReceiptLine $l) => in_array($l->status, [ReceiptLine::STATUS_KNOWN, ReceiptLine::STATUS_IGNORED], true));
    }

    /**
     * Enregistre les prix des lignes associées, mémorise les libellés et clôt le ticket
     * si plus aucune ligne n'est à associer. Renvoie le nombre de prix enregistrés.
     */
    public function apply(Receipt $receipt, ?User $user, bool $remember = true): int
    {
        $receipt->loadMissing('lines.pack.ingredient');
        $applied = 0;

        DB::transaction(function () use ($receipt, $user, $remember, &$applied) {
            foreach ($receipt->lines as $line) {
                if ($line->kind !== 'produit') {
                    continue;
                }

                if ($line->status === ReceiptLine::STATUS_IGNORED) {
                    continue;
                }

                if (! $line->pack || ! in_array($line->status, [ReceiptLine::STATUS_KNOWN, ReceiptLine::STATUS_SUGGESTED, ReceiptLine::STATUS_APPLIED], true)) {
                    continue;
                }

                $cents = self::packPrice($line, $line->pack);
                if ($cents === null || $cents <= 0 || $cents > 100000) {
                    continue;
                }

                $values = [
                    'ingredient_pack_id' => $line->pack->id,
                    'store_id' => $receipt->store_id,
                    'price_cents' => $cents,
                    'source' => 'ticket',
                    'is_promo' => $line->discount_cents > 0,
                    'observed_on' => $receipt->purchased_on,
                    'created_by' => $user?->id,
                ];

                $price = $line->price_id ? Price::find($line->price_id) : null;
                if ($price) {
                    $price->update($values);
                } else {
                    $price = Price::create($values);
                }

                $line->forceFill(['status' => ReceiptLine::STATUS_APPLIED, 'pack_price_cents' => $cents, 'price_id' => $price->id])->save();
                $applied++;

                if ($remember) {
                    $this->remember($receipt->store_id, $line->normalized_label, $line->pack->id, false, $user);
                }
            }

            $pending = $receipt->lines->where('kind', 'produit')
                ->whereIn('status', [ReceiptLine::STATUS_UNKNOWN, ReceiptLine::STATUS_SUGGESTED])->count();

            $receipt->forceFill([
                'status' => $pending === 0 ? Receipt::STATUS_DONE : Receipt::STATUS_TO_REVIEW,
                'processed_at' => now(),
                'processed_by' => $user?->id,
            ])->save();
        });

        return $applied;
    }

    /** Mémorise (ou corrige) le libellé d'un magasin : conditionnement ou « à ignorer ». */
    public function remember(?int $storeId, string $normalized, ?int $packId, bool $ignored, ?User $user): void
    {
        if ($storeId === null || $normalized === '') {
            return;
        }

        $alias = ReceiptAlias::firstOrNew(['store_id' => $storeId, 'normalized_label' => $normalized]);
        $alias->fill([
            'ingredient_pack_id' => $ignored ? null : $packId,
            'is_ignored' => $ignored,
            'created_by' => $alias->created_by ?? $user?->id,
        ]);
        $alias->hits = (int) $alias->hits + 1;
        $alias->save();
    }

    /**
     * Prix d'un conditionnement déduit d'une ligne :
     *   pesée → prix au kilo ramené à la quantité du conditionnement ;
     *   à l'unité → prix payé par article, ajusté si le libellé indique une autre quantité
     *   (ex. « 6X1L » associé à la bouteille de 1 L → prix d'une bouteille).
     */
    public static function packPrice(ReceiptLine $line, IngredientPack $pack): ?int
    {
        $ingredient = $pack->ingredient;
        $paid = $line->paidCents();

        if ($line->quantity <= 0 || $paid <= 0) {
            return null;
        }

        if ($line->isWeighted()) {
            try {
                $base = Units::toBase($line->quantity * 1000, 'g', $ingredient);
            } catch (UnitConversionException) {
                return null;
            }

            return $base > 0 ? (int) round($paid / $base * $pack->quantity) : null;
        }

        $perItem = $paid / $line->quantity;
        $labelQuantity = ReceiptMatcher::labelQuantity($line->normalized_label, $ingredient);

        if ($labelQuantity !== null && abs($labelQuantity - $pack->quantity) > $pack->quantity * 0.02) {
            return (int) round($perItem / $labelQuantity * $pack->quantity);
        }

        return (int) round($perItem);
    }

    /** Magasin déduit du correspondant Paperless (ou du texte) : noms connus puis mots-clés des enseignes. */
    public function guessStore(?string $text): ?Store
    {
        $haystack = ' '.Str::lower(Str::ascii((string) $text)).' ';
        if (trim($haystack) === '') {
            return null;
        }

        $stores = Store::all();

        foreach ($stores as $store) {
            foreach ((array) $store->receipt_names as $name) {
                if ($name !== '' && str_contains($haystack, Str::lower(Str::ascii($name)))) {
                    return $store;
                }
            }
        }

        $keywords = [
            'leclerc-drive' => ['leclerc'],
            'lidl' => ['lidl'],
            'carrefour' => ['carrefour'],
            'hyper-u' => ['hyper u', 'super u', 'magasins u', 'systeme u', ' u express'],
            'grand-frais' => ['grand frais'],
            'morin' => ['morin'],
        ];

        foreach ($keywords as $slug => $words) {
            foreach ($words as $word) {
                if (str_contains($haystack, $word)) {
                    return $stores->firstWhere('slug', $slug);
                }
            }
        }

        return null;
    }

    /** Retient un nom de correspondant pour un magasin (reconnaissance des prochains tickets). */
    public static function learnStoreName(Store $store, ?string $name): void
    {
        $name = trim((string) $name);
        if ($name === '') {
            return;
        }

        $names = (array) $store->receipt_names;
        if (! in_array($name, $names, true)) {
            $names[] = $name;
            $store->forceFill(['receipt_names' => $names])->save();
        }
    }

    private function matcher(): ReceiptMatcher
    {
        return $this->matcher ??= new ReceiptMatcher;
    }
}
