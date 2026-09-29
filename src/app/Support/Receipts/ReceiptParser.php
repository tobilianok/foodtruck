<?php

namespace App\Support\Receipts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Lecteur générique de tickets de caisse français (texte OCR ou PDF dématérialisé).
 *
 * Formats reconnus :
 *   LAIT 1/2 ECR 1L                    1,05 €        prix en fin de ligne (code TVA éventuel après)
 *   LAIT 1/2 ECR 1L                                  libellé seul, puis…
 *     2 x 1,05                          2,10        … ligne de quantité (avant/après, avec ou sans total)
 *   CAROTTES VRAC 0,856 kg x 2,49 €/kg  2,13        poids × prix au kilo (même ligne ou ligne suivante)
 *   REMISE IMMEDIATE                   -0,50        remise, rattachée à l'article précédent
 *   TOTAL / NET A PAYER               25,40        total du ticket (fin des articles)
 *
 * Les règles propres à une enseigne viendront s'ajouter ici avec de vrais tickets.
 */
class ReceiptParser
{
    private const PRICE = '-?\d{1,4}[,.]\d{2}';

    /** Débuts de lignes qui ne sont pas des articles (en-tête, paiement, TVA…). */
    private const SKIP = [
        'SOUS TOTAL', 'SOUS-TOTAL', 'S/TOTAL', 'STOTAL', 'TVA', 'T.V.A', 'HT', 'TTC', 'BASE', 'TAUX', 'CB', 'CARTE BANCAIRE',
        'CARTE BLEUE', 'CARTE CB', 'CARTE FIDELITE', 'VISA', 'MASTERCARD', 'ESPECES', 'ESP', 'RENDU', 'MONNAIE', 'PAIEMENT',
        'REGLEMENT', 'TICKET', 'CAISSE', 'CAISSIER', 'CAISSIERE', 'HOTE', 'HOTESSE', 'MERCI', 'FIDELITE', 'CAGNOTTE', 'POINTS', 'SOLDE',
        'NB ARTICLE', 'NB ARTICLES', 'NBRE ARTICLES', 'NOMBRE D', 'ARTICLES', 'SIRET', 'SIREN', 'TEL', 'TEL.', 'TELEPHONE', 'WWW', 'HTTP',
        'BIENVENUE', 'AU REVOIR', 'A BIENTOT', 'DATE', 'HEURE', 'TRANSACTION', 'AUTORISATION', 'DEBIT', 'CREDIT', 'SANS CONTACT',
        'CONTACTLESS', 'REPARTITION', 'MAGASIN', 'COMMANDE', 'FACTURE', 'CLIENT', 'VENDEUR', 'OPERATEUR', 'A CONSERVER', 'CONSERVEZ',
        'ECONOMIE', 'ECONOMIES', 'VOUS AVEZ', 'DONT', 'MONTANT TVA', 'EUR', 'EURO', 'EUROS',
    ];

    /** Lignes de total (fin de la liste des articles). */
    private const TOTAL = '/^(TOTAL(?! REMISE)(\s+TTC|\s+A PAYER|\s+EUR)?|NET A PAYER|A PAYER|MONTANT (A PAYER|DU|TOTAL)|TOTAL CB)\b/';

    /** Lignes de remise (en tête de libellé ; un montant négatif suffit aussi). */
    private const DISCOUNT = '/^(REMISE|REDUC|REDUCTION|PROMO|COUPON|BON D.?ACHAT|AVANTAGE|RABAIS|ANNUL|OFFRE)/';

    /**
     * @return array{lines: array<int, array{kind: string, label: string, quantity: float, quantity_unit: string, unit_price_cents: ?int, total_cents: int, discount_cents: int}>, total_cents: ?int, date: ?string}
     */
    public function parse(string $text): array
    {
        $items = [];
        $total = null;
        $pendingQuantity = null;
        $finished = false;

        foreach (preg_split('/\R/u', $text) as $rawLine) {
            $line = $this->clean($rawLine);
            if ($line === '') {
                continue;
            }

            $upper = $this->upper($line);

            if (preg_match(self::TOTAL, $upper) && ($price = $this->trailingPrice($line)) !== null) {
                $total ??= abs($price[0]);
                $finished = true;

                continue;
            }

            if ($finished || $this->isSkipped($upper)) {
                continue;
            }

            $weight = $this->weight($line);
            $quantity = $weight === null ? $this->quantity($line) : null;
            $price = $this->trailingPrice($line);

            // « 2 x 1,05 » sans total : le montant de fin de ligne est le prix unitaire, pas un total
            if ($quantity !== null && $price !== null && $price[1] < $quantity[2]) {
                $price = null;
            }
            $label = $this->label($line, $weight, $quantity, $price);
            $hasLabel = preg_match_all('/\pL/u', $label) >= 2;

            // Ligne sans libellé : quantité ou poids qui complète l'article voisin
            if (! $hasLabel) {
                if ($weight === null && $quantity === null) {
                    continue;
                }

                $last = array_key_last($items);
                if ($last !== null && ($items[$last]['total_cents'] === null || $this->lineOwnsDetails($items[$last]))) {
                    $this->applyDetails($items[$last], $weight, $quantity, $price);
                } else {
                    $pendingQuantity = [$weight, $quantity, $price];
                }

                continue;
            }

            $isDiscount = ($price !== null && $price[0] < 0) || ($price !== null && preg_match(self::DISCOUNT, $this->upper($label)));

            if ($isDiscount) {
                if ($price === null) {
                    continue;
                }
                $amount = abs($price[0]);
                $last = array_key_last($items);
                if ($last !== null && $items[$last]['kind'] === 'produit' && $items[$last]['total_cents'] !== null) {
                    $items[$last]['discount_cents'] += $amount;
                } else {
                    $items[] = $this->item('remise', $label, 1, 'piece', null, -$amount);
                }

                continue;
            }

            $item = $this->item('produit', $label, 1, 'piece', null, $price[0] ?? null);
            $this->applyDetails($item, $weight, $quantity, $price);

            if ($pendingQuantity !== null) {
                $this->applyDetails($item, ...$pendingQuantity);
                $pendingQuantity = null;
            }

            $items[] = $item;
        }

        $lines = [];
        foreach ($items as $item) {
            if ($item['total_cents'] === null) {
                continue;
            }
            if ($item['unit_price_cents'] === null && $item['quantity'] > 0) {
                $item['unit_price_cents'] = (int) round($item['total_cents'] / $item['quantity']);
            }
            $lines[] = $item;
        }

        return ['lines' => $lines, 'total_cents' => $total, 'date' => $this->date($text)];
    }

    /** Clé de rapprochement d'un libellé : majuscules sans accents, ponctuation réduite. */
    public static function normalize(string $label): string
    {
        $value = Str::upper(Str::ascii($label));
        $value = preg_replace('/[^A-Z0-9%\/,.]+/', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function item(string $kind, string $label, float $quantity, string $unit, ?int $unitPrice, ?int $total): array
    {
        return [
            'kind' => $kind,
            'label' => Str::limit($label, 200, ''),
            'quantity' => $quantity,
            'quantity_unit' => $unit,
            'unit_price_cents' => $unitPrice,
            'total_cents' => $total,
            'discount_cents' => 0,
        ];
    }

    /** Un article déjà chiffré peut recevoir sa ligne de détail si la quantité n'a pas encore été précisée. */
    private function lineOwnsDetails(array $item): bool
    {
        return $item['kind'] === 'produit' && $item['quantity'] == 1 && $item['quantity_unit'] === 'piece' && $item['unit_price_cents'] === null;
    }

    private function applyDetails(array &$item, ?array $weight, ?array $quantity, ?array $price): void
    {
        if ($weight !== null) {
            [$kg, $perKg] = $weight;
            $item['quantity'] = $kg;
            $item['quantity_unit'] = 'kg';
            $item['unit_price_cents'] = $perKg;
            $item['total_cents'] = $price[0] ?? $item['total_cents'] ?? (int) round($kg * $perKg);
        } elseif ($quantity !== null) {
            [$count, $unitPrice] = $quantity;
            $item['quantity'] = (float) $count;
            $item['unit_price_cents'] = $unitPrice;
            $item['total_cents'] = $price[0] ?? $count * $unitPrice;
        } elseif ($price !== null && $item['total_cents'] === null) {
            $item['total_cents'] = $price[0];
        }
    }

    /** @return array{0: float, 1: int}|null [poids en kg, prix au kg en centimes] */
    private function weight(string $line): ?array
    {
        if (preg_match('/(\d+[,.]\d{1,3})\s*kg\s*[x×*]\s*(\d{1,4}[,.]\d{2})\s*(?:€|eur)?\s*\/\s*kg/iu', $line, $m)) {
            return [$this->number($m[1]), $this->cents($m[2])];
        }

        return null;
    }

    /** @return array{0: int, 1: int, 2: int}|null [nombre, prix unitaire en centimes, position de fin] */
    private function quantity(string $line): ?array
    {
        if (preg_match('/(?<![\d,.])(\d{1,3})\s*[x×*]\s*(\d{1,4}[,.]\d{2})(?!\s*(?:€|eur)?\s*\/)/iu', $line, $m, PREG_OFFSET_CAPTURE) && (int) $m[1][0] > 0) {
            return [(int) $m[1][0], $this->cents($m[2][0]), $m[0][1] + strlen($m[0][0])];
        }

        return null;
    }

    /** Dernier montant de la ligne, éventuellement suivi de « € » et d'un code TVA. @return array{0: int, 1: int}|null [centimes, position] */
    private function trailingPrice(string $line): ?array
    {
        if (preg_match('/(?<![\d,.\/])('.self::PRICE.')\s*(?:€|eur|euros?)?\s*(?:[A-D]|[1-4]|\*|T\d?)?\s*$/iu', $line, $m, PREG_OFFSET_CAPTURE)) {
            return [$this->cents($m[1][0]), $m[1][1]];
        }

        return null;
    }

    private function label(string $line, ?array $weight, ?array $quantity, ?array $price): string
    {
        if ($price !== null) {
            $line = substr($line, 0, $price[1]);
        }

        $line = preg_replace('/\d+[,.]\d{1,3}\s*kg\s*[x×*]\s*\d{1,4}[,.]\d{2}\s*(?:€|eur)?\s*\/\s*kg/iu', ' ', $line);
        $line = preg_replace('/(?<![\d,.])\d{1,3}\s*[x×*]\s*\d{1,4}[,.]\d{2}(?:\s*(?:€|eur))?/iu', ' ', $line);
        $line = preg_replace('/^\s*(\d{6,14}|\d{1,3}\s+(?=\pL))\s*/u', '', $line); // code article ou quantité en tête
        $line = preg_replace('/\s*(€|eur)\s*$/iu', '', $line);

        return trim(preg_replace('/\s+/', ' ', $line), " \t-:*");
    }

    private function isSkipped(string $upper): bool
    {
        foreach (self::SKIP as $prefix) {
            if (preg_match('/^'.preg_quote($prefix, '/').'(?![A-Z])/', $upper)) {
                return true;
            }
        }

        // Lignes de date/heure, de séparateurs ou de codes seuls
        return (bool) preg_match('/^(\d{2}[\/.\-]\d{2}[\/.\-]\d{2,4}|[\d\s:\/.\-=*_#]+$)/', $upper);
    }

    private function clean(string $line): string
    {
        $line = str_replace(["\t", "\u{00A0}", '|'], ' ', $line);
        // Erreurs d'OCR fréquentes dans les montants : O à la place de 0
        $line = preg_replace('/(?<=\d)[Oo](?=\d)|(?<=\d[,.])[Oo]|(?<=[,.]\d)[Oo]/', '0', $line);

        return trim(preg_replace('/\s+/', ' ', $line));
    }

    private function upper(string $value): string
    {
        return Str::upper(Str::ascii($value));
    }

    private function date(string $text): ?string
    {
        if (! preg_match('/\b(\d{2})[\/.\-](\d{2})[\/.\-](\d{4}|\d{2})\b/', $text, $m)) {
            return null;
        }

        $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
        if (! checkdate((int) $m[2], (int) $m[1], $year) || $year < 2020) {
            return null;
        }

        $date = Carbon::create($year, (int) $m[2], (int) $m[1]);

        return $date->isFuture() ? null : $date->toDateString();
    }

    private function number(string $value): float
    {
        return (float) str_replace(',', '.', $value);
    }

    private function cents(string $value): int
    {
        return (int) round($this->number($value) * 100);
    }
}
