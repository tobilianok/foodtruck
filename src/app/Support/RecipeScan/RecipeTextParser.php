<?php

namespace App\Support\RecipeScan;

use Illuminate\Support\Str;

/**
 * Lit le texte d'une fiche de recette (contenu d'un document Paperless) :
 * titre, nombre de personnes, temps, ingrédients, étapes, conseil, source.
 *
 * Le lecteur est volontairement simple et prévisible : tout ce qu'il n'est pas sûr d'avoir compris
 * est signalé dans « issues », et la recette n'est alors pas publiée toute seule.
 */
class RecipeTextParser
{
    /**
     * @return array{
     *   title: string, description: ?string, category: string, yield_quantity: ?float, yield_unit: string,
     *   prep_minutes: ?int, cook_minutes: ?int, rest_minutes: ?int, difficulty: string, source: ?string,
     *   tags: array<int, string>, ingredients: array<int, array>, steps: array<int, array{body: string, timer: ?int}>,
     *   tip: ?string, issues: array<int, string>, layout: string
     * }
     */
    public static function parse(string $raw, ?string $titleHint = null): array
    {
        $clean = TextCleaner::clean($raw);

        // Fiches de kits repas (HelloFresh) : texte à colonnes entrelacées, lu par un lecteur dédié
        if (MealKitSheetParser::detects($clean['text'])) {
            return MealKitSheetParser::parse($clean, $titleHint);
        }

        $issues = [];

        $meta = ['prep' => null, 'cook' => null, 'rest' => null, 'yield' => null];
        $head = [];
        $ingredientLines = [];
        $stepLines = [];
        $tipLines = [];
        $tipTitle = null;
        $state = 'head';
        $foundIngredients = false;
        $foundSteps = false;

        $lines = explode("\n", $clean['text']);
        for ($i = 0; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            $key = self::key($line);

            if ($line !== '' && $state !== 'steps' && ($m = self::metadata($line, $lines, $i)) !== null) {
                [$name, $value, $consumed] = $m;
                $meta[$name] ??= $value;
                $i += $consumed;

                continue;
            }

            if ($line !== '' && self::isHeading($key, 'ingredients')) {
                $state = 'ingredients';
                $foundIngredients = true;

                continue;
            }
            if ($line !== '' && self::isHeading($key, 'steps')) {
                $state = 'steps';
                $foundSteps = true;

                continue;
            }
            if ($line !== '' && self::isHeading($key, 'tip')) {
                $tipTitle = preg_match('/conseils?\s+de\s+(.+?)[\s.:]*$/iu', $line, $t)
                    ? 'Conseil de '.self::sentence($t[1]) : 'Conseil';
                if ($state !== 'steps') {
                    $state = 'tip';
                }

                continue;
            }

            match ($state) {
                'head' => $head[] = $line,
                'ingredients' => $ingredientLines[] = $line,
                'steps' => $stepLines[] = $line,
                'tip' => $tipLines[] = $line,
            };
        }

        // Titre : celui de Paperless en priorité, sinon l'en-tête imprimé, sinon le grand titre de la page
        $title = trim((string) $titleHint) !== '' ? trim((string) $titleHint) : ($clean['title'] ?: null);
        if ($title === null) {
            $heading = collect($head)->first(fn ($l) => $l !== '' && mb_strlen($l) >= 4);
            $title = $heading ? self::sentence($heading) : null;
        }
        $title = $title === null ? null : trim(preg_replace('/\s*\|\s*.*$/u', '', $title));
        if ($title === null || $title === '') {
            $title = 'Recette sans titre';
            $issues[] = 'Titre non trouvé.';
        }
        $title = mb_substr($title, 0, 120);

        // Ingrédients
        $ingredients = [];
        if (! $foundIngredients) {
            $issues[] = 'Liste d\'ingrédients introuvable.';
        } else {
            $ingredients = IngredientLineParser::parseSection($ingredientLines);
            if ($ingredients === []) {
                $issues[] = 'Aucun ingrédient lu dans la fiche.';
            }
        }

        // Étapes
        $steps = [];
        $tip = null;
        $layout = 'none';
        if (! $foundSteps) {
            $issues[] = 'Étapes de préparation introuvables.';
        } else {
            $extracted = StepExtractor::extract($stepLines);
            $issues = array_merge($issues, $extracted['issues']);
            $layout = $extracted['layout'];
            $tip = $extracted['tip'];
            $steps = array_map(fn (string $body) => ['body' => mb_substr($body, 0, 2000), 'timer' => self::timer($body)], $extracted['steps']);
        }

        if ($tipLines !== []) {
            $tip = trim(preg_replace('/^[«“"]\s*|\s*[»”"]$/u', '', implode(' ', array_filter(array_map('trim', $tipLines)))));
        }

        $description = $tip ? mb_substr((($tipTitle ?? 'Conseil').' : ').$tip, 0, 2000) : null;

        // Nombre de personnes
        $yield = $meta['yield'];
        if ($yield === null && preg_match('/pour\s+(\d+(?:[.,]\d+)?)\s*(?:personnes?|pers\.?|convives?|couverts?)/iu', $clean['text'], $m)) {
            $yield = (float) str_replace(',', '.', $m[1]);
        }
        if ($yield === null) {
            $issues[] = 'Nombre de personnes non trouvé : 4 par défaut.';
        }

        $author = $clean['author'];
        $source = null;
        if ($author || $clean['domain']) {
            $source = trim(($author ?: '').($author && $clean['domain'] ? ' ('.$clean['domain'].')' : ($clean['domain'] ?: '')));
        }

        $haystack = Str::lower($title.' '.($clean['title'] ?? ''));

        return [
            'title' => $title,
            'description' => $description,
            'category' => self::category($haystack),
            'yield_quantity' => $yield,
            'yield_unit' => 'personnes',
            'prep_minutes' => $meta['prep'],
            'cook_minutes' => $meta['cook'],
            'rest_minutes' => $meta['rest'],
            'difficulty' => 'facile',
            'source' => $source ? mb_substr($source, 0, 255) : null,
            'tags' => self::tags($haystack),
            'ingredients' => $ingredients,
            'steps' => $steps,
            'tip' => $tip,
            'issues' => array_values(array_unique($issues)),
            'layout' => $layout,
        ];
    }

    /** « 1 h 30 », « 1h30 », « 45 min », « 2 heures » → minutes */
    public static function duration(string $text): ?int
    {
        $text = Str::lower(trim($text));
        $minutes = 0;
        $found = false;

        if (preg_match('/(\d+)\s*(?:h|heures?)\s*(\d{1,2})?/u', $text, $m)) {
            $minutes += (int) $m[1] * 60 + (int) ($m[2] ?? 0);
            $found = true;
        } elseif (preg_match('/(\d+)\s*(?:min|minutes?|mn)\b/u', $text, $m)) {
            $minutes += (int) $m[1];
            $found = true;
        }

        return $found ? $minutes : null;
    }

    /** Minuteur d'une étape : seulement si une seule durée y est citée. */
    public static function timer(string $body): ?int
    {
        preg_match_all('/(\d+)\s*(?:min(?:utes?)?|mn)\b/iu', $body, $m);
        if (count($m[1]) !== 1) {
            return null;
        }

        $minutes = (int) $m[1][0];

        return $minutes >= 1 && $minutes <= 2880 ? $minutes : null;
    }

    /** @return array{0: string, 1: int|float|null, 2: int}|null nom, valeur, lignes suivantes consommées */
    private static function metadata(string $line, array $lines, int $i): ?array
    {
        $key = self::key($line);
        $labels = [
            'prep' => '/^temps de preparation\b|^preparation\s*:|^prep\b\s*:/',
            'cook' => '/^temps de cuisson\b|^cuisson\s*:/',
            'rest' => '/^temps de repos\b|^repos\s*:|^temps de pause\b/',
            'yield' => '/^(?:nombre de (?:couverts|personnes|parts|portions)|nb (?:de )?(?:personnes|parts)|portions?|couverts|pour \d+ (?:personnes|parts))\b|^personnes\s*:/',
        ];

        foreach ($labels as $name => $pattern) {
            if (! preg_match($pattern, $key)) {
                continue;
            }

            // Valeur sur la même ligne (« Préparation : 15 min ») ou sur la suivante
            $candidates = [$line, $lines[$i + 1] ?? ''];
            $consumed = 0;
            $value = null;

            foreach ($candidates as $n => $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '') {
                    continue;
                }
                $value = $name === 'yield' ? self::yieldNumber($candidate) : self::duration($candidate);
                if ($value !== null) {
                    $consumed = $n;

                    break;
                }
            }

            if ($value === null) {
                // Étiquette sans valeur lisible : on la saute quand même pour ne pas polluer les sections
                return [$name, null, 0];
            }

            return [$name, $value, $consumed];
        }

        return null;
    }

    private static function yieldNumber(string $text): ?float
    {
        return preg_match('/(\d+(?:[.,]\d+)?)/u', $text, $m) ? (float) str_replace(',', '.', $m[1]) : null;
    }

    private static function isHeading(string $key, string $kind): bool
    {
        if ($key === '' || mb_strlen($key) > 40 || preg_match('/\d/', $key)) {
            return false;
        }

        return match ($kind) {
            'ingredients' => preg_match('/^(?:les )?ingredients?(?: necessaires?)?$/', $key) === 1,
            'steps' => preg_match('/^(?:la )?(?:recette|preparation|realisation|etapes?|instructions?|methode|deroule|progression)(?: de la recette| pas a pas)?$|^pas a pas$|^la recette pas a pas$/', $key) === 1,
            'tip' => preg_match('/^(?:le |la |les |l )?(?:conseils?|astuces?|trucs?|petit plus|bon a savoir|notes?)(?: de .+)?$/', $key) === 1,
            default => false,
        };
    }

    /** Clé de comparaison : minuscules, sans accents ni ponctuation finale. */
    private static function key(string $line): string
    {
        $key = Str::lower(Str::ascii(trim($line)));
        $key = preg_replace("/['’]/", ' ', $key);
        $key = preg_replace('/[\.\:…!\s]+$/', '', $key);

        return trim(preg_replace('/\s+/', ' ', $key));
    }

    public static function sentence(string $text): string
    {
        $text = trim($text, " .:");

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_strtolower(mb_substr($text, 1));
    }

    public static function category(string $haystack): string
    {
        $haystack = Str::ascii($haystack);

        return match (true) {
            (bool) preg_match('/\b(gateau|cake|brownie|cookies?|muffins?|madeleines?|clafoutis|crumble|flan|mousse|tiramisu|panna cotta|tarte (?:aux|au|a la) (?:pomme|poire|fruits?|chocolat|citron|fraise)|dessert|glace|sorbet|compote|creme dessert|pate a tartiner)\b/', $haystack) => 'dessert',
            (bool) preg_match('/\b(salade|veloute|soupe|potage|gaspacho|carpaccio|tartare)\b/', $haystack) => 'entree',
            (bool) preg_match('/\b(puree|riz pilaf|accompagnement|frites)\b/', $haystack) => 'accompagnement',
            (bool) preg_match('/\b(smoothie|jus|boisson|limonade|cocktail)\b/', $haystack) => 'boisson',
            (bool) preg_match('/\b(granola|porridge|pancakes?|petit[- ]dejeuner)\b/', $haystack) => 'petit-dejeuner',
            default => 'plat',
        };
    }

    /** @return array<int, string> */
    public static function tags(string $haystack): array
    {
        $haystack = Str::ascii($haystack);
        $tags = [];

        if (preg_match('/vegetalien|vegan/', $haystack)) {
            $tags[] = 'vegan';
            $tags[] = 'veggy';
        } elseif (preg_match('/vegetarien|veggie|veggy/', $haystack)) {
            $tags[] = 'veggy';
        }

        return $tags;
    }
}
