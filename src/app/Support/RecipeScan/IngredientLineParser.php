<?php

namespace App\Support\RecipeScan;

/**
 * Lit la liste d'ingrédients d'une fiche : « 225 G DE PÂTES COURTES (ICI MEZZE MANICHE) », « ½ courgette »,
 * « 2 c. à soupe d'huile d'olive », « SEL ET POIVRE »…
 *
 * Chaque ligne donne : groupe, texte d'origine, nom lu, quantité, unité (codes de App\Support\Units), précision, facultatif.
 */
class IngredientLineParser
{
    private const BULLET = '/^[•·▪●■◦\-–—*]\s*/u';

    /** Puce que la reconnaissance de texte a lue comme « e » ou « ?? » (« e 400 g de spaghetti », « ??Sel »). */
    private const OCR_BULLET = '/^(?:e\s+(?=\S)|\?\?\s*)(?:e\s+(?=\S)|\?\?\s*)?/u';

    private const FRACTIONS = ['½' => 0.5, '¼' => 0.25, '¾' => 0.75, '⅓' => 1 / 3, '⅔' => 2 / 3, '⅛' => 0.125];

    /** Mots qui comptent des éléments : l'unité devient « pièce » et le mot est gardé en précision. */
    private const PIECE_WORDS = 'feuilles?|gousses?|branches?|brins?|tranches?|pots?|bo[iî]tes?|sachets?|t[eê]tes?|bottes?|bouquets?|filets?|noix|morceaux?|cubes?|tiges?|rondelles?|pav[eé]s?|escalopes?|blancs?|b[aâ]tons?|bocal|bocaux|carr[eé]s?|barquettes?|poign[eé]es?|tubes?|conserves?|boules?|pi[eè]ces?|bandes?|cuisses?|aiguillettes?|[eé]pis?|grappes?|quartiers?|zestes?|rouleaux?|plaques?|sticks?|gr[aâ]ins?';

    private const QUALIFIERS = 'bonne|belle|grosse|petite|grande|g[eé]n[eé]reuse|pleine|rase|ras[eé]e|bomb[eé]e|copieuse|demie|gros|petit|grand|beau|bon|moyen|moyenne';

    /**
     * @param  array<int, string>  $lines  lignes de la section « ingrédients » (vides comprises)
     * @return array<int, array{group: ?string, raw: string, name: string, quantity: ?float, unit: ?string, note: ?string, optional: bool}>
     */
    public static function parseSection(array $lines): array
    {
        $bulletsUsed = collect($lines)->contains(fn ($l) => preg_match(self::BULLET, trim($l)) === 1 || preg_match(self::OCR_BULLET, trim($l)) === 1);
        $logical = [];
        $group = null;

        foreach ($lines as $line) {
            $line = trim($line);
            // Reste d'une puce seule (« e », « | »…) : rien à lire
            if ($line === '' || preg_match('/^[e|©@°•·\-–—*.]$/u', $line) === 1) {
                continue;
            }

            $isBullet = preg_match(self::BULLET, $line) === 1 || preg_match(self::OCR_BULLET, $line) === 1;
            $text = trim(preg_replace(self::OCR_BULLET, '', preg_replace(self::BULLET, '', $line)));
            if ($text === '') {
                continue;
            }

            if (! $isBullet && self::isGroupHeading($text)) {
                $group = mb_substr(self::ucfirst(rtrim($text, " :\u{00A0}")), 0, 60);

                continue;
            }

            $continues = false;
            if ($logical !== [] && ! $isBullet) {
                $continues = ($bulletsUsed && ! self::startsWithQuantity($text)) || (preg_match('/^\p{Ll}/u', $text) === 1 && ! self::startsWithQuantity($text));
            }

            if ($continues) {
                $logical[array_key_last($logical)]['text'] .= ' '.$text;
            } else {
                $logical[] = ['group' => $group, 'text' => $text];
            }
        }

        $items = [];
        foreach ($logical as $entry) {
            foreach (self::parseLine($entry['text']) as $item) {
                $items[] = ['group' => $entry['group']] + $item;
            }
        }

        return $items;
    }

    /** @return array<int, array{raw: string, name: string, quantity: ?float, unit: ?string, note: ?string, optional: bool}> */
    public static function parseLine(string $text): array
    {
        // « … ⚠ message » : lecture réparée en amont (LayoutComposer), la ligne passe en rouge à la relecture
        $forced = null;
        if (preg_match('/\s*⚠\s*(.+)$/u', $text, $w) === 1) {
            $forced = trim($w[1]);
            $text = mb_substr($text, 0, mb_strlen($text) - mb_strlen($w[0]));
        }

        $raw = trim($text);
        $text = trim(preg_replace('/\s+/u', ' ', $raw));
        if ($text === '') {
            return [];
        }

        // Ligne écrite en capitales : on passe en minuscules pour que les notes restent lisibles
        $letters = preg_replace('/[^\p{L}]/u', '', $text);
        if ($letters !== '' && mb_strlen(preg_replace('/[^\p{Lu}]/u', '', $letters)) / mb_strlen($letters) > 0.7) {
            $text = mb_strtolower($text);
        }

        $notes = [];
        $tail = [];
        $optional = false;

        if (preg_match_all('/\(([^)]*)\)/u', $text, $m)) {
            foreach ($m[1] as $inside) {
                $tail[] = trim($inside);
            }
            $text = trim(preg_replace('/\s+/u', ' ', preg_replace('/\([^)]*\)/u', ' ', $text)));
        }

        if (preg_match('/\b(facultatif|facultative|optionnel|optionnelle)\b/iu', $text)) {
            $optional = true;
            $text = trim(preg_replace('/\b(facultatif|facultative|optionnel|optionnelle)\b/iu', '', $text));
        }

        if (preg_match('/\b(selon (?:le |votre )?go[uû]t|au go[uû]t|[aà] volont[eé]|[aà] votre convenance)\b/iu', $text, $m)) {
            $notes[] = mb_strtolower($m[1]);
            $text = trim(preg_replace('/\b(selon (?:le |votre )?go[uû]t|au go[uû]t|[aà] volont[eé]|[aà] votre convenance)\b/iu', '', $text), " ,;");
        }

        // Quantité
        $quantity = null;
        $unit = null;
        $rest = $text;
        $hadQuantity = false;
        $quantityText = '';
        $check = null;

        if (preg_match('/^(?<q>\d+\s+\d\s*\/\s*\d|\d+\s*\/\s*\d+|\d+(?:[.,]\d+)?\s*[½¼¾⅓⅔⅛]?|[½¼¾⅓⅔⅛])\s*(?<range>(?:à|-|–)\s*(?<q2>\d+(?:[.,]\d+)?)\s+)?/u', $text, $m)) {
            $quantity = self::number($m['q']);
            $quantityText = trim($m['q']);
            $rest = trim(mb_substr($text, mb_strlen($m[0])));
            $hadQuantity = true;

            if (! empty($m['range'])) {
                $notes[] = trim($m['q']).' à '.trim($m['q2']);
            }
        }

        if ($hadQuantity) {
            $qualifier = null;
            if (preg_match('/^('.self::QUALIFIERS.')\s+(?=(?:cs|cc|c\.|cuill|cuiller|verre|pinc|g\b|kg|ml|cl|dl|l\b|litre|gramme))/iu', $rest, $q)) {
                $qualifier = mb_strtolower($q[1]);
                $rest = trim(mb_substr($rest, mb_strlen($q[0])));
            }

            // « 20 ci de crème » : le « l » de « cl » lu comme un « i »
            $misreadUnit = null;
            if (preg_match('/^ci(?=\s+(?:de\s|d[\x27’]))/iu', $rest) === 1) {
                $rest = 'cl'.mb_substr($rest, 2);
                $misreadUnit = '« ci » lu comme « cl » (centilitres) : à vérifier avec la fiche.';
            }

            [$unit, $rest, $pieceWord] = self::extractUnit($rest);

            if ($qualifier) {
                $notes[] = $qualifier;
            }
            if ($pieceWord) {
                $notes[] = $pieceWord;
            }
            if ($unit === null) {
                $unit = 'piece';
                if (preg_match('/^('.self::QUALIFIERS.')\s+(.+)$/iu', $rest, $q)) {
                    $notes[] = mb_strtolower($q[1]);
                    $rest = $q[2];
                }
            }
        }

        if ($hadQuantity && $quantity !== null) {
            [$quantity, $check] = self::plausible($quantity, $unit, $quantityText);
        }
        $check ??= $misreadUnit ?? null;
        $check = $forced ?? $check;

        $rest = self::stripDe($rest);

        // « bouillon de volaille ou de légumes » → bouillon de volaille, ou de légumes
        if (preg_match('/^(.+?)\s+ou\s+(.+)$/iu', $rest, $m)) {
            $rest = $m[1];
            $tail[] = 'ou '.$m[2];
        }

        // « oignon, émincé » → oignon, émincé
        if ($hadQuantity && str_contains($rest, ',')) {
            [$name, $after] = array_map('trim', explode(',', $rest, 2));
            if ($name !== '' && $after !== '') {
                $rest = $name;
                $tail[] = $after;
            }
        }
        $notes = array_merge($notes, $tail);

        $rest = trim($rest, " .;:,-–");

        // « sel et poivre » : deux lignes sans quantité
        if (! $hadQuantity && preg_match('/^([^,&]+?)\s*(?:,|&|\bet\b)\s*([^,&]+)$/iu', $rest, $m)
            && str_word_count(self::ascii($m[1])) <= 3 && str_word_count(self::ascii($m[2])) <= 3) {
            return [
                self::item($raw, $m[1], null, null, $notes, $optional),
                self::item($raw, $m[2], null, null, [], $optional),
            ];
        }

        if ($rest === '') {
            return [];
        }

        return [self::item($raw, $rest, $quantity, $unit, $notes, $optional, $check)];
    }

    private static function item(string $raw, string $name, ?float $quantity, ?string $unit, array $notes, bool $optional, ?string $check = null): array
    {
        $notes = array_values(array_unique(array_filter(array_map(fn ($n) => trim((string) $n, " ,;"), $notes))));

        return [
            'raw' => $raw,
            'name' => trim(self::stripDe(trim($name))),
            'quantity' => $quantity,
            'unit' => $unit,
            'note' => $notes === [] ? null : mb_substr(implode(', ', $notes), 0, 120),
            'optional' => $optional,
        ] + ($check === null ? [] : ['check' => $check]);
    }

    /**
     * Une quantité démesurée (« 227100 g de parmesan ») vient presque toujours d'une puce lue comme des chiffres :
     * on enlève le début, et la ligne est signalée pour être vérifiée.
     *
     * @return array{0: float, 1: ?string}
     */
    private static function plausible(float $quantity, ?string $unit, string $text): array
    {
        $max = match ($unit) {
            'g', 'ml' => 5000,
            'kg', 'l' => 20,
            'cl' => 500,
            'dl' => 50,
            'cas', 'cac' => 50,
            'piece' => 100,
            'pincee', 'verre' => 20,
            default => null,
        };

        if ($max === null || $quantity <= $max) {
            return [$quantity, null];
        }

        $digits = preg_replace('/\D/', '', $text);
        if (in_array($unit, ['g', 'ml', 'piece'], true) && strlen($digits) > 2 && $quantity === (float) $digits) {
            for ($i = 1; $i < strlen($digits); $i++) {
                $cut = substr($digits, $i);
                if ($cut[0] !== '0' && (float) $cut <= $max) {
                    return [(float) $cut, "Quantité lue « {$digits} » : le début est sans doute une puce mal lue, corrigée en {$cut}. À vérifier avec la fiche."];
                }
            }
        }

        return [$quantity, "Quantité inhabituelle (« {$text} »). À vérifier avec la fiche."];
    }

    /** @return array{0: ?string, 1: string, 2: ?string} code d'unité, reste de la ligne, mot de comptage éventuel */
    private static function extractUnit(string $rest): array
    {
        $units = [
            'kg' => '(?:kg|kilos?|kilogrammes?)',
            'g' => '(?:g|gr|grammes?)',
            'ml' => '(?:ml|millilitres?)',
            'cl' => '(?:cl|centilitres?)',
            'dl' => '(?:dl|decilitres?|décilitres?)',
            'l' => '(?:l|litres?)',
            'cas' => '(?:cs|c\.?\s*[àa]\.?\s*soupe|c\.?\s*[àa]\.?\s*s\.?|c\.?s\.?|[cç][àa]s|cuill?[eè]re?(?:e|ée)?s?\s*[àa]\s*soupe|cuill?[eè]re?(?:e|ée)?s?\s*[àa]\s*s\.?)',
            'cac' => '(?:cc|c\.?\s*[àa]\.?\s*caf[eé]|c\.?\s*[àa]\.?\s*c\.?|[cç][àa]c|cuill?[eè]re?(?:e|ée)?s?\s*[àa]\s*caf[eé]|cuill?[eè]re?(?:e|ée)?s?\s*[àa]\s*c\.?)',
            'pincee' => '(?:pinc[eé]es?)',
            'verre' => '(?:verres?)',
        ];

        foreach ($units as $code => $pattern) {
            if (preg_match('/^'.$pattern.'(?![\p{L}\x27])\.?\s*/iu', $rest, $m)) {
                return [$code, trim(mb_substr($rest, mb_strlen($m[0]))), null];
            }
        }

        if (preg_match('/^('.self::PIECE_WORDS.')(?![\p{L}])\s*/iu', $rest, $m)) {
            return ['piece', trim(mb_substr($rest, mb_strlen($m[0]))), mb_strtolower($m[1])];
        }

        return [null, $rest, null];
    }

    private static function stripDe(string $text): string
    {
        return trim(preg_replace('/^(?:de la |de l\'|de |d\'|du |des )/iu', '', trim($text)));
    }

    private static function number(string $text): float
    {
        $text = trim($text);
        foreach (self::FRACTIONS as $char => $value) {
            if (str_contains($text, $char)) {
                $whole = trim(str_replace($char, '', $text));

                return ($whole === '' ? 0 : (float) str_replace(',', '.', $whole)) + $value;
            }
        }

        if (preg_match('/^(\d+)\s+(\d)\s*\/\s*(\d)$/', $text, $m)) {
            return (int) $m[1] + (int) $m[2] / max(1, (int) $m[3]);
        }
        if (preg_match('/^(\d+)\s*\/\s*(\d+)$/', $text, $m)) {
            return (int) $m[1] / max(1, (int) $m[2]);
        }

        return (float) str_replace(',', '.', $text);
    }

    public static function startsWithQuantity(string $text): bool
    {
        return preg_match('/^(?:\d|[½¼¾⅓⅔⅛])/u', trim($text)) === 1;
    }

    private static function isGroupHeading(string $text): bool
    {
        if ($text === '' || self::startsWithQuantity($text)) {
            return false;
        }

        return str_ends_with($text, ':') || preg_match('/^pour\s+(?:la|le|les|l\'|un|une|\d)/iu', $text) === 1;
    }

    private static function ascii(string $text): string
    {
        return \Illuminate\Support\Str::ascii($text);
    }

    private static function ucfirst(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_strtolower(mb_substr($text, 1));
    }
}
