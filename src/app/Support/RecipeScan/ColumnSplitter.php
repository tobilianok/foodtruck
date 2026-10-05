<?php

namespace App\Support\RecipeScan;

use Illuminate\Support\Str;

/**
 * Fiches à deux colonnes (ingrédients à gauche, recette à droite) dont la reconnaissance de texte a mélangé
 * les colonnes ligne par ligne :
 *
 *   Les ingrédients La recette
 *   e 1 reblochon Etape 1
 *   : Faire cuire les crozets dans l'eau bouillante salée pendant 20 minutes.
 *   e 200 g de lardons la crème fraîche.
 *
 * Repérées à leurs deux titres sur la même ligne. Chaque ligne à puce est coupée en deux : l'ingrédient (début
 * de ligne) et ce qui appartient à la recette (repère « Étape », fin d'une phrase de la colonne de droite) ;
 * les lignes sans puce vont à la recette. Le texte est ensuite remis dans l'ordre habituel (titre, ingrédients,
 * recette) pour le lecteur ordinaire. Les repères d'étapes mal lus (« Etapat ») sont renumérotés dans l'ordre.
 *
 * Toute fiche lue ainsi reste à relire (voir RecipeTextParser) : la coupure est une estimation.
 */
class ColumnSplitter
{
    /** Puces de la colonne de gauche telles que la reconnaissance de texte les lit. */
    private const BULLET = '/^(?:[e•·°*©‘\'o\-–]|\?\?)\s+(?=\S)/u';

    /** Repère d'étape, éventuellement mal lu (« Etape 3 », « Étape3 », « Etapat »). */
    private const MARKER = '(?:[EÉ]tape\s*\d{1,2}|[EÉ]tap\p{L}{0,3}(?:\s*\d{1,2})?)';

    /** Repère d'étape illisible (« Biepeaz ») deviné à sa place : numéroté à la suite du précédent. */
    private const GUESSED_MARKER = '§étape§';

    /** Mots qui commencent un morceau de phrase de la colonne de droite. */
    private const LINK_WORDS = ['la', 'le', 'les', 'des', 'du', 'un', 'une', 'puis', 'et', 'dans', 'en', 'au', 'aux', 'a', 'à', 'sur', 'avec', 'pour', 'ou', 'jusqu', 'sans', 'pendant'];

    /** Le texte remis en ordre, ou null si la fiche n'a pas ses deux colonnes mélangées. */
    public static function split(string $text): ?string
    {
        $lines = explode("\n", $text);
        $header = null;
        foreach ($lines as $i => $line) {
            if (self::isDoubleHeading($line)) {
                $header = $i;
                break;
            }
        }
        if ($header === null) {
            return null;
        }

        $headLines = array_slice($lines, 0, $header);
        $left = [];
        $right = [];
        $next = 1;

        foreach (array_slice($lines, $header + 1) as $raw) {
            $line = trim($raw);
            if ($line === '' || preg_match('/^[^\p{L}\p{N}]{1,3}$/u', $line) === 1) {
                continue;
            }

            if (preg_match(self::BULLET, $line, $b) === 1) {
                $rest = trim(mb_substr($line, mb_strlen($b[0])));

                // Une puce suivie d'une longue phrase qui commence par une majuscule : c'est la recette (puce parasite)
                if (! self::startsWithQuantity($rest) && self::looksLikeSentence($rest)) {
                    self::pushRight($right, $rest, $next);

                    continue;
                }

                [$ingredient, $tail] = self::cut($rest, self::unfinished($right));
                $left[] = '- '.$ingredient;
                if ($tail !== null) {
                    self::pushRight($right, $tail, $next);
                }

                continue;
            }

            // Ligne de la colonne de droite (pointillé ou deux-points parasite en tête retiré)
            self::pushRight($right, trim(preg_replace('/^[:;|,.‘\'`]+\s+/u', '', $line)), $next);
        }

        if ($left === [] || $right === []) {
            return null;
        }

        return implode("\n", array_merge($headLines, ['', 'Les ingrédients'], $left, ['', 'La recette'], $right));
    }

    /** « Les ingrédients La recette » (ou « Ingrédients Préparation ») sur une seule ligne. */
    public static function isDoubleHeading(string $line): bool
    {
        $key = Str::lower(Str::ascii(trim($line)));
        $key = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z ]/', ' ', $key)));

        return preg_match('/^(?:les )?ingredients? (?:la )?(?:recette|preparation|realisation|etapes?)$/', $key) === 1;
    }

    /**
     * Coupe une ligne à puce : [ingrédient, morceau de recette ou null].
     *
     * @param  bool  $expectsContinuation  la dernière phrase de la recette n'est pas terminée
     * @return array{0: string, 1: ?string}
     */
    private static function cut(string $rest, bool $expectsContinuation): array
    {
        // Repère d'étape en fin de ligne (« 1 reblochon Etape 1 »), éventuellement suivi du début de l'étape
        if (preg_match('/^(.+?)\s+('.self::MARKER.')(?:\s+(.+))?$/u', $rest, $m) === 1 && self::isMarker($m[2])) {
            return [trim($m[1]), trim($m[2].(isset($m[3]) && $m[3] !== '' ? "\n".$m[3] : ''))];
        }

        // Mot illisible à majuscule en fin d'ingrédient (« crème fraîche Biepeaz ») : repère d'étape mal lu
        if (preg_match('/^(.+?)\s+(\p{Lu}\p{Ll}{3,8})$/u', $rest, $m) === 1 && preg_match('/\p{L}/u', $m[1]) === 1
            && preg_match('/(?:^|\s)(?:de|d[\x27’]|du|des|à|au|aux|et)$/iu', $m[1]) !== 1) {
            return [trim($m[1]), self::GUESSED_MARKER];
        }

        $words = preg_split('/\s+/u', $rest);
        $count = count($words);

        // Longueur minimale de l'ingrédient : quantité, unité, « de », puis au moins un mot
        $min = 1;
        if (self::startsWithQuantity($rest)) {
            $min = $count;
            foreach ($words as $k => $word) {
                if (! preg_match('/^(?:\d+(?:[.,]\d+)?[\p{L}]{0,3}|[\d\/½¼¾]+|g|gr|kg|cl|ci|ml|dl|l|cs|cc|c\.|de|d[\'’]\S*|pinc[ée]es?|cuill\S*)$/iu', $word)) {
                    $min = $k + 1;
                    break;
                }
            }
        }

        for ($k = $min; $k < $count; $k++) {
            $tail = implode(' ', array_slice($words, $k));
            $first = Str::lower($words[$k]);
            $startsWithLink = in_array($first, self::LINK_WORDS, true) || preg_match('/^(?:l|d|qu|j|s|n)[\'’]/u', $first) === 1;
            $endsSentence = preg_match('/[.!?:]$/u', $tail) === 1;

            if (($startsWithLink && ($endsSentence || $expectsContinuation))
                || preg_match('/^\S+(?:\s+\S+){0,2}[.!?](?:\s|$)/u', $tail) === 1
                || self::looksLikeSentence($tail)) {
                return [implode(' ', array_slice($words, 0, $k)), $tail];
            }
        }

        return [$rest, null];
    }

    /** Ajoute un morceau à la recette ; un repère d'étape va sur sa propre ligne, numéroté dans l'ordre. */
    private static function pushRight(array &$right, string $text, int &$next): void
    {
        foreach (explode("\n", $text) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if ($part === self::GUESSED_MARKER || self::isMarker($part)) {
                $number = $part !== self::GUESSED_MARKER && preg_match('/(\d{1,2})\s*$/u', $part, $n) ? (int) $n[1] : $next;
                $right[] = '';
                $right[] = 'Etape '.$number;
                $next = $number + 1;

                continue;
            }

            $right[] = $part;
        }
    }

    private static function isMarker(string $text): bool
    {
        return preg_match('/^'.self::MARKER.'$/u', trim($text)) === 1;
    }

    /** La dernière phrase de la recette continue sur la ligne suivante. */
    private static function unfinished(array $right): bool
    {
        for ($i = count($right) - 1; $i >= 0; $i--) {
            if ($right[$i] === '') {
                continue;
            }

            return ! self::isMarker($right[$i]) && preg_match('/[.!?:]$/u', $right[$i]) !== 1;
        }

        return false;
    }

    private static function startsWithQuantity(string $text): bool
    {
        return preg_match('/^[\d½¼¾]/u', $text) === 1;
    }

    /** Début de phrase de recette : majuscule et au moins quatre mots (« Couper l'oignon et le faire revenir… »). */
    private static function looksLikeSentence(string $text): bool
    {
        return preg_match('/^\p{Lu}\p{Ll}+/u', $text) === 1 && count(preg_split('/\s+/u', trim($text))) >= 4;
    }
}
