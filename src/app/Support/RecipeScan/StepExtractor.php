<?php

namespace App\Support\RecipeScan;

/**
 * Isole les étapes d'une recette dans le texte de la section « préparation ».
 *
 * Mises en page reconnues, dans l'ordre :
 *  1. numéro seul sur sa ligne (« 1 » puis le texte) ;
 *  2. numéro suivi d'une ponctuation ou « Étape 1 » (« 1. », « 2) », « Étape 3 : ») ;
 *  3. numéro au milieu du bloc de l'étape (impression d'un site : le chiffre est centré en hauteur) ;
 *  4. puces ;
 *  5. paragraphes séparés par une ligne vide.
 *
 * Un encadré « conseil » imprimé dans une colonne à côté des étapes est séparé du texte des étapes.
 */
class StepExtractor
{
    /** @return array{steps: array<int, string>, tip: ?string, issues: array<int, string>, layout: string} */
    public static function extract(array $lines): array
    {
        $issues = [];
        [$flow, $tipLines] = self::splitQuote($lines);
        $flow = self::trimBlanks($flow);

        foreach (['alone', 'prefixed', 'middle', 'bullets', 'paragraphs'] as $layout) {
            $result = match ($layout) {
                'alone' => self::byAloneNumbers($flow),
                'prefixed' => self::byPrefixedNumbers($flow),
                'middle' => self::byMiddleNumbers($flow),
                'bullets' => self::byBullets($flow),
                'paragraphs' => self::byParagraphs($flow),
            };

            if ($result !== null && $result['steps'] !== []) {
                $issues = array_merge($issues, $result['issues']);
                $steps = array_map(fn (array $parts) => self::join($parts), $result['steps']);

                return [
                    'steps' => array_values(array_filter($steps, fn ($s) => $s !== '')),
                    'tip' => $tipLines === [] ? null : self::tip($tipLines),
                    'issues' => $issues,
                    'layout' => $layout,
                ];
            }
        }

        return ['steps' => [], 'tip' => $tipLines === [] ? null : self::tip($tipLines), 'issues' => ['Aucune étape reconnue dans la fiche.'], 'layout' => 'none'];
    }

    /**
     * Sépare l'encadré « « … » » des lignes d'étapes qui le traversent (deux colonnes imprimées à la suite).
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private static function splitQuote(array $lines): array
    {
        $lines = array_map('trim', $lines);
        $start = null;
        $end = null;

        foreach ($lines as $i => $line) {
            if ($start === null && preg_match('/^[«“"]/u', $line)) {
                $start = $i;
            }
            if ($start !== null && preg_match('/[»”"]$/u', $line)) {
                $end = $i;

                break;
            }
        }

        if ($start === null || $end === null) {
            return [$lines, []];
        }

        $flow = array_slice($lines, 0, $start);
        $tip = [];

        for ($i = $start; $i <= $end; $i++) {
            $line = $lines[$i];
            $next = self::nextNonBlank($lines, $i, $end);
            $isMarker = preg_match('/^\d{1,2}\s+\S/u', $line) === 1 && $i !== $start;
            $isStepEnd = $i !== $start && $i !== $end && $line !== '' && preg_match('/[.!?]$/u', $line) === 1
                && $next !== null && preg_match('/^\p{Ll}/u', $next) === 1;

            if ($isMarker || $isStepEnd) {
                $flow[] = $line;
            } elseif ($line !== '') {
                $tip[] = $line;
            }
        }

        return [array_merge($flow, array_slice($lines, $end + 1)), $tip];
    }

    private static function nextNonBlank(array $lines, int $from, int $to): ?string
    {
        for ($i = $from + 1; $i <= $to; $i++) {
            if ($lines[$i] !== '') {
                return $lines[$i];
            }
        }

        return null;
    }

    private static function tip(array $lines): string
    {
        $text = self::join($lines);

        return trim(preg_replace('/^[«“"]\s*|\s*[»”"]$/u', '', $text));
    }

    private static function trimBlanks(array $lines): array
    {
        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }
        while ($lines !== [] && trim($lines[array_key_last($lines)]) === '') {
            array_pop($lines);
        }

        return array_values($lines);
    }

    /** Recolle des lignes coupées par la mise en page (« mélangez- » + « les » → « mélangez-les »). */
    private static function join(array $parts): string
    {
        $text = '';
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if ($text === '') {
                $text = $part;
            } elseif (preg_match('/\p{L}-$/u', $text) && preg_match('/^\p{Ll}/u', $part)) {
                $text .= $part;
            } else {
                $text .= ' '.$part;
            }
        }

        return trim($text);
    }

    // ---------------------------------------------------------------- 1. numéro seul

    private static function byAloneNumbers(array $flow): ?array
    {
        $markers = self::sequence($flow, fn (string $line, int $n) => $line === (string) $n ? '' : null);
        if (count($markers) < 2) {
            return null;
        }

        $steps = [];
        $issues = [];
        foreach ($markers as $k => $index) {
            $end = $markers[$k + 1] ?? count($flow);
            $parts = [];
            $seenText = false;

            for ($i = $index + 1; $i < $end; $i++) {
                if ($flow[$i] === '') {
                    if ($seenText) {
                        break;
                    }

                    continue;
                }
                $seenText = true;
                $parts[] = $flow[$i];
            }
            $steps[] = $parts;
        }

        return ['steps' => $steps, 'issues' => $issues];
    }

    // ---------------------------------------------------------------- 2. « 1. », « Étape 1 »

    private static function byPrefixedNumbers(array $flow): ?array
    {
        $markers = self::sequence($flow, function (string $line, int $n) {
            if (preg_match('/^(?:[ée]tape|step)\s*'.$n.'\s*[:.\-–)]?\s*(.*)$/iu', $line, $m)) {
                return $m[1];
            }

            return preg_match('/^'.$n.'\s*(?:[.)]|[-–:])\s+(.*)$/u', $line, $m) ? $m[1] : null;
        });

        if (count($markers) < 2) {
            return null;
        }

        return self::blocksFromMarkers($flow, $markers, fn (string $line, int $n) => self::strip($line, $n));
    }

    private static function strip(string $line, int $n): string
    {
        $line = preg_replace('/^(?:[ée]tape|step)\s*'.$n.'\s*[:.\-–)]?\s*/iu', '', $line, 1, $count);
        if ($count) {
            return $line;
        }

        return preg_replace('/^'.$n.'\s*(?:[.)]|[-–:])?\s*/u', '', $line, 1);
    }

    /** Étape = ligne du numéro + lignes suivantes, jusqu'au numéro suivant (ou à la première ligne vide pour la dernière). */
    private static function blocksFromMarkers(array $flow, array $markers, \Closure $strip): array
    {
        $steps = [];
        $issues = [];

        foreach ($markers as $k => $index) {
            $n = $k + 1;
            $isLast = ! isset($markers[$k + 1]);
            $end = $markers[$k + 1] ?? count($flow);
            $parts = [$strip($flow[$index], $n)];
            $blankSeen = false;

            for ($i = $index + 1; $i < $end; $i++) {
                if ($flow[$i] === '') {
                    $blankSeen = true;
                    if ($isLast) {
                        break;
                    }

                    continue;
                }
                $parts[] = $flow[$i];
            }

            if ($isLast && $blankSeen && collect(array_slice($flow, $index + 1))->filter()->count() > count($parts) - 1) {
                $issues[] = 'Du texte suit la dernière étape : il a été laissé de côté, à vérifier.';
            }
            $steps[] = $parts;
        }

        return ['steps' => $steps, 'issues' => $issues];
    }

    // ---------------------------------------------------------------- 3. numéro au milieu du bloc

    private static function byMiddleNumbers(array $flow): ?array
    {
        $markers = self::sequence($flow, fn (string $line, int $n) => preg_match('/^'.$n.'\s+(\S.*)$/u', $line, $m) ? $m[1] : null);
        if (count($markers) < 2) {
            return null;
        }

        $issues = [];
        $texts = [];
        foreach ($markers as $k => $index) {
            $texts[$k] = preg_replace('/^'.($k + 1).'\s+/u', '', $flow[$index]);
        }

        // Lignes avant le premier numéro : tête de l'étape 1 (si aucune, le numéro est en début d'étape)
        $before = array_values(array_filter(array_slice($flow, 0, $markers[0]), fn ($l) => $l !== ''));
        $heads = [0 => $before];
        $tails = [];
        $middle = $before !== [];

        for ($k = 0; $k < count($markers); $k++) {
            $from = $markers[$k] + 1;
            $to = $markers[$k + 1] ?? count($flow);
            $gap = array_slice($flow, $from, $to - $from);

            if (! isset($markers[$k + 1])) {
                $tail = [];
                foreach ($gap as $line) {
                    if ($line === '') {
                        break;
                    }
                    $tail[] = $line;
                }
                $limit = $middle ? count($heads[$k]) : PHP_INT_MAX;
                if (count($tail) > $limit) {
                    $issues[] = 'Du texte suit la dernière étape : il a été laissé de côté, à vérifier.';
                    $tail = array_slice($tail, 0, $limit);
                }
                $tails[$k] = $tail;

                continue;
            }

            [$tails[$k], $heads[$k + 1]] = $middle
                ? self::splitGap($gap, count($heads[$k]), $texts[$k + 1], $issues, $k + 1)
                : [array_values(array_filter($gap, fn ($l) => $l !== '')), []];
        }

        $steps = [];
        foreach ($markers as $k => $index) {
            $steps[] = array_merge($heads[$k] ?? [], [$texts[$k]], $tails[$k] ?? []);
        }

        return ['steps' => $steps, 'issues' => array_values(array_unique($issues))];
    }

    /**
     * Entre deux numéros : fin de l'étape précédente puis début de la suivante.
     * Ligne vide = frontière ; sinon début de phrase (majuscule après un point) et centrage du numéro.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private static function splitGap(array $gap, int $headCount, string $nextText, array &$issues, int $stepNumber): array
    {
        $blank = array_keys(array_filter($gap, fn ($l) => $l === ''));
        if ($blank !== []) {
            $first = $blank[0];
            $last = $blank[array_key_last($blank)];
            $tail = array_values(array_filter(array_slice($gap, 0, $first), fn ($l) => $l !== ''));
            $head = array_values(array_filter(array_slice($gap, $last + 1), fn ($l) => $l !== ''));
            $between = array_values(array_filter(array_slice($gap, $first, $last - $first), fn ($l) => $l !== ''));
            if ($between !== []) {
                $issues[] = "Découpage incertain avant l'étape {$stepNumber} : à vérifier.";
                $tail = array_merge($tail, $between);
            }

            return [$tail, $head];
        }

        $g = count($gap);
        $valid = [];
        for ($j = 0; $j <= $g; $j++) {
            $afterSentence = $j === 0 || preg_match('/[.!?;:)»]$/u', $gap[$j - 1]) === 1;
            $startsStep = $j === $g
                ? preg_match('/^[\p{Lu}\d«"]/u', $nextText) === 1
                : preg_match('/^[\p{Lu}\d«"]/u', $gap[$j]) === 1;
            if ($afterSentence && $startsStep) {
                $valid[] = $j;
            }
        }

        // Le numéro est centré en hauteur : étape de L lignes, numéro à la ligne floor(L/2)
        $centered = array_values(array_filter($valid, fn ($j) => intdiv($headCount + 1 + $j, 2) === $headCount));
        $pick = $centered !== [] ? ($centered[0]) : null;
        if (count($centered) > 1) {
            $pick = in_array($headCount, $centered, true) ? $headCount : $centered[0];
        }
        if ($pick === null && count($valid) === 1) {
            $pick = $valid[0];
        }
        if ($pick === null) {
            $issues[] = "Découpage incertain avant l'étape {$stepNumber} : à vérifier.";
            $pick = min($g, $headCount);
        }

        return [array_slice($gap, 0, $pick), array_slice($gap, $pick)];
    }

    // ---------------------------------------------------------------- 4. puces et 5. paragraphes

    private static function byBullets(array $flow): ?array
    {
        $steps = [];
        foreach ($flow as $line) {
            if (preg_match('/^[•·▪●■◦\-–—*]\s*(.*)$/u', $line, $m)) {
                $steps[] = [$m[1]];
            } elseif ($line !== '' && $steps !== []) {
                $steps[array_key_last($steps)][] = $line;
            }
        }

        return count($steps) >= 2 ? ['steps' => $steps, 'issues' => []] : null;
    }

    private static function byParagraphs(array $flow): ?array
    {
        $steps = [];
        $current = [];
        foreach ($flow as $line) {
            if ($line === '') {
                if ($current !== []) {
                    $steps[] = $current;
                    $current = [];
                }

                continue;
            }
            $current[] = $line;
        }
        if ($current !== []) {
            $steps[] = $current;
        }

        if ($steps === []) {
            return null;
        }

        return ['steps' => $steps, 'issues' => count($steps) === 1 && count($steps[0]) > 3
            ? ['Étapes non numérotées : le texte est gardé en un seul bloc, à découper si besoin.'] : []];
    }

    /**
     * Repère les lignes de numérotation 1, 2, 3… dans l'ordre (le numéro suivant n'est cherché qu'après le précédent).
     *
     * @param  \Closure(string, int): ?string  $match
     * @return array<int, int> index de ligne de chaque numéro
     */
    private static function sequence(array $flow, \Closure $match): array
    {
        $markers = [];
        $n = 1;

        foreach ($flow as $i => $line) {
            if ($line !== '' && $match($line, $n) !== null) {
                $markers[] = $i;
                $n++;
            }
        }

        return $markers;
    }
}
