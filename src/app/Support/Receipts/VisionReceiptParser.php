<?php

namespace App\Support\Receipts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * v0.19.0 : ticket recopié par le modèle de vision (JSON imposé par VisionClient::receiptSchema()) → les lignes que
 * ReceiptProcessor connaît (même forme que ReceiptParser::parse). L'IA recopie, le code interprète et contrôle ;
 * règles validées par l'essai du 7 octobre 2026 sur les 6 tickets de Louis (Leclerc Drive, Lidl Plus) :
 *
 *   1. lignes qui ne sont pas des articles écartées (« Nombre de lignes: 4 » recopié avec le total, « A payer »…) ;
 *   2. ligne de pesée recopiée comme un article (« 1,018 kg x 1,49 EUR/kg ») rattachée à l'article du dessus ;
 *   3. pesée (« 1,420 kg x 1,99 EUR/kg ») : unité kg, poids et prix au kilo lus dans la ligne de détail ; prix au kilo
 *      sans poids recopié : poids = prix ÷ prix au kilo (un calcul, pas une invention ; signalé) ;
 *   4. sinon à la pièce, même si le libellé contient un poids (« Carottes sachet 1 kg ») ;
 *   5. remise = montant négatif seulement, rattachée à l'article du dessus (comme ReceiptParser) ;
 *   6. contrôle quantité × prix unitaire = prix ; chiffre douteux signalé : la ligne est « à vérifier » ;
 *   7. un seul prix illisible : déduit du total (signalé, à vérifier) ;
 *   8. date recopiée telle qu'imprimée (« 24.09.26 ») puis convertie ici.
 *
 * v0.21.0, n'importe quelle enseigne (premier ticket Carrefour, scanné sur deux pages A4 qui se chevauchent) :
 *   9. titres de rayon (« << BOISSONS >> ») écartés ;
 *  10. lettre de TVA en tête de libellé (« D PET 1.5L ORANGINA ») retirée quand la plupart des articles en ont une ;
 *  11. le ticket s'arrête au total : ce que l'IA a recopié après (coupons, cagnotte, détail des promotions, avantages
 *      fidélité) est écarté quand le début des lignes retombe exactement sur le total imprimé ;
 *  12. lignes recopiées deux fois de suite (fin d'une page répétée au début de la suivante) : le second exemplaire est
 *      écarté, seulement si le compte retombe alors au centime sur le total.
 */
class VisionReceiptParser
{
    public const NOTE_WEIGHT_DEDUCED = 'poids non recopié : déduit du prix ÷ prix au kilo';

    public const NOTE_DOUBT = 'chiffre difficile à lire : vérifie la ligne';

    public const NOTE_MISMATCH = 'quantité × prix unitaire ≠ prix : vérifie la ligne';

    /** Notes d'une ligne dont la lecture est à vérifier (fenêtre « Vérifier la lecture »). */
    public const READING_NOTES = [self::NOTE_DOUBT, self::NOTE_MISMATCH, ReceiptParser::NOTE_DEDUCED, ReceiptParser::NOTE_CORRECTED, ReceiptParser::NOTE_WEIGHT];

    private const WEIGHING = '(\d+(?:[.,]\d+)?)\s*(kg|g)\s*[x×*]\s*(\d+(?:[.,]\d+)?)';

    /** Ligne de détail « 3 x 0,89 » : nombre d'articles × prix unitaire. */
    private const COUNT = '/^\s*(\d{1,3})\s*[x×*]\s*(\d+[.,]\d{2})\b/u';

    /** Débuts de libellés qui ne sont pas des articles. */
    private const NOT_ARTICLE = '/^\s*(?:nombre\s+d[e\'’]?\s*(?:lignes?|articles?)|nb\.?\s+articles?|sous[- ]?total|s\/total|total\b|(?:net\s+)?[àa]\s+payer|montant\b|carte\s+bancaire|cb\b|esp[eè]ces|rendu\b|monnaie\b|t\.?v\.?a\b|avec\s+lidl\s+plus|vous\s+avez\s+[ée]conomis)/iu';

    /** Titre de rayon : « << BOISSONS >> », « ** EPICERIE ** », « -- FRAIS -- ». */
    private const SECTION = '/^\s*(?:<<|«|\*{2,}|={2,}|-{2,}).*(?:>>|»|\*{2,}|={2,}|-{2,})\s*$/u';

    /** Lettre de TVA en tête de libellé (Carrefour : « D PET 1.5L ORANGINA », « F FRIGOBLOC »). */
    private const VAT_PREFIX = '/^[A-Z]\s+(?=\S{2,})/u';

    /** Écart toléré entre quantité × prix unitaire et le prix (arrondis du magasin). */
    private const TOLERANCE = 0.02;

    /**
     * @return array{lines: array<int, array<string, mixed>>, total_cents: ?int, date: ?string, expected_lines: null, unread: array<int, string>, ignored: array<int, string>, store_text: string}
     */
    public function parse(array $answer): array
    {
        // 1 et 2. Lignes retenues, pesées isolées rattachées
        $rows = [];
        foreach ($answer['lignes'] ?? [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $label = trim(preg_replace('/\s+/u', ' ', (string) ($line['libelle'] ?? '')));
            if ($label === '' || preg_match(self::NOT_ARTICLE, $label) === 1 || preg_match(self::SECTION, $label) === 1) {
                continue;
            }
            $last = array_key_last($rows);
            if ($last !== null && preg_match('/^\s*'.self::WEIGHING.'/iu', $label) === 1 && (self::amount($rows[$last]['prix'] ?? null) ?? 0) >= 0) {
                $rows[$last]['detail'] = ($rows[$last]['detail'] ?? null) ?: $label;
                if (self::amount($rows[$last]['prix'] ?? null) === null) {
                    $rows[$last]['prix'] = $line['prix'] ?? null;
                }

                continue;
            }
            $rows[] = ['libelle' => $label] + $line;
        }

        // 3 à 6. Interprétation ligne par ligne (montants en euros)
        $read = [];
        foreach ($rows as $line) {
            $read[] = $this->interpret($line);
        }

        $total = self::amount($answer['total'] ?? null);
        $total = $total !== null && $total > 0 ? $total : null;

        // 11 et 12. Ce qui suit le total, lignes répétées d'une page à l'autre
        $ignored = [];
        if ($total !== null) {
            [$read, $ignored] = self::fitTotal($read, (int) round($total * 100));
        }

        // 10. Lettre de TVA en tête de libellé, quand la plupart des lignes gardées en ont une
        $prefixed = count(array_filter($read, fn ($r) => preg_match(self::VAT_PREFIX, $r['label']) === 1));
        if (count($read) >= 3 && $prefixed >= 0.6 * count($read)) {
            foreach ($read as $i => $r) {
                $read[$i]['label'] = preg_replace(self::VAT_PREFIX, '', $r['label']);
            }
        }

        // 7. Un seul prix illisible : déduit du total
        $missing = array_keys(array_filter($read, fn ($r) => $r['price'] === null));
        $unread = [];
        if (count($missing) === 1 && $total !== null) {
            $known = array_sum(array_map(fn ($r) => $r['price'] ?? 0, $read));
            $gap = round($total - $known, 2);
            if ($gap > 0 && $gap <= 200) {
                $i = $missing[0];
                $read[$i]['price'] = $gap;
                $read[$i]['quantity'] = 1.0;
                $read[$i]['unit'] = 'piece';
                $read[$i]['unit_price'] = $gap;
                $read[$i]['note'] = ReceiptParser::NOTE_DEDUCED;
                $read[$i]['uncertain'] = true;
                $missing = [];
            }
        }
        foreach ($missing as $i) {
            $unread[] = $read[$i]['label'];
        }

        // Lignes au format de ReceiptProcessor ; remises rattachées à l'article du dessus
        $items = [];
        foreach ($read as $r) {
            if ($r['price'] === null) {
                continue;
            }
            $cents = (int) round($r['price'] * 100);
            if ($cents < 0) {
                $last = array_key_last($items);
                if ($last !== null && $items[$last]['kind'] === 'produit') {
                    $items[$last]['discount_cents'] += -$cents;
                } else {
                    $items[] = ReceiptParser::item('remise', $r['label'], 1, 'piece', null, $cents);
                }

                continue;
            }
            $item = ReceiptParser::item('produit', $r['label'], $r['quantity'], $r['unit'],
                $r['unit_price'] !== null ? (int) round($r['unit_price'] * 100) : ($r['quantity'] > 0 ? (int) round($cents / $r['quantity']) : null), $cents);
            $item['note'] = $r['note'];
            $item['uncertain'] = $r['uncertain'];
            $items[] = $item;
        }

        return [
            'lines' => $items,
            'total_cents' => $total !== null ? (int) round($total * 100) : null,
            'date' => self::date($answer['date_imprimee'] ?? ($answer['date'] ?? null)),
            'expected_lines' => null,
            'unread' => $unread,
            'ignored' => $ignored,
            'store_text' => Str::limit(trim((string) ($answer['magasin'] ?? '')), 120, ''),
        ];
    }

    /** @return array{label: string, quantity: float, unit: string, unit_price: ?float, price: ?float, note: ?string, uncertain: bool} */
    private function interpret(array $line): array
    {
        $label = $line['libelle'];
        $price = self::amount($line['prix'] ?? null);
        $quantity = is_numeric($line['quantite'] ?? null) && (float) $line['quantite'] > 0 ? (float) $line['quantite'] : 1.0;
        $unitPriceText = trim((string) ($line['prix_unitaire'] ?? ''));
        $perKg = preg_match('/\/\s*k\s?g/iu', $unitPriceText) === 1;
        $unitPrice = $unitPriceText !== '' ? self::amount(preg_replace('/\s*(?:EUR|€)?\s*\/\s*k\s?g\s*$/iu', '', $unitPriceText)) : null;
        $discount = $price !== null && $price < 0;
        $unit = 'piece';
        $note = null;
        $uncertain = false;

        // Poids recopié en grammes (« 420 » g) : ramené en kilos
        if (Str::lower((string) ($line['unite'] ?? '')) === 'g' && $quantity >= 1) {
            $quantity /= 1000;
            $line['unite'] = 'kg';
        }

        // Pesée lue dans la ligne de détail ; dans le libellé seulement s'il n'est que la pesée (« 1,018 kg x 1,49 »),
        // jamais au milieu d'un libellé (« Escalopes 600 g x 2 » reste un article à la pièce)
        $weighing = null;
        $detail = (string) ($line['detail'] ?? '');
        if (preg_match('/'.self::WEIGHING.'/iu', $detail, $m) === 1 || ($detail === '' && preg_match('/^\s*'.self::WEIGHING.'/iu', $label, $m) === 1)) {
            $weighing = $m;
        } elseif (! $discount && $quantity == 1 && preg_match(self::COUNT, $detail, $m) === 1 && (int) $m[1] > 1) {
            // « 3 x 0,89 » : nombre d'articles et prix unitaire lus dans la ligne de détail
            $quantity = (float) $m[1];
            $unitPrice = self::number($m[2]);
        }

        if ($weighing && ! $discount) {
            // Pesée imprimée : poids et prix au kilo
            $weight = self::number($weighing[1]) / (Str::lower($weighing[2]) === 'g' ? 1000 : 1);
            if ($weight > 0) {
                [$quantity, $unitPrice, $unit] = [$weight, self::number($weighing[3]), 'kg'];
            }
        } elseif ($unitPrice && $price !== null && $price > 0 && ! $discount
            && ($perKg || in_array(Str::lower((string) ($line['unite'] ?? '')), ['kg', 'g'], true)
                || ($quantity == 1 && $price < $unitPrice && str_contains(Str::lower(Str::ascii($label)), 'vrac')))) {
            // Prix au kilo sans ligne de pesée lisible
            if (abs($quantity * $unitPrice - $price) <= self::TOLERANCE && floor($quantity) == $quantity) {
                // « 1 × 2,39 = 2,39 » : article à la pièce (« Courgette 1 kg »), le « /kg » était de trop
            } elseif ($quantity == 1) {
                $quantity = round($price / $unitPrice, 3);
                $unit = 'kg';
                $note = self::NOTE_WEIGHT_DEDUCED;
            } else {
                $unit = 'kg';
            }
        } elseif ($unitPrice && $price !== null && $price > 0 && ! $discount && floor($quantity) != $quantity
            && abs($quantity * $unitPrice - $price) <= self::TOLERANCE) {
            // Quantité décimale qui retombe sur le prix (1,42 × 1,99 = 2,83) : c'est une pesée
            $unit = 'kg';
        }

        if ($price !== null && $unitPrice !== null && $unitPrice > 0 && ! $discount && abs($quantity * $unitPrice - $price) > self::TOLERANCE) {
            $note = self::NOTE_MISMATCH;
            $uncertain = true;
        }
        if (! empty($line['doute']) && ! $uncertain) {
            $note = self::NOTE_DOUBT;
            $uncertain = true;
        }

        return [
            'label' => Str::limit($label, 200, ''),
            'quantity' => $quantity,
            'unit' => $unit,
            'unit_price' => $discount ? null : $unitPrice,
            'price' => $price,
            'note' => $note,
            'uncertain' => $uncertain,
        ];
    }

    /**
     * Lignes qui retombent au centime sur le total imprimé : telles quelles si c'est déjà le cas ; sinon sans ce qui suit
     * le total (11), au besoin après avoir retiré une série de lignes recopiée deux fois de suite (12). Rien n'est
     * retiré si aucune de ces corrections ne retombe exactement sur le total, ni si un prix est illisible.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>} lignes gardées, libellés écartés
     */
    private static function fitTotal(array $read, int $total): array
    {
        if ($read === [] || in_array(null, array_column($read, 'price'), true)) {
            return [$read, []];
        }

        $fit = self::cutAtTotal($read, $total);
        if ($fit !== null) {
            return $fit;
        }

        // Série répétée juste après elle-même (2 lignes au moins : deux articles identiques à la suite sont courants)
        $key = fn (array $r) => mb_strtolower($r['label']).'|'.(int) round($r['price'] * 100);
        $keys = array_map($key, $read);
        $n = count($keys);
        for ($length = intdiv($n, 2); $length >= 2; $length--) {
            for ($i = 0; $i + 2 * $length <= $n; $i++) {
                if (array_slice($keys, $i, $length) !== array_slice($keys, $i + $length, $length)) {
                    continue;
                }
                $removed = array_slice($read, $i + $length, $length);
                $rest = array_merge(array_slice($read, 0, $i + $length), array_slice($read, $i + 2 * $length));
                $fit = self::cutAtTotal($rest, $total);
                if ($fit !== null) {
                    return [$fit[0], array_merge(array_column($removed, 'label'), $fit[1])];
                }
            }
        }

        return [$read, []];
    }

    /** @return ?array{0: array<int, array<string, mixed>>, 1: array<int, string>} */
    private static function cutAtTotal(array $read, int $total): ?array
    {
        $sums = [];
        $sum = 0;
        foreach ($read as $i => $r) {
            $sum += (int) round($r['price'] * 100);
            $sums[$i] = $sum;
        }
        if ($sum === $total) {
            return [$read, []];
        }
        for ($k = count($read) - 2; $k >= 0; $k--) {
            if ($sums[$k] === $total) {
                return [array_slice($read, 0, $k + 1), array_column(array_slice($read, $k + 1), 'label')];
            }
        }

        return null;
    }

    /** « 2,83 », « -0,71 », « 12,19 € », « 4.49 », « 1,79 EUR » → 2.83, -0.71… ; null si illisible. */
    public static function amount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $text = str_replace(['−', '–', "\u{00A0}", ' ', '€'], ['-', '-', '', '', ''], (string) $value);
        $text = preg_replace('/EUR$/i', '', $text);
        if (preg_match('/^(-?)(\d{1,6})(?:[.,](\d{1,2}))?(-?)$/', $text, $m) !== 1) {
            return null;
        }
        $amount = (float) ($m[2].'.'.str_pad($m[3] ?? '0', 2, '0'));

        return ($m[1] === '-' || $m[4] === '-') ? -$amount : $amount;
    }

    private static function number(string $text): float
    {
        return (float) str_replace(',', '.', $text);
    }

    /** « 24.09.26 », « 02/10/2025 », « 2026-09-28 », « le 24/09/26 à 14:34 » → AAAA-MM-JJ ; null si illisible ou futur. */
    public static function date(mixed $text): ?string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }
        if (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $text, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2,4})/', $text, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $year += $year < 100 ? 2000 : 0;
        } else {
            return null;
        }
        if (! checkdate($month, $day, $year) || $year < 2020) {
            return null;
        }
        $date = Carbon::create($year, $month, $day, 0, 0, 0, 'Europe/Paris');

        return $date->gt(now('Europe/Paris')->addDay()) ? null : $date->toDateString();
    }
}
