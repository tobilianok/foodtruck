<?php

namespace App\Support\Receipts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Bon de commande Leclerc Drive (PDF reçu par e-mail, texte propre) :
 *
 *   VIANDES POISSONS (2 produits)                                      rubrique
 *   Hauts de cuisse Eco+ - Poulet blanc - 1kg 1 4.49 4.49              désignation, quantité, prix unitaire, total
 *   Origine : ESPAGNE • Catégorie : CAT-1-                             détail ignoré
 *   Total de la commande : 91.35 / Mes économies : - 1.58               total payé = 89.77 (hors avoirs)
 *   DÉTAIL DE MES ÉCONOMIES : LOT … 1.58 + noms des produits concernés remise répartie sur ces produits
 *   Anti-Gaspi : Cuisses de poulet Maître Coq 1.25                     remise déjà déduite du prix (promo)
 */
class LeclercDriveParser
{
    /** Rubriques non alimentaires (ignorées d'office, comme la TVA à 20 % sur les tickets). */
    private const NON_FOOD = ['HYGIENE', 'BEAUTE', 'ENTRETIEN', 'NETTOYAGE', 'MAISON', 'ANIMA', 'PAPETERIE', 'BAZAR', 'TEXTILE',
        'JARDIN', 'BRICOLAGE', 'AUTO', 'PARAPHARMACIE', 'ELECTRO', 'CULTURE', 'JOUET', 'CAVE', 'VINS', 'BIERE', 'ALCOOL', 'SPIRITUEUX', 'APERITIF'];

    public static function accepts(string $text): bool
    {
        $upper = Str::upper(Str::ascii($text));

        return str_contains($upper, 'BON DE COMMANDE')
            && (str_contains($upper, 'LECLERC') || str_contains($upper, 'DRIVE'))
            && (bool) preg_match('/DESIGNATION\s+QUANTITE\s+PRIX UNITAIRE/', $upper);
    }

    /** @return array{lines: array, total_cents: ?int, date: ?string, expected_lines: ?int, unread: array<int, string>} */
    public function parse(string $text): array
    {
        $items = [];
        $unread = [];
        $section = null;
        $sectionNonFood = false;
        $sectionPromo = false;
        $orderTotal = null;
        $savings = 0;
        $mode = 'items';
        $group = null;
        $groups = [];
        $antiGaspi = [];

        foreach (preg_split('/\R/u', $text) as $raw) {
            $line = trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $raw)));
            $upper = Str::upper(Str::ascii($line));

            if ($line === '') {
                if ($mode === 'savings') {
                    $group = null;
                }

                continue;
            }

            if (preg_match('/^TOTAL DE LA COMMANDE\s*:?\s*(\d{1,5}[.,]\d{2})/', $upper, $m)) {
                $orderTotal = $this->cents($m[1]);
                $mode = 'footer';

                continue;
            }
            if (preg_match('/^MES ECONOMIES.*?-?\s*(\d{1,4}[.,]\d{2})\s*(?:€|EUR)?\s*$/', $upper, $m)) {
                $savings = $this->cents($m[1]);

                continue;
            }
            if (str_starts_with($upper, 'DETAIL DE MES ECONOMIES') || preg_match('/^\(\d\)\s*DETAIL DE MES ECONOMIES/', $upper)) {
                $mode = 'savings';
                $group = null;

                continue;
            }
            if (preg_match('/^ANTI-?GASPI\s*:\s*$/', $upper)) {
                $mode = 'antigaspi';

                continue;
            }

            if ($mode === 'items') {
                if (preg_match('/^(.+?)\s*\((\d+) produits?\)/i', $upper, $m)) {
                    $section = trim($m[1]);
                    $sectionNonFood = $this->isNonFood($section);
                    $sectionPromo = str_contains($section, 'ANTI-GASPI') || str_contains($section, 'ANTI GASPI');

                    continue;
                }

                if (preg_match('/^(.+?)\s+(\d{1,3})\s+(\d{1,4}[.,]\d{2})\s+(\d{1,5}[.,]\d{2})\s*€?$/u', $line, $m)) {
                    $quantity = (int) $m[2];
                    $unit = $this->cents($m[3]);
                    $total = $this->cents($m[4]);

                    if ($quantity > 0 && abs($unit * $quantity - $total) <= 2) {
                        $item = ReceiptParser::item('produit', trim($m[1], " -"), (float) $quantity, 'piece', $unit, $total);
                        $item['non_food'] = $sectionNonFood;
                        $item['section'] = $section ? Str::limit(Str::title(Str::lower($section)), 60, '') : null;
                        $item['promo'] = $sectionPromo;
                        $items[] = $item;

                        continue;
                    }
                }

                if ($items !== [] && preg_match('/\d[.,]\d\d\s*€?$/u', $line) && ! preg_match('/^(DESIGNATION|TOTAL|TEL)/', $upper)) {
                    $unread[] = $line;
                }

                continue;
            }

            if ($mode === 'savings') {
                if (str_starts_with($upper, 'VOUS AVEZ') || str_starts_with($upper, 'GRACE') || str_starts_with($upper, 'BONS DE')) {
                    $group = null;

                    continue;
                }
                if (preg_match('/^(.+?)\s+(\d{1,4}[.,]\d{2})\s*€?$/u', $line, $m)) {
                    $groups[] = ['amount' => $this->cents($m[2]), 'names' => []];
                    $group = array_key_last($groups);

                    continue;
                }
                if ($group !== null && preg_match('/^(.+?)\s*-\s*$/u', $line, $m)) {
                    $groups[$group]['names'][] = $m[1];
                }

                continue;
            }

            if ($mode === 'antigaspi') {
                if (str_starts_with($line, '*')) {
                    $mode = 'footer';

                    continue;
                }
                if (preg_match('/^(.+?)\s+(\d{1,4}[.,]\d{2})\s*€?$/u', $line, $m)) {
                    $antiGaspi[] = ['name' => $m[1], 'amount' => $this->cents($m[2])];
                }
            }
        }

        // Remises de lot réparties sur les produits nommés ; sinon remise globale
        foreach ($groups as $g) {
            $targets = [];
            foreach ($g['names'] as $name) {
                $index = $this->find($items, $name);
                if ($index !== null && ! in_array($index, $targets, true)) {
                    $targets[] = $index;
                }
            }

            if ($targets === []) {
                $items[] = ReceiptParser::item('remise', 'Économies', 1, 'piece', null, -$g['amount']);

                continue;
            }

            $share = intdiv($g['amount'], count($targets));
            $rest = $g['amount'] - $share * count($targets);
            foreach ($targets as $i => $index) {
                $items[$index]['discount_cents'] += $share + ($i === 0 ? $rest : 0);
            }
        }

        // Anti-gaspi : le prix affiché est déjà remisé → prix d'origine reconstitué, remise marquée (promo)
        foreach ($antiGaspi as $row) {
            $index = $this->find($items, $row['name']);
            if ($index !== null) {
                $items[$index]['total_cents'] += $row['amount'];
                $items[$index]['discount_cents'] += $row['amount'];
                $items[$index]['unit_price_cents'] = (int) round($items[$index]['total_cents'] / max(1, $items[$index]['quantity']));
            }
        }

        foreach ($items as &$item) {
            // Rubrique anti-gaspi sans détail : prix de destockage, pas un prix de référence
            if (($item['promo'] ?? false) && $item['discount_cents'] === 0) {
                $item['note'] = 'anti-gaspi : prix réduit, non enregistré comme prix habituel';
                $item['uncertain'] = true;
            }
            unset($item['promo']);
        }
        unset($item);

        return [
            'lines' => $items,
            'total_cents' => $orderTotal !== null ? $orderTotal - $savings : null,
            'date' => $this->date($text),
            'expected_lines' => null,
            'unread' => $unread,
        ];
    }

    private function isNonFood(string $section): bool
    {
        foreach (self::NON_FOOD as $word) {
            if (str_contains($section, $word)) {
                return true;
            }
        }

        return false;
    }

    /** Produit dont le libellé contient tous les mots du nom donné dans le détail des économies. */
    private function find(array $items, string $name): ?int
    {
        $words = $this->words($name);
        if ($words === []) {
            return null;
        }

        foreach ($items as $i => $item) {
            if ($item['kind'] === 'produit' && array_diff($words, $this->words($item['label'])) === []) {
                return $i;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function words(string $value): array
    {
        return array_values(array_filter(explode(' ', Str::slug($value, ' ')), fn ($w) => strlen($w) >= 2));
    }

    /** Date de la commande (« commande n°26108855 du 28/09/2026 »), à défaut date de retrait. */
    private function date(string $text): ?string
    {
        if (preg_match('/commande\s+n\S*\s*\d+\s+du\s+(\d{2})\/(\d{2})\/(\d{4})/iu', $text, $m)
            || preg_match('/retrait\s+le\s+(\d{2})\/(\d{2})\/(\d{2,4})/iu', $text, $m)) {
            $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
            if (checkdate((int) $m[2], (int) $m[1], $year)) {
                $date = Carbon::create($year, (int) $m[2], (int) $m[1]);

                return $date->isFuture() ? null : $date->toDateString();
            }
        }

        return null;
    }

    private function cents(string $value): int
    {
        return (int) round((float) str_replace(',', '.', $value) * 100);
    }
}
