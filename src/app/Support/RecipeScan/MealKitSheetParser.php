<?php

namespace App\Support\RecipeScan;

/**
 * Lecteur des fiches de kits repas (format HelloFresh) scannées : la reconnaissance de texte de Paperless
 * mélange les colonnes de la page (tableau des ingrédients, valeurs nutritionnelles et étapes sont imprimés côte à côte).
 *
 * Ce que la fiche contient : titre, sous-titre, « À table dans : 35 - 45 Min », une légende de photos,
 * « Ingrédients pour 2 personnes » (nom puis quantité), un bloc « À ajouter vous-même », puis des étapes titrées
 * (« Chop, chop, chop », « Tout baigne »…) en puces, sans numéros dans le texte.
 *
 * Ce que le lecteur fait : retire le tableau d'ingrédients et les valeurs nutritionnelles des lignes d'étapes,
 * sépare les colonnes entrelacées (repère de puce, fin de phrase, sinon équilibre des longueurs), remet les étapes
 * dans l'ordre de la carte, répare les fractions perdues (« ½ », « ¼ ») et signale tout ce qui reste incertain.
 * Une fiche de ce format n'est jamais publiée toute seule : les étapes sont à relire avec le PDF.
 */
class MealKitSheetParser
{
    /** Ligne sans repère plus longue que ça : elle porte probablement deux colonnes. */
    private const TWO_COLUMNS_MIN = 60;

    /** Fin de phrase + majuscule dans une ligne plus longue que ça : changement de colonne. */
    private const SENTENCE_SPLIT_MIN = 52;

    private const GLYPHS = '[e°•·+]';

    private const ROW = '/^(?<name>\p{L}[\p{L}\'’ \-]*?)(?<star>\*)?\s+(?<qty>\d+(?:[.,]\d+)?\s*[½¼¾]?|[½¼¾⅓⅔]|[%#]|Z)\s*(?<unit>kg|g|ml|cl|cm|cs|cc|pi[eè]ces?(?:\(s\))?|sachets?(?:\(s\))?|paquets?(?:\(s\))?|pots?(?:\(s\))?|bo[iî]tes?(?:\(s\))?|gousses?(?:\(s\))?|tranches?(?:\(s\))?)(?![\p{L}])(?<rest>.*)$/u';

    private const NUTRITION_ROW = '/^(?:[ÉE]nergie|Lipides|Dont|Glucides|Fibres|Prot[ée]ines|Sel)\b[^()\d]*(?:\([^)]*\))?[\s\d,.\/]*/u';

    /** Libellés des encadrés « conseil » de la carte. */
    private const TIP_MARKER = '/(L\'ASTUCE DU CHEF|ZOOM NUTRITION|LE SAVIEZ-VOUS|BON À SAVOIR)\s*:?\s*/iu';

    public static function detects(string $text): bool
    {
        return preg_match('/ingr[ée]dients?\s+pour\s+\d+\s+personnes?/iu', $text) === 1
            && (preg_match('/hello\s*fresh/iu', $text) === 1
                || (preg_match('/mes\s+ustensiles/iu', $text) === 1 && preg_match('/c\'est\s+parti/iu', $text) === 1));
    }

    /**
     * @param  array{text: string, title: ?string, author: ?string, domain: ?string}  $clean
     */
    public static function parse(array $clean, ?string $titleHint = null): array
    {
        $lines = array_map('trim', explode("\n", $clean['text']));
        $issues = ['Fiche à colonnes (kit repas) : les étapes sont reconstituées à partir d\'un texte mélangé, à relire avec le PDF.'];

        $iIngredients = self::findLine($lines, '/^ingr[ée]dients?\s+pour\s+\d+\s+personnes?/iu') ?? 0;
        $iStart = self::findLine($lines, '/^c\'est parti/iu') ?? $iIngredients;
        $head = array_slice($lines, 0, $iStart);

        // En-tête : titre, sous-titre, temps, légende des photos
        $meta = self::head($head);
        $title = trim((string) $titleHint) !== '' ? trim((string) $titleHint) : ($meta['title'] ?: 'Recette sans titre');
        $title = mb_substr($title, 0, 120);

        // Ustensiles
        $utensils = [];
        $iUtensils = self::findLine($lines, '/^mes ustensiles/iu');
        if ($iUtensils !== null && $iUtensils < $iIngredients) {
            $utensils = array_values(array_filter(array_slice($lines, $iUtensils + 1, $iIngredients - $iUtensils - 1), fn ($l) => $l !== ''));
        }

        // Nombre de personnes et titre de la première étape (même ligne que « Ingrédients pour 2 personnes »)
        preg_match('/^ingr[ée]dients?\s+pour\s+(\d+(?:[.,]\d+)?)\s+personnes?\s*(.*)$/iu', $lines[$iIngredients] ?? '', $m);
        $yield = isset($m[1]) ? (float) str_replace(',', '.', $m[1]) : null;
        $firstTitle = trim($m[2] ?? '');

        $iEndTable = self::findLine($lines, '/^\*\s*conserver/iu', $iIngredients + 1) ?? count($lines);
        $table = array_slice($lines, $iIngredients + 1, max(0, $iEndTable - $iIngredients - 1));
        $after = array_slice($lines, $iEndTable + 1);

        [$ingredients, $stepFragments, $ingredientIssues] = self::table($table);
        $issues = array_merge($issues, $ingredientIssues);

        // Étapes
        $groups = [];
        $tips = [];
        $groups[] = ['source' => 'table', 'title' => $firstTitle !== '' ? $firstTitle : null, 'frags' => $stepFragments];
        self::flow($after, $groups, $tips);

        $steps = [];
        foreach (self::ordered($groups) as $group) {
            $bullets = self::bullets($group['frags']);
            if ($bullets === []) {
                continue;
            }
            $body = ($group['title'] ? rtrim($group['title'], ' .').' — ' : '').implode(' ', $bullets);
            $steps[] = $body;
        }

        // Réparations de texte (fractions perdues, mots collés) sur les étapes et les conseils
        $repaired = 0;
        $steps = array_map(function (string $body) use (&$repaired) {
            return self::repair($body, $repaired);
        }, $steps);
        $tips = array_map(function (string $tip) use (&$repaired) {
            return self::repair($tip, $repaired);
        }, $tips);
        if ($repaired > 0) {
            $issues[] = 'Fraction illisible dans une étape (½ ou ¼ supposé) : à vérifier avec le PDF.';
        }
        // « 2 cc de curry par personne » : un « ½ » imprimé peut avoir été lu « 2 »
        if (preg_match('/\b2 (?:cc|cs)\b[^.]{0,40}par personne/u', implode(' ', $steps))) {
            $issues[] = 'Une quantité de cuillères « 2 … par personne » est peut-être un « ½ » mal lu : à vérifier avec le PDF.';
        }

        // Ingrédients annoncés dans la légende des photos mais absents du tableau (ligne absorbée par la mise en page)
        $ingredients = self::completeFromLegend($ingredients, $meta['legend'], $steps, $issues);

        if ($ingredients === []) {
            $issues[] = 'Aucun ingrédient lu dans la fiche.';
        }
        if ($steps === []) {
            $issues[] = 'Aucune étape reconnue dans la fiche.';
        }
        if ($yield === null) {
            $issues[] = 'Nombre de personnes non trouvé : 4 par défaut.';
        }

        $stepRows = array_map(fn (string $body) => ['body' => mb_substr($body, 0, 2000), 'timer' => RecipeTextParser::timer($body)], $steps);

        $descriptionParts = [];
        if ($meta['subtitle']) {
            $descriptionParts[] = rtrim(mb_strtoupper(mb_substr($meta['subtitle'], 0, 1)).mb_substr($meta['subtitle'], 1), '.').'.';
        }
        if ($meta['time_label']) {
            $descriptionParts[] = 'À table en '.$meta['time_label'].'.';
        }
        if ($utensils !== []) {
            $descriptionParts[] = 'Ustensiles : '.rtrim(self::joinLines($utensils), '.').'.';
        }
        $tip = $tips === [] ? null : implode(' ', $tips);
        if ($tip) {
            $descriptionParts[] = $tip;
        }

        $haystack = mb_strtolower($title);
        $source = 'HelloFresh';
        if (preg_match('/semaine\s+(\d{1,2})\s*\|?\s*(\d{4})/iu', $clean['text'], $s)) {
            $source .= ' (semaine '.(int) $s[1].', '.$s[2].')';
        }

        return [
            'title' => $title,
            'description' => $descriptionParts === [] ? null : mb_substr(implode(' ', $descriptionParts), 0, 2000),
            'category' => RecipeTextParser::category($haystack),
            'yield_quantity' => $yield,
            'yield_unit' => 'personnes',
            'prep_minutes' => $meta['minutes'],
            'cook_minutes' => null,
            'rest_minutes' => null,
            'difficulty' => 'facile',
            'source' => $source,
            'tags' => RecipeTextParser::tags($haystack),
            'ingredients' => $ingredients,
            'steps' => $stepRows,
            'tip' => $tip,
            'issues' => array_values(array_unique($issues)),
            'layout' => 'kit',
        ];
    }

    // ---------------------------------------------------------------- en-tête

    /** @return array{title: ?string, subtitle: ?string, minutes: ?int, time_label: ?string, legend: array<int, string>} */
    private static function head(array $head): array
    {
        $title = null;
        $subtitle = null;
        $minutes = null;
        $timeLabel = null;
        $timeIndex = null;

        foreach ($head as $i => $line) {
            if ($line === '') {
                continue;
            }
            if ($title === null && preg_match('/^(?:HELLO\s+)?(\p{L}.{3,})$/u', $line, $m) && ! preg_match('/^FRESH$/', $line)) {
                $title = trim($m[1]);

                continue;
            }
            if ($subtitle === null && preg_match('/^avec\s+\S/iu', $line)) {
                $subtitle = $line;

                continue;
            }
            if ($timeIndex === null && preg_match('/table\s+dans\s*:?\s*(\d+)(?:\s*[-–]\s*(\d+))?\s*min/iu', $line, $m)) {
                $timeIndex = $i;
                $minutes = (int) ($m[2] ?? $m[1]);
                $timeLabel = isset($m[2]) ? "{$m[1]}-{$m[2]} min" : "{$m[1]} min";
            }
        }

        // Légende des photos : un nom par ligne, la suite d'un nom coupé commence par une minuscule
        $legend = [];
        foreach (array_slice($head, ($timeIndex ?? -1) + 1) as $line) {
            if ($line === '' || preg_match('/^FRESH$/', $line) || mb_strlen(preg_replace('/[^\p{L}]/u', '', $line)) < 4) {
                continue;
            }
            if (preg_match('/^\p{Ll}/u', $line) && $legend !== []) {
                $legend[array_key_last($legend)] .= ' '.$line;

                continue;
            }
            $legend[] = $line;
        }

        return ['title' => $title, 'subtitle' => $subtitle, 'minutes' => $minutes, 'time_label' => $timeLabel, 'legend' => $legend];
    }

    // ---------------------------------------------------------------- tableau des ingrédients

    /**
     * @return array{0: array<int, array>, 1: array<int, array{text: string, bullet: bool}>, 2: array<int, string>}
     *                                                                                                              ingrédients, fragments d'étape de la colonne voisine, réserves
     */
    private static function table(array $lines): array
    {
        $rows = [];
        $fragments = [];
        $issues = [];

        $lastRow = null;
        foreach ($lines as $i => $line) {
            if (preg_match(self::ROW, $line)) {
                $lastRow = $i;
            }
        }

        $addBlock = $lastRow === null ? [] : array_slice($lines, $lastRow + 1);
        $main = $lastRow === null ? $lines : array_slice($lines, 0, $lastRow + 1);

        foreach ($main as $line) {
            if ($line === '') {
                continue;
            }
            if (preg_match(self::ROW, $line, $m)) {
                $item = self::rowItem($line, $m);
                $rows[] = $item;
                if (($f = self::fragment($m['rest'], true)) !== null) {
                    $fragments[] = $f;
                }

                continue;
            }
            if (($f = self::fragment($line, true)) !== null) {
                $fragments[] = $f;
            }
        }

        // « À ajouter vous-même » : la quantité est imprimée avant le nom
        $pending = null;
        foreach ($addBlock as $line) {
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(?<q>\d+(?:[.,]\d+)?\s*[½¼¾]?|[½¼¾])\s*(?<u>cs|cc|g|ml|cl|kg)$/iu', $line, $m)) {
                $pending = [$m['q'], mb_strtolower($m['u'])];

                continue;
            }
            $words = preg_split('/\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY);
            if ($pending !== null && count($words) >= 1) {
                $check = null;
                $qty = self::quantity($pending[0], $pending[1], $check);
                $rows[] = [
                    'group' => 'À ajouter vous-même',
                    'raw' => trim(preg_replace('/\s+/u', ' ', $pending[0].' '.$pending[1].' '.$line)),
                    'name' => self::ucfirst($line),
                    'quantity' => $qty,
                    'unit' => $pending[1] === 'cs' ? 'cas' : ($pending[1] === 'cc' ? 'cac' : $pending[1]),
                    'note' => null,
                    'optional' => false,
                    'check' => $check,
                ];
                $pending = null;

                continue;
            }
            if (count($words) >= 2) {
                foreach (IngredientLineParser::parseLine($line) as $item) {
                    $rows[] = ['group' => 'À ajouter vous-même'] + $item;
                }
            }
            // un mot isolé sans quantité : reste du bandeau « À ajouter vous-même », ignoré
        }

        return [$rows, $fragments, $issues];
    }

    /** @param  array<string, string>  $m */
    private static function rowItem(string $raw, array $m): array
    {
        $unitToken = mb_strtolower(preg_replace('/\(s\)/u', '', $m['unit']));
        $check = null;
        $quantity = self::quantity($m['qty'], $unitToken, $check);
        $note = null;

        $unit = match (true) {
            $unitToken === 'g' => 'g',
            $unitToken === 'kg' => 'kg',
            $unitToken === 'ml' => 'ml',
            $unitToken === 'cl' => 'cl',
            $unitToken === 'cs' => 'cas',
            $unitToken === 'cc' => 'cac',
            default => 'piece',
        };

        if ($unitToken === 'cm') {
            $note = $m['qty'].' cm';
            $check ??= 'Quantité en cm : « 1 pièce » mise par défaut, à convertir.';
        } elseif (preg_match('/^(sachet|paquet|pot|bo[iî]te|gousse|tranche)/u', $unitToken, $w)) {
            $note = $w[1];
        }

        return [
            'group' => null,
            'raw' => trim(preg_replace('/\s+/u', ' ', substr($raw, 0, strlen($raw) - strlen($m['rest'])))),
            'name' => self::ucfirst(trim($m['name'])),
            'quantity' => $quantity,
            'unit' => $unit,
            'note' => $note,
            'optional' => false,
            'check' => $check,
        ];
    }

    /** « 150 », « ½ », « % » (½ perdu par la reconnaissance), « 12 » devant cs/cc (1½ perdu) → nombre. */
    private static function quantity(string $token, string $unit, ?string &$check): ?float
    {
        $token = trim($token);

        if (in_array($token, ['%', '#', 'Z'], true)) {
            $check = "Fraction lue « {$token} » par la reconnaissance de texte : ½ supposé, à vérifier.";

            return 0.5;
        }

        $fractions = ['½' => 0.5, '¼' => 0.25, '¾' => 0.75, '⅓' => 1 / 3, '⅔' => 2 / 3];
        foreach ($fractions as $glyph => $value) {
            if (str_contains($token, $glyph)) {
                $whole = trim(str_replace($glyph, '', $token));

                return ($whole === '' ? 0 : (float) str_replace(',', '.', $whole)) + $value;
            }
        }

        $value = (float) str_replace(',', '.', $token);

        // « 12 cs » : la fraction de « 1½ cs » est perdue, aucune recette ne demande 12 cuillères à soupe pour 2
        if (in_array($unit, ['cs', 'cc'], true) && ctype_digit($token) && $value >= 12 && $value <= 94) {
            $last = (int) $value % 10;
            if ($last === 2 || $last === 4) {
                $check = "« {$token} {$unit} » lu pour « ".intdiv((int) $value, 10).($last === 2 ? '½' : '¼')." {$unit} » : à vérifier.";

                return intdiv((int) $value, 10) + ($last === 2 ? 0.5 : 0.25);
            }
        }

        return $value;
    }

    // ---------------------------------------------------------------- étapes

    /**
     * Parcourt le texte qui suit le tableau : valeurs nutritionnelles, allergènes, puis les colonnes d'étapes.
     *
     * @param  array<int, string>  $lines
     * @param  array<int, array{source: string, title: ?string, frags: array}>  $groups
     * @param  array<int, string>  $tips
     */
    private static function flow(array $lines, array &$groups, array &$tips): void
    {
        $phase = 'nutrition';
        $tipOpen = null;
        $skipAllergens = false;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            // Encadré « conseil » : ouvert jusqu'à la fin de sa phrase
            if ($tipOpen !== null) {
                $tips[$tipOpen] .= ' '.$line;
                if (preg_match('/[.!?]$/u', $line)) {
                    $tipOpen = null;
                }

                continue;
            }

            // Fin du bloc imprimé à gauche, début des colonnes d'étapes
            if (preg_match('/^(?:montrez-nous vos plats|partagez vos photos|semaine\s+\d+\s*\|)/iu', $line)) {
                $phase = 'columns';

                continue;
            }
            if (preg_match('/^\d{3}\/\d{2}/', $line) || preg_match('/^\d$/', $line)) {
                if ($phase === 'columns' && preg_match('/^\d$/', $line)) {
                    $phase = 'tail';
                }

                continue;
            }

            // Phrases d'usage imprimées par la carte
            if (preg_match('/^(?:valeurs? nutritionnelles?|par portion\b|attention, certains ingr|d\'allergie, r[ée]f[ée]rez|allerg[eè]nes et traces)/iu', $line)) {
                continue;
            }
            if (preg_match('/^[àa] vos fourchettes/iu', $line)) {
                continue;
            }

            if (preg_match(self::TIP_MARKER, $line, $m, PREG_OFFSET_CAPTURE)) {
                $label = self::ucfirst(mb_strtolower(rtrim($m[1][0], ' :')));
                $text = trim(substr($line, $m[0][1] + strlen($m[0][0])));
                $tips[] = $label.' : '.$text;
                if (! preg_match('/[.!?]$/u', $text)) {
                    $tipOpen = array_key_last($tips);
                }

                continue;
            }

            if ($phase === 'nutrition') {
                $rest = $line;
                if (preg_match(self::NUTRITION_ROW, $line, $m)) {
                    $rest = trim(substr($line, strlen($m[0])));
                } elseif (preg_match('/^allerg[eè]nes\s*:\s*(.*)$/iu', $line, $m)) {
                    $rest = trim($m[1]);
                }
                if ($rest === '') {
                    continue;
                }

                $fragment = self::fragment($rest, true);
                if ($fragment === null) {
                    continue;
                }
                if (! $fragment['bullet'] && self::isTitle($fragment['text'])) {
                    $groups[] = ['source' => 'nutrition', 'title' => $fragment['text'], 'frags' => []];

                    continue;
                }
                if (self::lastGroup($groups, 'nutrition') === null) {
                    $groups[] = ['source' => 'nutrition', 'title' => null, 'frags' => []];
                }
                $groups[self::lastGroup($groups, 'nutrition')]['frags'][] = $fragment;

                continue;
            }

            if ($phase === 'columns') {
                $columns = self::groupsOf($groups, 'columns');

                // Titres des colonnes : tant qu'aucune ligne de texte n'a été lue
                $hasText = collect($columns)->contains(fn ($i) => $groups[$i]['frags'] !== []);
                if (! $hasText && ($titles = self::titles($line)) !== null) {
                    foreach ($titles as $t) {
                        $groups[] = ['source' => 'columns', 'title' => $t, 'frags' => []];
                    }

                    continue;
                }
                $columns = self::groupsOf($groups, 'columns');
                if ($columns === []) {
                    $groups[] = ['source' => 'columns', 'title' => null, 'frags' => []];
                    $columns = self::groupsOf($groups, 'columns');
                }

                self::assignColumns($line, $groups, $columns);

                continue;
            }

            // phase « tail » : dernières étapes, une colonne après l'autre
            $tail = self::groupsOf($groups, 'tail');
            $hasText = collect($tail)->contains(fn ($i) => $groups[$i]['frags'] !== []);
            if (! $hasText && ($titles = self::titles($line)) !== null) {
                foreach ($titles as $t) {
                    $groups[] = ['source' => 'tail', 'title' => $t, 'frags' => []];
                }

                continue;
            }
            $tail = self::groupsOf($groups, 'tail');
            if ($tail === []) {
                $groups[] = ['source' => 'tail', 'title' => null, 'frags' => []];
                $tail = self::groupsOf($groups, 'tail');
            }

            $fragment = self::fragment($line, false);
            if ($fragment === null) {
                continue;
            }
            // Deux colonnes l'une après l'autre : la seconde commence à la première puce
            $current = $tail[0];
            foreach ($tail as $index) {
                if ($groups[$index]['frags'] !== [] && $fragment['bullet'] && $index === $tail[array_key_last($tail)]) {
                    $current = $index;
                } elseif ($groups[$index]['frags'] !== []) {
                    $current = $index;
                }
            }
            if ($fragment['bullet'] && $groups[$current]['frags'] !== [] && isset($tail[array_search($current, $tail, true) + 1])) {
                $current = $tail[array_search($current, $tail, true) + 1];
            }
            $groups[$current]['frags'][] = $fragment;
        }
    }

    /** @return array<int, int> index des groupes d'une source */
    private static function groupsOf(array $groups, string $source): array
    {
        return array_keys(array_filter($groups, fn ($g) => $g['source'] === $source));
    }

    private static function lastGroup(array $groups, string $source): ?int
    {
        $indexes = self::groupsOf($groups, $source);

        return $indexes === [] ? null : $indexes[array_key_last($indexes)];
    }

    /**
     * Une ligne imprimée sur deux colonnes d'étapes : on sépare, puis on range chaque morceau dans sa colonne.
     *
     * @param  array<int, int>  $columns  index des groupes des colonnes
     */
    private static function assignColumns(string $line, array &$groups, array $columns): void
    {
        if (count($columns) === 1) {
            if (($f = self::fragment($line, false)) !== null) {
                $groups[$columns[0]]['frags'][] = $f;
            }

            return;
        }

        [$left, $right] = self::splitTwoColumns($line);

        if ($right !== null) {
            if (($f = self::fragment($left, false)) !== null) {
                $groups[$columns[0]]['frags'][] = $f;
            }
            if (($f = self::fragment($right, false)) !== null) {
                $f['bullet'] = $f['bullet'] || self::startsSentence($right);
                $groups[$columns[1]]['frags'][] = $f;
            }

            return;
        }

        // Une seule colonne sur cette ligne : celle dont la phrase n'est pas terminée
        $f = self::fragment($line, false);
        if ($f === null) {
            return;
        }
        $open = array_values(array_filter($columns, function ($i) use ($groups) {
            $last = $groups[$i]['frags'] === [] ? null : $groups[$i]['frags'][array_key_last($groups[$i]['frags'])]['text'];

            return $last !== null && ! preg_match('/[.!?)]$/u', $last);
        }));
        $target = $open !== [] ? $open[array_key_last($open)] : $columns[array_key_last($columns)];
        $groups[$target]['frags'][] = $f;
    }

    /** @return array{0: string, 1: ?string} */
    private static function splitTwoColumns(string $line): array
    {
        // 1. repère de puce au milieu de la ligne
        if (preg_match('/^(.+?\S)\s+'.self::GLYPHS.'\s+(\p{Lu}.*)$/u', $line, $m)) {
            return [$m[1], $m[2]];
        }

        $length = mb_strlen($line);

        // 2. fin de phrase suivie d'une majuscule
        if ($length >= self::SENTENCE_SPLIT_MIN && preg_match('/^(.+?[.!?])\s+(\p{Lu}.*)$/u', $line, $m)
            && mb_strlen($m[1]) <= 50 && mb_strlen($m[2]) >= 12) {
            return [$m[1], $m[2]];
        }

        // 3. deux colonnes de même largeur : on coupe au plus près du milieu
        if ($length >= self::TWO_COLUMNS_MIN) {
            $words = preg_split('/\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY);
            $best = null;
            $bestGap = PHP_INT_MAX;
            for ($i = 1; $i < count($words); $i++) {
                $left = implode(' ', array_slice($words, 0, $i));
                $right = implode(' ', array_slice($words, $i));
                if (mb_strlen($left) < 15 || mb_strlen($right) < 15) {
                    continue;
                }
                $gap = abs(mb_strlen($left) - mb_strlen($right));
                if ($gap < $bestGap) {
                    $bestGap = $gap;
                    $best = [$left, $right];
                }
            }
            if ($best !== null) {
                return $best;
            }
        }

        return [$line, null];
    }

    // ---------------------------------------------------------------- fragments, puces, titres

    /**
     * Nettoie un morceau de ligne : puce de début (« e », « ° », « + »), puce de fin (début de la colonne voisine),
     * premiers mots parasites d'une cellule du tableau.
     *
     * @return array{text: string, bullet: bool}|null
     */
    private static function fragment(string $text, bool $fromTable): ?array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return null;
        }

        $bullet = false;
        if (preg_match('/^(?:'.self::GLYPHS.'|\.)\s+(?=\p{Lu})/u', $text, $m)) {
            $bullet = true;
            $text = trim(mb_substr($text, mb_strlen($m[0])));
        }
        $text = trim(preg_replace('/\s+'.self::GLYPHS.'$/u', '', $text));

        if ($fromTable) {
            $text = self::stripGarbagePrefix($text);
        }

        $text = trim($text, " \t");
        $letters = mb_strlen(preg_replace('/[^\p{L}]/u', '', $text));
        if ($letters < 3 || preg_match('/^[^\p{L}]*$/u', $text) || ($letters <= 6 && preg_match('/\p{Ll}\p{Lu}/u', $text))) {
            return null;
        }

        return ['text' => $text, 'bullet' => $bullet];
    }

    /** « Eh dEEGUE de Effeuillez et… » → « Effeuillez et… » ; « LR thaï. Coupez… » → « thaï. Coupez… » */
    private static function stripGarbagePrefix(string $text): string
    {
        $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $count = count($tokens);

        for ($i = 0; $i < min(4, $count - 1); $i++) {
            $token = $tokens[$i];
            $mixedCase = preg_match('/\p{Ll}\p{Lu}/u', $token) === 1;
            $allCapsShort = preg_match('/^\p{Lu}{2,3}$/u', $token) === 1;
            $short = mb_strlen($token) <= 3;

            $nextClean = false;
            foreach (array_slice($tokens, $i + 1, 3) as $next) {
                if (preg_match('/^\p{Lu}\p{Ll}{4,}/u', $next)) {
                    $nextClean = true;

                    break;
                }
            }

            if ($mixedCase || $allCapsShort || ($short && $nextClean && preg_match('/^\p{Lu}?\p{Ll}{1,2}$/u', $token))) {
                continue;
            }

            return implode(' ', array_slice($tokens, $i));
        }

        return implode(' ', array_slice($tokens, min(4, $count - 1)));
    }

    /**
     * Regroupe les fragments en puces : une nouvelle puce commence avec un repère de puce, ou après une phrase
     * terminée quand le fragment suivant commence par une majuscule.
     *
     * @param  array<int, array{text: string, bullet: bool}>  $fragments
     * @return array<int, string>
     */
    private static function bullets(array $fragments): array
    {
        $bullets = [];
        $previous = null;

        foreach ($fragments as $fragment) {
            $startsNew = $bullets === []
                || $fragment['bullet']
                || ($previous !== null && preg_match('/[.!?)»]$/u', $previous) && preg_match('/^\p{Lu}/u', $fragment['text']));

            if ($startsNew) {
                $bullets[] = $fragment['text'];
            } else {
                $last = array_key_last($bullets);
                $bullets[$last] = self::glue($bullets[$last], $fragment['text']);
            }
            $previous = $fragment['text'];
        }

        return $bullets;
    }

    private static function glue(string $text, string $part): string
    {
        return preg_match('/\p{L}-$/u', $text) && preg_match('/^\p{Ll}/u', $part) ? $text.$part : $text.' '.$part;
    }

    private static function joinLines(array $lines): string
    {
        $text = '';
        foreach ($lines as $line) {
            $text = $text === '' ? $line : self::glue($text, $line);
        }

        return $text;
    }

    private static function startsSentence(string $text): bool
    {
        return preg_match('/^\p{Lu}/u', trim($text)) === 1;
    }

    /** Un titre d'étape : court, majuscule initiale, ni chiffre ni point final. */
    private static function isTitle(string $text): bool
    {
        $text = trim($text);
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $text !== '' && mb_strlen($text) <= 45 && count($words) <= 6
            && preg_match('/^\p{Lu}/u', $text) === 1
            && ! preg_match('/\d/u', $text)
            && ! preg_match('/[.,;:]$/u', $text)
            && mb_strlen(preg_replace('/[^\p{L}]/u', '', $text)) >= 4;
    }

    /**
     * Une ligne qui ne contient que des titres d'étapes (« Tout baigne », ou « Dernier coup de poêle Comment est votre curry ? »).
     *
     * @return array<int, string>|null
     */
    private static function titles(string $line): ?array
    {
        $line = trim($line);
        if (self::isTitle($line)) {
            return [$line];
        }

        if (preg_match('/^(.+?\p{Ll})\s+(\p{Lu}[^.]*)$/u', $line, $m) && self::isTitle($m[1]) && self::isTitle($m[2])) {
            return [trim($m[1]), trim($m[2])];
        }

        return null;
    }

    /** Ordre de la carte : première colonne (étape 1), colonnes du milieu et de droite, puis la deuxième rangée. */
    private static function ordered(array $groups): array
    {
        $rank = ['table' => 1, 'columns' => 2, 'nutrition' => 3, 'tail' => 4];
        $indexed = [];
        foreach ($groups as $i => $group) {
            $indexed[] = [$rank[$group['source']] ?? 9, $i, $group];
        }
        usort($indexed, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(fn ($row) => $row[2], $indexed);
    }

    // ---------------------------------------------------------------- réparations

    /** Mots collés, ponctuation sans espace, fractions perdues devant une unité. */
    private static function repair(string $text, int &$repaired): string
    {
        $text = preg_replace('/(\p{Ll}),(?=\p{L})/u', '$1, ', $text);
        $text = preg_replace('/(\p{Ll})(\d)/u', '$1 $2', $text);
        $text = preg_replace('/-y(la|le|les|l\'|du|des|un|une)\b/u', '-y $1', $text);
        $text = preg_replace('/(\p{L})\s*,\s*$/u', '$1', $text);
        $text = preg_replace('/\s,(?=\s|$)/u', '', $text);
        $text = preg_replace_callback('/, (Le|La|Les|Un|Une)(?= )/u', fn ($m) => ', '.mb_strtolower($m[1]), $text);
        $text = self::unglue($text);

        // « 4 sachet » : un quart de sachet (le pluriel manque après un nombre supérieur à 1)
        $text = preg_replace_callback('/\b4 (sachet|paquet|pot|cc|cs|cuill[eè]re|verre|pièce|gousse|boîte)\b(?!s)/u', function ($m) use (&$repaired) {
            $repaired++;

            return '¼ '.$m[1];
        }, $text);

        // « avec cc de curry » : la fraction précédant l'unité est perdue
        $text = preg_replace_callback('/\b(avec|et|ajoutez|ajoutez-y|de|d\')\s+(cc|cs)\b/iu', function ($m) use (&$repaired) {
            $repaired++;

            return $m[1].' ½ '.$m[2];
        }, $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** Mots d'usage courant des cartes : sert à redécouper les mots collés par la reconnaissance de texte. */
    private const LEXICON = ['le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'et', 'ou', 'au', 'aux', 'en', 'par', 'sur', 'sous', 'dans', 'avec', 'sans', 'pour', 'que', 'qui', 'ce', 'ces', 'se', 'sa', 'son', 'ses', 'est', 'sont', 'plus', 'puis', 'ainsi', 'petit', 'petite', 'grand', 'grande', 'filet', 'huile', 'eau', 'sel', 'poivre', 'riz', 'curry', 'thaï', 'wok', 'casserole', 'poêle', 'feu', 'moyen', 'vif', 'min', 'couvert', 'couvrez', 'mélangez', 'ajoutez', 'servez', 'pressez', 'saupoudrez', 'coupez', 'ciselez', 'râpez', 'épluchez', 'effeuillez', 'faites', 'portez', 'remettez', 'retirez', 'secouez', 'laissez', 'égouttez', 'épongez', 'réservez', 'cuire', 'cuisson', 'crevettes', 'carotte', 'carottes', 'citron', 'coriandre', 'basilic', 'gingembre', 'échalote', 'ail', 'lait', 'coco', 'sauce', 'poisson', 'plat', 'assiettes', 'gouttes', 'ensemble', 'chaque', 'personne', 'revenir', 'fondantes', 'dorées', 'tendre', 'goût', 'selon', 'votre', 'vos', 'ensuite', 'jusqu', 'wok', 'poêle', 'sauteuse', 'pâtes', 'oignon', 'tomates', 'crème', 'beurre', 'farine', 'sucre', 'four', 'plaque', 'saladier', 'bol'];

    /** « avecunpetitfilet » → « avec un petit filet » : seulement si le mot est entièrement couvert par des mots connus. */
    private static function unglue(string $text): string
    {
        return preg_replace_callback('/\p{L}{8,}/u', function (array $m) {
            $word = $m[0];
            $lower = mb_strtolower($word);
            if (in_array($lower, self::LEXICON, true)) {
                return $word;
            }

            $parts = self::segment($lower);
            if ($parts === null || count($parts) < 2 || count($parts) > 5) {
                return $word;
            }
            $out = implode(' ', $parts);

            return mb_strtoupper(mb_substr($word, 0, 1)) === mb_substr($word, 0, 1)
                ? mb_strtoupper(mb_substr($out, 0, 1)).mb_substr($out, 1) : $out;
        }, $text);
    }

    /** @return array<int, string>|null découpage en mots du lexique avec le moins de mots possible */
    private static function segment(string $word): ?array
    {
        $length = mb_strlen($word);
        $best = [0 => []];

        for ($end = 1; $end <= $length; $end++) {
            for ($start = max(0, $end - 12); $start < $end; $start++) {
                if (! isset($best[$start])) {
                    continue;
                }
                $piece = mb_substr($word, $start, $end - $start);
                if (! in_array($piece, self::LEXICON, true)) {
                    continue;
                }
                $candidate = array_merge($best[$start], [$piece]);
                if (! isset($best[$end]) || count($candidate) < count($best[$end])) {
                    $best[$end] = $candidate;
                }
            }
        }

        return $best[$length] ?? null;
    }

    // ---------------------------------------------------------------- légende

    /**
     * @param  array<int, array>  $ingredients
     * @param  array<int, string>  $legend
     * @param  array<int, string>  $steps
     * @param  array<int, string>  $issues
     * @return array<int, array>
     */
    private static function completeFromLegend(array $ingredients, array $legend, array $steps, array &$issues): array
    {
        $known = array_map(fn (array $row) => self::norm($row['name']), $ingredients);
        $text = mb_strtolower(implode(' ', $steps));

        foreach ($legend as $name) {
            $key = self::norm($name);
            if ($key === '' || in_array($key, $known, true)) {
                continue;
            }
            foreach ($known as $existing) {
                if ($existing !== '' && (str_contains($existing, $key) || str_contains($key, $existing))) {
                    continue 2;
                }
            }

            $row = [
                'group' => null,
                'raw' => $name,
                'name' => self::ucfirst($name),
                'quantity' => null,
                'unit' => null,
                'note' => null,
                'optional' => false,
                'check' => 'Ligne du tableau absorbée par la mise en page : quantité à relire sur le PDF.',
            ];

            // « Secouez le paquet de lait de coco » : l'étape donne l'unité et la quantité (1)
            if (preg_match('/\b(paquet|sachet|pot|boîte)\s+(?:de |d\')'.preg_quote(mb_strtolower($name), '/').'/u', $text, $m)) {
                $row['quantity'] = 1.0;
                $row['unit'] = 'piece';
                $row['note'] = $m[1];
                $row['check'] = "Ligne du tableau absorbée par la mise en page : « 1 {$m[1]} » retrouvé dans les étapes, à vérifier.";
            }

            $ingredients[] = $row;
            $known[] = $key;
        }

        return $ingredients;
    }

    private static function norm(string $text): string
    {
        return preg_replace('/[^a-z]/', '', mb_strtolower(\Illuminate\Support\Str::ascii($text)));
    }

    private static function ucfirst(string $text): string
    {
        $text = trim($text);

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    private static function findLine(array $lines, string $pattern, int $from = 0): ?int
    {
        for ($i = $from; $i < count($lines); $i++) {
            if ($lines[$i] !== '' && preg_match($pattern, $lines[$i])) {
                return $i;
            }
        }

        return null;
    }
}
