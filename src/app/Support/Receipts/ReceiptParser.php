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
 * Tickets Lidl Plus lus par OCR (texte Paperless) : colonnes « P.U. Qté Total » bruitées
 * (« 1,/9 », quantité « 7 » ou « | » pour 1, codes collés « 5,99BT »), remises aberrantes
 * (« -60,36 » pour -0,36), pesée illisible, lignes illisibles signalées.
 *
 * Les bons de commande Leclerc Drive ont leur propre lecteur (LeclercDriveParser).
 */
class ReceiptParser
{
    private const PRICE = '-?\d{1,4}[,.]\d{2}';

    /** Débuts de lignes qui ne sont pas des articles (en-tête, paiement, TVA…). */
    private const SKIP = [
        'SOUS TOTAL', 'SOUS-TOTAL', 'S/TOTAL', 'STOTAL', 'TVA', 'T.V.A', 'HT', 'TTC', 'BASE', 'TAUX', 'CB', 'CARTE BANCAIRE',
        'CARTE BLEUE', 'CARTE CB', 'CARTE FIDELITE', 'VISA', 'MASTERCARD', 'ESPECES', 'ESP', 'RENDU', 'MONNAIE', 'PAIEMENT',
        'REGLEMENT', 'TICKET', 'CAISSE', 'CAISSIER', 'CAISSIERE', 'HOTE', 'HOTESSE', 'MERCI', 'FIDELITE', 'CAGNOTTE', 'POINTS', 'SOLDE',
        'NB ARTICLE', 'NB ARTICLES', 'NBRE ARTICLES', 'NOMBRE DE LIGNES', 'NOMBRE D ARTICLES', 'NOMBRE ARTICLES', 'ARTICLES', 'ARTICLE', 'SIRET', 'SIREN', 'TEL', 'TEL.', 'TELEPHONE', 'WWW', 'HTTP',
        'BIENVENUE', 'AU REVOIR', 'A BIENTOT', 'DATE', 'HEURE', 'TRANSACTION', 'AUTORISATION', 'DEBIT', 'CREDIT', 'SANS CONTACT',
        'CONTACTLESS', 'REPARTITION', 'MAGASIN', 'COMMANDE', 'FACTURE', 'CLIENT', 'VENDEUR', 'OPERATEUR', 'A CONSERVER', 'CONSERVEZ',
        'ECONOMIE', 'ECONOMIES', 'VOUS AVEZ', 'DONT', 'MONTANT TVA', 'EUR', 'EURO', 'EUROS',
    ];

    /** Lignes de total (fin de la liste des articles). */
    private const TOTAL = '/^(TOTAL(?! (REMISE|PROMO|ELIGIBLE|ECONOMI|HT|TVA))(\s+TTC|\s+A PAYER|\s+EUR)?|NET A PAYER|A PAYER|MONTANT (A PAYER|DU|TOTAL)|TOTAL CB)\b/';

    /** Lignes de remise (en tête de libellé ; un montant négatif suffit aussi). */
    private const DISCOUNT = '/^(REMISE|REDUC|REDUCTION|PROMO|COUPON|BON D.?ACHAT|AVANTAGE|RABAIS|ANNUL|OFFRE)/';

    public const NOTE_WEIGHT = 'poids illisible sur le ticket : prix non enregistré';

    public const NOTE_DEDUCED = 'ligne illisible : montant déduit du total du ticket';

    public const NOTE_CORRECTED = 'lecture corrigée (ticket peu lisible) : vérifie le prix';

    /**
     * @return array{lines: array<int, array{kind: string, label: string, quantity: float, quantity_unit: string, unit_price_cents: ?int, total_cents: int, discount_cents: int, vat_rate: ?float, non_food: bool, note: ?string, uncertain: bool}>, total_cents: ?int, date: ?string, expected_lines: ?int, unread: array<int, string>}
     */
    public function parse(string $text): array
    {
        if (LeclercDriveParser::accepts($text)) {
            return (new LeclercDriveParser)->parse($text);
        }

        $vatRates = $this->vatTable($text);
        $items = [];
        $total = null;
        $expected = null;
        $pendingQuantity = null;
        $finished = false;
        $priced = false;

        foreach (preg_split('/\R/u', $text) as $rawLine) {
            $line = $this->clean($rawLine);
            if ($line === '') {
                continue;
            }

            $upper = $this->upper($line);

            if (preg_match('/^NOMBRE (?:DE LIGNES|D.?ARTICLES)\s*:?\s*(\d{1,3})\b/', $upper, $m)) {
                $expected ??= (int) $m[1];
            }

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

            // Ligne de pesée illisible (« QG 016 KO © L 99 EUR/kg ») : l'article précédent est pesé, poids inconnu
            if ($weight === null && preg_match('/(?:€|EUR)\s*\/\s*KG\b/', $upper)) {
                $last = array_key_last($items);
                if ($last !== null && $this->lineOwnsDetails($items[$last])) {
                    $items[$last]['quantity'] = 0.0;
                    $items[$last]['quantity_unit'] = 'kg';
                    $items[$last]['note'] = self::NOTE_WEIGHT;
                }

                continue;
            }

            // « 2 x 1,05 » sans total : le montant de fin de ligne est le prix unitaire, pas un total
            if ($quantity !== null && $price !== null && $price[1] < $quantity[2]) {
                $price = null;
            }
            // Colonnes « P.U. Qté Total » (Lidl…) : « Kiwi jaune pièce   0,89  4   3,56 A T »
            $columns = $weight === null && $quantity === null && $price !== null ? $this->columns($line, $price) : null;
            $corrected = false;
            if ($columns !== null) {
                $quantity = [$columns[0], $columns[1], $price[1]];
                $corrected = $columns[4];
                $price[0] = $columns[3];
                $label = $this->label(substr($line, 0, $columns[2]), null, null, null);
            } else {
                $label = $this->label($line, $weight, $quantity, $price);
            }
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
                    $room = $items[$last]['total_cents'] - $items[$last]['discount_cents'];
                    // Remise plus grande que l'article : chiffre parasite de l'OCR (« -60,36 », « -6,92 » pour -0,36, -0,92)
                    if ($amount > $room && $amount % 100 > 0 && $amount % 100 <= $room) {
                        $amount %= 100;
                    }
                    $items[$last]['discount_cents'] += $amount;
                } else {
                    $items[] = self::item('remise', $label, 1, 'piece', null, -$amount);
                }

                continue;
            }

            $item = self::item('produit', $label, 1, 'piece', null, $price[0] ?? null);
            $item['vat_rate'] = isset($price[2]) ? ($vatRates[$price[2]] ?? null) : null;
            $item['uncertain'] = $corrected;
            $item['note'] = $corrected ? self::NOTE_CORRECTED : null;
            $item['raw'] = $line;
            $this->applyDetails($item, $weight, $quantity, $price);

            if ($pendingQuantity !== null) {
                $this->applyDetails($item, ...$pendingQuantity);
                $pendingQuantity = null;
            }

            $items[] = $item;
        }

        // Libellés sans prix situés après le premier article chiffré : lignes que l'OCR n'a pas su lire
        $lines = [];
        $unread = [];
        $seenPriced = false;
        foreach ($items as $item) {
            if ($item['total_cents'] === null) {
                if ($seenPriced && $item['kind'] === 'produit') {
                    $unread[] = $item['raw'] ?? $item['label'];
                    $lines[] = $item + ['unread' => true];
                }

                continue;
            }
            $seenPriced = true;
            if ($item['unit_price_cents'] === null && $item['quantity'] > 0) {
                $item['unit_price_cents'] = (int) round($item['total_cents'] / $item['quantity']);
            }
            $lines[] = $item;
        }

        $lines = $this->resolveUnread($lines, $unread, $total, $expected);

        return ['lines' => $lines, 'total_cents' => $total, 'date' => $this->date($text), 'expected_lines' => $expected, 'unread' => $unread];
    }

    /** Somme payée des lignes lues (remises déduites). */
    public static function paidSum(array $lines): int
    {
        return (int) array_sum(array_map(fn ($l) => $l['total_cents'] - $l['discount_cents'], $lines));
    }

    /**
     * Une seule ligne illisible et un ticket qui annonce son nombre de lignes : son montant est l'écart avec le total.
     * Si la somme retombe exactement sur le total, les corrections de lecture sont confirmées.
     */
    private function resolveUnread(array $lines, array &$unread, ?int $total, ?int $expected): array
    {
        $read = array_values(array_filter($lines, fn ($l) => empty($l['unread'])));
        $pending = array_values(array_filter($lines, fn ($l) => ! empty($l['unread'])));
        $products = count(array_filter($read, fn ($l) => $l['kind'] === 'produit'));

        if ($total !== null && count($pending) === 1 && $expected === $products + 1) {
            $gap = $total - self::paidSum($read);
            if ($gap > 0 && $gap <= 20000) {
                $result = [];
                foreach ($lines as $line) {
                    if (! empty($line['unread'])) {
                        $label = trim(preg_replace('/\s+\S*\d[\d,.]*.*$/u', '', $line['label'])) ?: $line['label'];
                        $line = array_merge($line, [
                            'label' => $label, 'quantity' => 1.0, 'quantity_unit' => 'piece',
                            'unit_price_cents' => $gap, 'total_cents' => $gap, 'discount_cents' => 0,
                            'note' => self::NOTE_DEDUCED, 'uncertain' => true,
                        ]);
                        unset($line['unread']);
                    }
                    $result[] = $line;
                }
                $unread = [];
                $read = $result;
            }
        }

        $exact = $total !== null && abs($total - self::paidSum($read)) <= 1;

        return array_map(function ($line) use ($exact) {
            if ($exact && $line['note'] === self::NOTE_CORRECTED) {
                $line['note'] = null;
                $line['uncertain'] = false;
            }
            unset($line['raw']);

            return $line;
        }, $read);
    }

    /** Clé de rapprochement d'un libellé : majuscules sans accents, ponctuation réduite. */
    public static function normalize(string $label): string
    {
        $value = Str::upper(Str::ascii($label));
        $value = preg_replace('/[^A-Z0-9%\/,.]+/', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    public static function item(string $kind, string $label, float $quantity, string $unit, ?int $unitPrice, ?int $total): array
    {
        return [
            'kind' => $kind,
            'label' => Str::limit($label, 200, ''),
            'quantity' => $quantity,
            'quantity_unit' => $unit,
            'unit_price_cents' => $unitPrice,
            'total_cents' => $total,
            'discount_cents' => 0,
            'vat_rate' => null,
            'non_food' => false,
            'note' => null,
            'uncertain' => false,
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

    /**
     * Dernier montant de la ligne, éventuellement suivi de « € » et de codes (TVA « A », « B »… ; « T » titre-restaurant).
     * @return array{0: int, 1: int, 2: ?string}|null [centimes, position, code TVA]
     */
    private function trailingPrice(string $line): ?array
    {
        if (preg_match('/(?<![\d,.\/])('.self::PRICE.')\s*(?:€|eur|euros?)?((?:\s*\b(?:[A-D]|T\d?|\*))*)\s*$/iu', $line, $m, PREG_OFFSET_CAPTURE)) {
            $code = preg_match('/\b([A-D])\b/i', $m[2][0], $c) ? strtoupper($c[1]) : null;

            return [$this->cents($m[1][0]), $m[1][1], $code];
        }

        return null;
    }

    /**
     * Colonnes prix unitaire + quantité juste avant le total (Lidl…), y compris lues par OCR :
     * quantité « 7 », « 71 » ou « | » pour 1, prix unitaire ou total mal lu d'un chiffre.
     * @return array{0: float, 1: int, 2: int, 3: int, 4: bool}|null [quantité, prix unitaire, position de début, total retenu, lecture corrigée]
     */
    private function columns(string $line, array $price): ?array
    {
        $before = substr($line, 0, $price[1]);

        if (! preg_match('/(?<![\d,.])(\d{1,4}[,.]\d{2})\s+([\dIl]{1,3})\s*$/', $before, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $unit = $this->cents($m[1][0]);
        $token = strtr($m[2][0], ['I' => '1', 'l' => '1']);
        $count = (int) $token;
        $total = $price[0];

        if ($unit <= 0 || $total <= 0) {
            return null;
        }
        if ($count > 0 && abs($unit * $count - $total) <= 2) {
            return [(float) $count, $unit, $m[0][1], $total, false];
        }

        $n = (int) round($total / $unit);

        // Prix unitaire aberrant (« 8,99 7 0,99 ») ou un seul article : le total fait foi
        if ($n <= 1) {
            return abs($unit - $total) <= max(20, (int) round($total * 0.15)) || $n === 0
                ? [1.0, $total, $m[0][1], $total, $unit !== $total]
                : null;
        }

        // Plusieurs articles : la quantité lue confirme le prix unitaire (« 0,55 4 2,28 » → 4 × 0,55)
        if (abs($unit * $n - $total) <= max(10, (int) round($total * 0.1))) {
            return str_contains($token, (string) $n)
                ? [(float) $n, $unit, $m[0][1], $unit * $n, true]
                : [(float) $n, (int) round($total / $n), $m[0][1], $total, true];
        }

        return null;
    }

    /** Tableau de TVA du ticket : « A 5,5% », « B 20% » → taux par code. @return array<string, float> */
    private function vatTable(string $text): array
    {
        preg_match_all('/^\s*([A-D])\s+(\d{1,2}(?:[,.]\d{1,2})?)\s*%/mi', $text, $m, PREG_SET_ORDER);

        $rates = [];
        foreach ($m as $row) {
            $rates[strtoupper($row[1])] ??= (float) str_replace(',', '.', $row[2]);
        }

        return $rates;
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
        $line = str_replace(["\t", "\u{00A0}"], ' ', $line);
        // Barre verticale entre deux montants : quantité 1 mal lue (« 2,39 | 2,35 »), sinon cadre décoratif
        $line = preg_replace('/(?<=\d[,.]\d\d)\s+\|\s+(?=\d)/', ' 1 ', $line);
        $line = str_replace('|', ' ', $line);
        // Erreurs d'OCR fréquentes dans les montants : O ou @ à la place de 0, / à la place de 7
        $line = preg_replace('/(?<=\d)[Oo](?=\d)|(?<=\d[,.])[Oo]|(?<=[,.]\d)[Oo]/', '0', $line);
        $line = preg_replace('/(?<![\w,.])@(?=[,.]\d\d)/u', '0', $line);
        $line = preg_replace('/(?<=\d[,.]\d)\/(?=\d(?!\d))/', '', $line);       // « 1,7/9 » → 1,79
        $line = preg_replace('/(?<=\d[,.])\/(?=\d(?!\d))/', '7', $line);         // « 1,/9 » → 1,79
        // Codes collés au montant : « 5,99BT », « 1,14AT », « 3,49B » → « 5,99 B T »
        $line = preg_replace('/(\d[,.]\d\d)\s*([A-D])\s?T\s*$/', '$1 $2 T', $line);
        $line = preg_replace('/(\d[,.]\d\d)([A-D])\s*$/', '$1 $2', $line);
        // « EUR/Kkg »
        $line = preg_replace('/\/\s*K+\s*kg\b/i', '/kg', $line);

        return trim(preg_replace('/\s+/', ' ', $line));
    }

    private function upper(string $value): string
    {
        return Str::upper(Str::ascii($value));
    }

    /** Première date plausible du ticket (les codes du type « 522183/04/11/02 » sont écartés). */
    private function date(string $text): ?string
    {
        preg_match_all('/(?<![\d\/])(\d{2})[\/.\-](\d{2})[\/.\-](\d{4}|\d{2})(?![\d\/])/', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
            if (! checkdate((int) $m[2], (int) $m[1], $year) || $year < 2020) {
                continue;
            }

            $date = Carbon::create($year, (int) $m[2], (int) $m[1]);
            if (! $date->isFuture()) {
                return $date->toDateString();
            }
        }

        return null;
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
