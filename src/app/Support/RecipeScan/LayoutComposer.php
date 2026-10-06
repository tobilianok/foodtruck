<?php

namespace App\Support\RecipeScan;

/**
 * Remet en forme une fiche lue par foodtruck-ocr (blocs de texte avec leur position, dans l'ordre de lecture)
 * en un texte propre et ordonné pour le lecteur habituel (RecipeTextParser) :
 *
 *   Titre
 *   4 pers 15 mn / Pour 4 personnes / Préparation : 35 min
 *   Les ingrédients
 *   …
 *   La recette
 *   Etape 1
 *   …
 *   Conseil
 *   …
 *
 * Ce que la mise en page permet et que le texte « à plat » de Paperless perdait : les colonnes ne sont jamais
 * mélangées, chaque bloc est un paragraphe, et les blocs parasites (valeurs nutritionnelles, allergènes, publicité,
 * légendes des photos, pied de page) sont reconnus et écartés en entier.
 *
 * Étapes : un repère « Étape N » (même mal lu) ou un bloc qui commence par un titre court (« Faire mijoter »)
 * ouvre une étape ; les blocs suivants de la même colonne la complètent. Pure fonction des blocs : testable.
 */
class LayoutComposer
{
    /** Lignes parasites (cartes de kits repas, fiches imprimées). */
    private const NOISE_LINE = '/valeurs nutritionnelles|^[\W_]*(?:[ée]nergie|lipides\s*total|lipides|glucides|fibres|prot[ée]ines|sel)\s*\((?:g|kj|£)|^[\W_]*dont\s*(?:satur|sucres)|^par portion|pour 100 ?g$|\bkcal\b|batch cooking'
        .'|veillez [àa] bien respecter les quantit|^(?:gauche pour )?pr[ée]parer votre recette\W*$|planifiez l\'ordre des recettes|date de p[ée]remption|inscrite sur l\'[ée]tiquette|rincez les fruits et|^l[ée]gumes\.?$|^c[’\']est parti'
        .'|^[àa] vos fourchettes|montrez-nous vos plats|partagez vos photos|#\s*hello\s*fresh|^semaine \d+\s*\|?\s*\d{4}|^\d+\/\d+-\d+\/\d+$|^[\W\d_]*h[eé]l+\p{L}{0,3}o[\W\d_]*$|^[\W\d_]*(?:hello\s*)?fresh[\W\d_]*$'
        .'|^mes ustensiles/iu';

    /** Blocs parasites entiers : pied de carte, allergènes. */
    private const NOISE_BLOCK = '/^[\W\d_]*fresh[\W\d_]*$|qr\s*code|faites-le-nous savoir|manquant ou\s+endommag|^allerg[èe]nes\b|traces d\'allerg|r[ée]f[ée]rez-vous aux [ée]tiquettes/imu';

    private const INGREDIENTS_HEADING = '/^\W*(?:les\s+)?ingr[ée]dients?(?:\s+n[ée]cessaires?)?(?:\s+pour\s+(\d+)\s*(?:personnes?|pers\.?|parts?))?\s*:?\s*$/iu';

    private const STEPS_HEADING = '/^\W*(?:la\s+)?(?:recette|pr[ée]paration|r[ée]alisation|[ée]tapes?|instructions?|m[ée]thode|d[ée]roul[ée])(?:\s+de la recette|\s+pas [àa] pas)?\s*:?\s*$/iu';

    /** Fin du tableau d'ingrédients d'une carte de kit repas. */
    private const INGREDIENTS_END = '/conserver au r[ée]frig[ée]rateur|valeurs nutritionnelles/iu';

    /** Repère interne : fin du tableau d'ingrédients (la ligne elle-même est retirée). */
    private const END = '§fin-ingredients§';

    private const TIP = '/^\W*(conseils?|astuces?|l\'astuce du chef|bon [àa] savoir|le saviez-vous|zoom nutrition)\s*:\s*(.*)$/iu';

    /** Temps imprimés sous forme d'étiquette : « À table dans : 25-35 min » (temps total). */
    private const TOTAL_TIME = '/(?:^|\s)(?:en cuisine|[àa] table dans|temps total|pr[êe]t en)\s*:?\s*(\d+)(?:\s*-\s*(\d+))?\s*(?:min|mn)/iu';

    /** @param  array{pages?: array<int, array{width?: int, height?: int, blocks?: array}>}  $layout */
    public static function compose(array $layout): string
    {
        $blocks = self::blocks($layout);
        if ($blocks === []) {
            return '';
        }

        // Taille habituelle du texte : un bloc d'une ligne écrit nettement plus gros est un titre
        $sizes = array_column($blocks, 'size');
        sort($sizes);
        $body = $sizes[intdiv(count($sizes), 2)] ?: 1;
        foreach ($blocks as &$b) {
            $b['big'] = count($b['lines']) === 1 && $b['size'] >= 1.15 * $body;
        }
        unset($b);

        $all = '';
        foreach ($layout['pages'] ?? [] as $page) {
            foreach ($page['blocks'] ?? [] as $block) {
                $all .= implode("\n", $block['lines'] ?? [])."\n";
            }
        }
        $kit = preg_match('/ingr[ée]dients?\s+pour\s+\d+\s+personnes?/iu', $all) === 1
            && preg_match('/hello\s*fresh|mes\s+ustensiles/iu', $all) === 1;
        $footer = preg_match('/^.*retrouvez[^\n]*\bsur\b[^\n]*$/imu', $all, $f) ? trim($f[0]) : null;
        $footerLine = $footer === null ? null : self::cleanLine($footer);
        $footer = $footer === null ? null : preg_replace('/\b(www)[,.](\w+)[,.](\w+)\b/iu', '$1.$2.$3', $footer);

        $title = self::title($blocks);
        $meta = [];
        $ingredients = [];
        $steps = [];
        $tip = [];
        $section = 'head';
        $seenIngredients = false;
        $ingredientsClosed = false;
        $ingredientsAt = null;

        foreach ($blocks as $i => $block) {
            if ($i === $title['index']) {
                continue;
            }
            $lines = $block['lines'];
            if (count($lines) >= 2 && mb_strlen($lines[0]) <= 4 && ! preg_match('/\d/u', $lines[0]) && self::isStepTitle($lines[1])
                && ! in_array($section, ['ingredients', 'head'], true)) {
                array_shift($lines);
                $block['lines'] = $lines;
            }
            $sameColumn = $ingredientsAt !== null && $block['page'] === $ingredientsAt['page'] && abs($block['x'] - $ingredientsAt['x']) < 0.05;

            // Un encadré (conseil, astuce) ne s'étend pas au bloc suivant
            if ($section === 'tip') {
                $section = $seenIngredients ? 'steps' : 'head';
            }

            // Liste d'ingrédients : seulement dans sa colonne ; la suite du tableau peut reprendre plus bas dans la même colonne
            if ($section === 'ingredients' && ! $sameColumn) {
                $section = 'steps';
            } elseif ($section !== 'ingredients' && $seenIngredients && ! $ingredientsClosed && $sameColumn && self::looksLikeIngredient($lines[0])) {
                $section = 'ingredients';
            }

            // Un bloc d'ingrédients se termine dès qu'arrive un paragraphe de texte
            if ($section === 'ingredients' && ! self::hasIngredientHeading($lines)
                && (self::isProse($lines) || (count($lines) >= 2 && self::isStepTitle($lines[0]) && self::isProse(array_slice($lines, 1))))) {
                $section = 'steps';
            }

            foreach ($lines as $n => $line) {
                // Pied de page « Retrouvez toutes nos recettes sur … » : repris à la fin pour la source
                if ($footerLine !== null && ($line === $footerLine || $line === $footer)) {
                    continue;
                }
                if (preg_match(self::INGREDIENTS_HEADING, $line, $m)) {
                    $section = 'ingredients';
                    $seenIngredients = true;
                    $ingredientsAt ??= ['page' => $block['page'], 'x' => $block['x']];
                    if (! empty($m[1])) {
                        $meta[] = 'Pour '.$m[1].' personnes';
                    }

                    continue;
                }
                if (preg_match(self::STEPS_HEADING, $line) && mb_strlen($line) < 40) {
                    $section = 'steps';

                    continue;
                }
                if (preg_match(self::TIP, $line, $m)) {
                    $section = 'tip';
                    if (trim($m[2]) !== '') {
                        $tip[] = trim($m[2]);
                    }

                    continue;
                }
                if (($time = self::metaLine($line)) !== null) {
                    if ($section !== 'steps' || $n === 0) {
                        $meta[] = $time;

                        continue;
                    }
                }

                if ($line === self::END) {
                    if ($section === 'ingredients') {
                        $section = 'after-ingredients';
                    }
                    $ingredientsClosed = $ingredientsClosed || $seenIngredients;

                    continue;
                }

                if ($section === 'ingredients') {
                    $item = self::ingredientLine($line);
                    // Nouvel ingrédient (puce, quantité, majuscule) ou suite de la ligne précédente (minuscule, sans puce)
                    $continues = $ingredients !== [] && ! self::hadBullet($line) && preg_match('/^\p{Ll}/u', $item) === 1
                        && ! str_ends_with($item, ':') && ! str_ends_with(end($ingredients), ':');
                    $ingredients[] = $continues || str_ends_with($item, ':') ? $item : '- '.$item;

                    continue;
                }

                if ($section === 'tip') {
                    $tip[] = $line;

                    continue;
                }

                // Texte avant la liste d'ingrédients (présentation, accroche) : laissé de côté
                if (in_array($section, ['head', 'after-ingredients'], true) && ! $seenIngredients) {
                    continue;
                }

                $section = 'steps';
                self::addStepLine($steps, $block, $n, $line);
            }

            if ($section === 'after-ingredients') {
                $section = 'steps';
            }
        }

        return self::render($title['text'], $meta, $ingredients, $steps, $tip, $footer ?? ($kit ? 'Retrouvez toutes nos recettes sur www.hellofresh.fr' : null));
    }

    /** Blocs de toutes les pages, lignes nettoyées, blocs et lignes parasites retirés. */
    private static function blocks(array $layout): array
    {
        $out = [];
        foreach ($layout['pages'] ?? [] as $p => $page) {
            $width = max(1, (int) ($page['width'] ?? 1));
            $height = max(1, (int) ($page['height'] ?? 1));
            foreach ($page['blocks'] ?? [] as $block) {
                $text = implode("\n", $block['lines'] ?? []);
                // Bloc lu avec une très faible confiance : tache, photo, pictogramme
                if (preg_match(self::NOISE_BLOCK, $text) || (isset($block['conf']) && (int) $block['conf'] < 40)) {
                    continue;
                }

                $lines = [];
                foreach ($block['lines'] ?? [] as $line) {
                    $line = self::cleanLine((string) $line);
                    if (preg_match(self::INGREDIENTS_END, $line)) {
                        $lines[] = self::END;

                        continue;
                    }
                    if ($line === '' || preg_match('/\p{L}.*\p{L}/u', $line) !== 1 || preg_match(self::NOISE_LINE, $line)) {
                        continue;
                    }
                    $lines[] = $line;
                }
                // Légende de photo : bloc court dont aucune ligne n'a été retirée (sinon c'est un titre d'étape dont la consigne générale a été ôtée)
                $text = array_values(array_filter($lines, fn ($l) => $l !== self::END));
                if ($text === [] && $lines === []) {
                    continue;
                }
                if ($text !== [] && count($lines) === count($block['lines'] ?? []) && self::isCaption($text, $block, $width)) {
                    continue;
                }

                $out[] = [
                    'page' => $p,
                    'x' => (int) ($block['x'] ?? 0) / $width,
                    'y' => (int) ($block['y'] ?? 0) / $height,
                    'size' => (int) ($block['size'] ?? 0),
                    'lines' => $lines,
                ];
            }
        }

        return $out;
    }

    private static function cleanLine(string $line): string
    {
        $line = trim(preg_replace('/\s+/u', ' ', $line));
        // Puces de la colonne d'étapes lues « + », « _ », « __ », « • »
        $line = preg_replace('/^(?:[+_•·»>]+|_{2,})\s+/u', '', $line);
        // Puce ronde lue « e » ou « e_ » devant une phrase, ou recopiée en fin de ligne depuis la colonne voisine
        $line = preg_replace('/^e_?\s+(?=\p{Lu})/u', '', $line);
        $line = preg_replace('/(?:\s+e)+$/u', '', $line);
        // « ¼ » lu « Y4 », « Ya » ou « Y » devant une unité (« ainsi que Y4 sachet de sauce », « avec Ya cc de curry »)
        $line = preg_replace('/(?<=^|\s)(?:Y4|Ya|Y)(?=\s+(?:cc|cs|c\.|sachets?|paquets?|pots?|pi[eè]ces?)\b)/u', '¼', $line);

        return trim($line);
    }

    /** Légende d'une photo (« Poivron », « Fromage râpé à l'italienne ») : quelques mots isolés, sans chiffre. */
    private static function isCaption(array $lines, array $block, int $width): bool
    {
        if (count($lines) > 3) {
            return false;
        }
        foreach ($lines as $line) {
            if (preg_match('/\d/u', $line) || count(preg_split('/\s+/u', $line)) > 3
                || preg_match(self::INGREDIENTS_HEADING, $line) || preg_match(self::STEPS_HEADING, $line) || preg_match(self::TIP, $line)
                || ColumnSplitter::isStepMarker($line) || preg_match('/[.!?:]$/u', $line)) {
                return false;
            }
        }

        // Mot isolé sur le bord de la page ou entre les photos : légende. Un titre de page (grand caractère) n'en est pas une.
        return count($lines) >= 1 && (int) ($block['size'] ?? 0) < 40;
    }

    /** Titre : le plus grand texte du haut de la première page. @return array{index: ?int, text: ?string} */
    private static function title(array $blocks): array
    {
        $best = null;
        foreach ($blocks as $i => $block) {
            if ($block['page'] !== 0 || $block['y'] > 0.45 || mb_strlen($block['lines'][0]) < 3) {
                continue;
            }
            if (preg_match(self::INGREDIENTS_HEADING, $block['lines'][0]) || preg_match(self::STEPS_HEADING, $block['lines'][0])) {
                continue;
            }
            if ($best === null || $block['size'] > $blocks[$best]['size']) {
                $best = $i;
            }
        }

        return ['index' => $best, 'text' => $best === null ? null : $blocks[$best]['lines'][0]];
    }

    /** Bandeau ou étiquette de temps / personnes, ramené à une forme que le lecteur connaît. */
    private static function metaLine(string $line): ?string
    {
        if (preg_match(self::TOTAL_TIME, $line, $m)) {
            return 'Préparation : '.(int) ($m[2] ?? $m[1] ?: $m[1]).' min';
        }
        if (mb_strlen($line) <= 30 && preg_match('/^\W*(?:\d{1,2}\s*(?:pers\.?|personnes?|parts?)|\d+\s*(?:h\s*\d*|min|mn))\W*$/iu', $line)) {
            return $line;
        }
        // « Préparation : 20 min », « Cuisson : 1 h », « Pour 4 personnes » : formes que le lecteur connaît
        if (mb_strlen($line) <= 60 && preg_match('/^\W*(?:(?:temps de )?(?:pr[ée]paration|cuisson|repos)\s*:?\s*\d|pour\s+\d{1,2}\s*(?:personnes?|pers\.?|parts?)\W*$)/iu', $line)) {
            return $line;
        }

        return null;
    }

    private static function hasIngredientHeading(array $lines): bool
    {
        foreach ($lines as $line) {
            if (preg_match(self::INGREDIENTS_HEADING, $line)) {
                return true;
            }
        }

        return false;
    }

    /** Paragraphe de texte : des phrases, pas une liste. */
    private static function isProse(array $lines): bool
    {
        // Une ligne de liste (puce, quantité en tête) n'est pas une phrase, même longue
        $long = count(array_filter($lines, fn ($l) => count(preg_split('/\s+/u', $l)) >= 6
            && preg_match('/^(?:[\-–•·*°©‘+]|e\s|\d|[½¼¾])/u', $l) !== 1));

        return $long >= 1 && $long >= count($lines) / 2;
    }

    /** Ligne de tableau d'ingrédients (« Huile de tournesol 1% cs », « Poivre et sel selon votre goût »). */
    private static function looksLikeIngredient(string $line): bool
    {
        return preg_match(MealKitSheetParser::ROW, $line) === 1
            || preg_match('/\s(?:\d+(?:[.,]\d+)?|\d%|[½¼¾])\s*(?:g|kg|ml|cl|cs|cc|sachets?|paquets?|pi[eè]ces?)(?:\(s\))?$/iu', $line) === 1
            || preg_match('/selon (?:votre|le) go[uû]t$/iu', $line) === 1;
    }

    private static function hadBullet(string $line): bool
    {
        return preg_match('/^(?:[°©‘•·*\-–]|e(?=\s))\s+/u', $line) === 1;
    }

    /** « Gousse d'ail 3 pièce(s) » (nom puis quantité, cartes de kits) → « 3 pièces gousse d'ail ». */
    private static function ingredientLine(string $line): string
    {
        $line = trim(preg_replace('/^(?:[°©‘•·*\-–]|e(?=\s))\s+/u', '', $line));
        $line = preg_replace('/(?<=\p{L})[*“”"]+/u', '', $line);
        $line = trim(preg_replace(['/\s+[,.]\s+/u', '/\s+[_|]+(?=\s|$)/u'], ' ', $line));

        if (preg_match('/^[àa] ajouter vous-m[êe]me\W*$/iu', $line)) {
            return 'À ajouter vous-même :';
        }
        if (preg_match('/^pour (?:la|le|les)\s.+$/iu', $line)) {
            return rtrim($line, ' :').' :';
        }

        // « 1% cs » : « 1½ » dont la fraction a été lue « % »
        $fraction = null;
        if (preg_match('/(?<=\s)(\d)%(?=\s*(?:cs|cc|sachet|paquet|pi[eè]ce|pot))/u', $line, $f) === 1) {
            $line = preg_replace('/(?<=\s)(\d)%(?=\s*(?:cs|cc|sachet|paquet|pi[eè]ce|pot))/u', '$1½', $line, 1);
            $fraction = "« {$f[1]}% » lu pour « {$f[1]}½ » (fraction perdue) : à vérifier avec la fiche.";
        }

        if (preg_match(MealKitSheetParser::ROW, $line, $m) === 1 && trim($m['rest']) === '' && mb_strtolower($m['unit']) === 'cm') {
            return trim($m['qty']).' '.trim($m['name']).' ('.trim($m['qty']).' cm) ⚠ Quantité en centimètres : 1 pièce mise par défaut, à convertir.';
        }

        if (preg_match(MealKitSheetParser::ROW, $line, $m) === 1 && trim($m['rest']) === '') {
            $unit = mb_strtolower(preg_replace('/\(s\)/u', '', $m['unit']));
            [$qty, $warning] = self::kitQuantity(trim($m['qty']), $unit);
            $unit = preg_match('/^pi[eè]ce/u', $unit) ? '' : $unit;

            $warning ??= $fraction;

            return trim(preg_replace('/\s+/u', ' ', $qty.' '.$unit.' '.trim($m['name']))).($warning ? ' ⚠ '.$warning : '');
        }

        // « Coriandre et basilic thaï* sachet(s) » : la quantité (souvent ½) n'a pas été lue
        if (preg_match('/^(\p{L}[\p{L}\'’ \-]*?)\*?\s+(sachets?|paquets?|pi[eè]ces?|pots?)(?:\(s\))?$/u', $line, $m) === 1) {
            $unit = preg_match('/^pi[eè]ce/u', $m[2]) ? '' : rtrim($m[2], 's');

            return trim('½ '.$unit.' '.trim($m[1])).' ⚠ Quantité illisible sur la carte : ½ supposé, à vérifier avec la fiche.';
        }

        // « Crevettes* 3208 » : le « g » de « 320g » lu comme un « 8 »
        if (preg_match('/^(\p{L}[\p{L}\'’ \-]*?)\s+(\d{2,4})8$/u', $line, $m) === 1) {
            return $m[2].' g '.trim($m[1]).' ⚠ « '.$m[2].'8 » lu comme « '.$m[2].' g » : à vérifier avec la fiche.';
        }

        // « Vinaigre balsamique blanc ou de riz 2cc », « Huile d'olive 2cs »
        if (preg_match('/^(\p{L}.*?)\s+(\d+(?:[.,]\d+)?\s*[½¼¾]?)\s*(cs|cc|g|kg|ml|cl)$/iu', $line, $m) === 1) {
            return trim($m[2]).' '.$m[3].' '.trim($m[1]);
        }

        return $line;
    }

    /**
     * Quantité d'une carte de kit : la fraction (½, ¼) est souvent perdue par la lecture (« 14 sachet » pour 1¼,
     * « 22 cs » pour 2½). Réparée quand le nombre est invraisemblable, et toujours signalée.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function kitQuantity(string $qty, string $unit): array
    {
        if (preg_match('/^\d{2}$/', $qty) && in_array((int) $qty % 10, [2, 4], true)
            && preg_match('/^(?:cs|cc|sachet|paquet|pot|bo[iî]te|gousse|tranche|pi[eè]ce)/u', $unit) && (int) $qty >= 12) {
            $whole = intdiv((int) $qty, 10);
            $fraction = (int) $qty % 10 === 2 ? '½' : '¼';

            return [$whole.$fraction, "« {$qty} {$unit} » lu pour « {$whole}{$fraction} {$unit} » (fraction perdue) : à vérifier avec la fiche."];
        }
        if ($qty === '4' && preg_match('/^(?:sachet|paquet|pot|pi[eè]ce)/u', $unit)) {
            return [$qty, "« 4 {$unit} » : la fraction ½ ou ¼ est souvent lue « 4 », à vérifier avec la fiche."];
        }
        if (in_array($qty, ['%', '#', 'Z'], true)) {
            return ['½', "Fraction lue « {$qty} » : ½ supposé, à vérifier avec la fiche."];
        }

        return [$qty, null];
    }

    /**
     * Ajoute une ligne à la recette : un repère « Étape N » ou un titre court en tête de bloc ouvre une étape,
     * sinon la ligne complète l'étape en cours (même colonne) ou en ouvre une nouvelle (autre colonne).
     */
    private static function addStepLine(array &$steps, array $block, int $n, string $line): void
    {
        if (ColumnSplitter::isStepMarker($line)) {
            $steps[] = ['x' => $block['x'], 'y' => $block['y'], 'page' => $block['page'], 'title' => null, 'lines' => [], 'marker' => true];

            return;
        }

        $last = array_key_last($steps);
        $current = $last === null ? null : $steps[$last];

        // « 1. Mélanger… », « 2) Ajouter… » : étape numérotée
        if (preg_match('/^\d{1,2}\s*[.)\-–]\s+(\S.*)$/u', $line, $m) === 1) {
            $steps[] = ['x' => $block['x'], 'y' => $block['y'], 'page' => $block['page'], 'title' => null, 'lines' => [$m[1]], 'marker' => true];

            return;
        }

        if ($n === 0) {
            $sameColumn = $current !== null && $current['page'] === $block['page'] && abs($current['x'] - $block['x']) < 0.05;

            if (self::isStepTitle($line) || (! empty($block['big']) && count(preg_split('/\s+/u', $line)) <= 6 && preg_match('/[.:,;]$/u', $line) !== 1)) {
                $steps[] = ['x' => $block['x'], 'y' => $block['y'], 'page' => $block['page'], 'title' => self::ucfirst($line), 'lines' => []];

                return;
            }

            // Paragraphe suivant : il complète une étape titrée ou ouverte par un repère (même colonne),
            // ou une phrase coupée (minuscule) ; sinon c'est une nouvelle étape
            $completes = $current !== null && $sameColumn
                && ($current['title'] !== null || ! empty($current['marker']) || $current['lines'] === [] || preg_match('/^\p{Ll}/u', $line) === 1);
            if (! $completes) {
                $steps[] = ['x' => $block['x'], 'y' => $block['y'], 'page' => $block['page'], 'title' => null, 'lines' => [$line]];

                return;
            }
        }

        $steps[array_key_last($steps)]['lines'][] = $line;
    }

    /**
     * Étapes titrées d'une carte de kit, imprimées en grille (2 ou 3 colonnes, 2 rangées) : lues rangée par rangée,
     * de gauche à droite. Les étapes numérotées ou repérées (« Étape 3 ») gardent l'ordre lu.
     */
    private static function readingOrder(array $steps): array
    {
        if (count($steps) < 3 || collect($steps)->contains(fn ($s) => $s['title'] === null)) {
            return $steps;
        }

        usort($steps, function ($a, $b) {
            if ($a['page'] !== $b['page']) {
                return $a['page'] <=> $b['page'];
            }
            // Même rangée : titres à moins de 4 % de la hauteur de page l'un de l'autre
            return abs($a['y'] - $b['y']) < 0.04 ? $a['x'] <=> $b['x'] : $a['y'] <=> $b['y'];
        });

        return $steps;
    }

    private static function ucfirst(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /** Titre d'étape de carte de kit (« Préparer », « Faire mijoter », « Cuire les crevettes »). */
    private static function isStepTitle(string $line): bool
    {
        return preg_match('/^\p{Lu}/u', $line) === 1
            && count(preg_split('/\s+/u', $line)) <= 5
            && preg_match('/[.:,;]$/u', $line) !== 1
            && preg_match('/\d/u', $line) !== 1;
    }

    private static function render(?string $title, array $meta, array $ingredients, array $steps, array $tip, ?string $footer): string
    {
        $out = [];
        if ($title) {
            $out[] = $title;
            $out[] = '';
        }

        // Bandeau « 4 pers » + « 15 mn » lu en deux blocs : remis sur une ligne
        $short = array_values(array_filter($meta, fn ($m) => ! str_contains($m, ':') && ! str_starts_with($m, 'Pour ')));
        $other = array_values(array_unique(array_diff($meta, $short)));
        if ($short !== []) {
            $out[] = implode(' ', $short);
        }
        array_push($out, ...$other);

        if ($ingredients !== []) {
            array_push($out, '', 'Les ingrédients', ...$ingredients);
        }

        if ($tip !== []) {
            $tipText = implode(' ', $tip);
            array_push($out, '', 'Conseil', mb_strtoupper(mb_substr($tipText, 0, 1)).mb_substr($tipText, 1));
        }

        $steps = self::readingOrder(array_values(array_filter($steps, fn ($s) => $s['lines'] !== [])));
        if ($steps !== []) {
            $out[] = '';
            $out[] = 'La recette';
            foreach ($steps as $k => $step) {
                $out[] = '';
                $out[] = 'Etape '.($k + 1);
                $body = implode(' ', $step['lines']);
                $out[] = $step['title'] ? $step['title'].' : '.$body : $body;
            }
        }

        if ($footer) {
            array_push($out, '', $footer);
        }

        return trim(implode("\n", $out));
    }
}
