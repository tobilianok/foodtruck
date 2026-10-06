<?php

namespace App\Support\RecipeScan;

use App\Models\Ingredient;
use App\Models\RecipeAlias;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Rapproche un libellé de fiche (« comté 24 mois râpé », « crème épaisse ») d'un ingrédient du référentiel.
 *
 * Ordre : libellé déjà validé par quelqu'un (alias appris) → synonymes courants → nom identique →
 * l'ingrédient est contenu dans le libellé (« comté » dans « comté 24 mois râpé ») → le libellé est contenu
 * dans l'ingrédient (« sauge » dans « Sauge fraîche ») → lecture approximative (« courgeties » → Courgette, à confirmer).
 * En cas d'égalité, aucun choix n'est fait : la fiche est relue.
 */
class IngredientMatcher
{
    /** Libellé normalisé → nom exact de l'ingrédient du référentiel (ignoré s'il n'existe pas). */
    public const SYNONYMS = [
        'oignon' => 'Oignon jaune',
        'creme epaisse' => 'Crème fraîche épaisse',
        'creme fraiche' => 'Crème fraîche épaisse',
        'creme fraiche epaisse' => 'Crème fraîche épaisse',
        'creme' => 'Crème liquide entière',
        'creme liquide' => 'Crème liquide entière',
        'creme fleurette' => 'Crème liquide entière',
        'sel' => 'Sel fin',
        'poivre' => 'Poivre noir moulu',
        'lait' => 'Lait demi-écrémé',
        'beurre' => 'Beurre doux',
        'farine' => 'Farine de blé T55',
        'sucre' => 'Sucre en poudre',
        'riz' => 'Riz long',
        'jaune oeuf' => 'Oeufs',
        'blanc oeuf' => 'Oeufs',
        'emmental' => 'Emmental râpé',
        'lardons' => 'Lardons fumés',
        'tomates pelees' => 'Tomates pelées en conserve',
        'tomate concassee' => 'Tomates pelées en conserve',
        'pate courte' => 'Pâtes (spaghetti, penne…)',
        'pate longue' => 'Pâtes (spaghetti, penne…)',
        'macaroni' => 'Pâtes (spaghetti, penne…)',
        'mini macaroni' => 'Pâtes (spaghetti, penne…)',
        'coquillette' => 'Pâtes (spaghetti, penne…)',
        'torsade' => 'Pâtes (spaghetti, penne…)',
        'tagliatelle' => 'Pâtes (spaghetti, penne…)',
        'fusilli' => 'Pâtes (spaghetti, penne…)',
        'farfalle' => 'Pâtes (spaghetti, penne…)',
        // v0.16.4 : noms des cartes de kits
        'blanc poulet' => 'Filet de poulet',
        'escalope poulet' => 'Filet de poulet',
    ];

    private const STOPWORDS = ['de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'au', 'aux', 'a', 'en', 'et', 'un', 'une', 'ou', 'pour', 'avec', 'sans', 'ici', 'sur', 'dans'];

    /** Mots qui décrivent le produit sans le changer : ignorés quand on cherche le libellé dans un nom d'ingrédient. */
    private const DESCRIPTORS = ['rape', 'rapee', 'emince', 'emincee', 'hache', 'hachee', 'coupe', 'coupee', 'fondu', 'fondue', 'mou', 'molle', 'pele', 'lave', 'cuit', 'cuite', 'cru', 'crue', 'frais', 'fraiche', 'moulu', 'moulue', 'entier', 'entiere', 'mur', 'mure', 'gros', 'petit', 'moyen', 'bio', 'vierge', 'extra', 'pressee', 'premiere', 'froid', 'fermier', 'label', 'affine', 'affinee', 'vieux', 'jeune', 'moi', 'mois', 'an'];

    /** Couleurs : ignorées seulement si l'ingrédient n'en précise pas (« poivron vert » → Poivron), jamais si elles le contredisent (« vin blanc » ≠ Vin rouge). */
    private const COLORS = ['vert', 'verte', 'rouge', 'jaune', 'orange', 'blanc', 'blanche', 'noir', 'noire', 'violet', 'violette'];

    /** @var Collection<int, array{ingredient: Ingredient, tokens: array<int, string>}> */
    private Collection $index;

    /** @var Collection<string, Ingredient> */
    private Collection $bySlug;

    public function __construct(?Collection $ingredients = null)
    {
        $ingredients ??= Ingredient::all();
        $this->bySlug = $ingredients->keyBy(fn (Ingredient $i) => Str::slug($i->name));
        $this->index = $ingredients->flatMap(fn (Ingredient $i) => collect(self::nameVariants($i->name))
            ->map(fn (string $variant) => ['ingredient' => $i, 'tokens' => self::tokens($variant)]))
            ->filter(fn (array $row) => $row['tokens'] !== [])->values();
    }

    /**
     * @return array{ingredient: ?Ingredient, via: ?string, candidates: array<int, string>}
     */
    public function match(string $label): array
    {
        $tokens = self::tokens($label);
        if ($tokens === []) {
            return ['ingredient' => null, 'via' => null, 'candidates' => []];
        }
        $key = implode(' ', $tokens);

        $alias = RecipeAlias::with('ingredient')->where('normalized_label', $key)->first();
        if ($alias?->ingredient) {
            return ['ingredient' => $alias->ingredient, 'via' => 'alias', 'candidates' => []];
        }

        if (isset(self::SYNONYMS[$key]) && ($syn = $this->bySlug->get(Str::slug(self::SYNONYMS[$key])))) {
            return ['ingredient' => $syn, 'via' => 'synonyme', 'candidates' => []];
        }

        $exact = $this->index->filter(fn ($row) => $row['tokens'] === $tokens)->pluck('ingredient')->unique('id');
        if ($exact->count() === 1) {
            return ['ingredient' => $exact->first(), 'via' => 'nom', 'candidates' => []];
        }

        // L'ingrédient est contenu dans le libellé, et ce qui reste n'est qu'une précision (« râpé », « 24 mois ») :
        // le plus précis (le plus de mots) l'emporte. « Pâte à tartiner » ne doit pas devenir « Pâtes ».
        $ignorable = array_map(fn ($d) => self::singular($d), self::DESCRIPTORS);
        $colors = array_map(fn ($c) => self::singular($c), self::COLORS);
        $contained = $this->index->filter(fn ($row) => array_diff($row['tokens'], $tokens) === []
                && collect(array_diff($tokens, $row['tokens']))->every(fn ($t) => ctype_digit($t) || in_array($t, $ignorable, true)
                    || (in_array($t, $colors, true) && array_intersect($row['tokens'], $colors) === [])))
            ->groupBy(fn ($row) => count($row['tokens']))->sortKeysDesc()->first();
        $best = $contained?->pluck('ingredient')->unique('id');
        if ($best && $best->count() === 1) {
            return ['ingredient' => $best->first(), 'via' => 'nom', 'candidates' => []];
        }

        // Le libellé est contenu dans l'ingrédient : « sauge » → « Sauge fraîche »
        $significant = array_values(array_diff($tokens, $ignorable));
        if ($significant !== []) {
            $partial = $this->index->filter(fn ($row) => array_diff($significant, $row['tokens']) === [])->pluck('ingredient')->unique('id');
            if ($partial->count() === 1) {
                return ['ingredient' => $partial->first(), 'via' => 'partiel', 'candidates' => []];
            }
        }

        // Lecture approximative du scan (« courgeties ») : une lettre ou deux de différence avec un seul ingrédient.
        // Proposé mais toujours à confirmer à la relecture (via « approchant »).
        if (($near = $this->approximate($significant !== [] ? $significant : $tokens)) !== null) {
            return ['ingredient' => $near, 'via' => 'approchant', 'candidates' => [$near->name]];
        }

        return ['ingredient' => null, 'via' => null, 'candidates' => $this->candidates($tokens)];
    }

    /** Un seul ingrédient dont chaque mot est à une ou deux lettres près d'un mot du libellé. */
    private function approximate(array $tokens): ?Ingredient
    {
        $close = function (string $a, string $b): bool {
            $length = min(strlen($a), strlen($b));
            $allowed = $length >= 8 ? 2 : ($length >= 5 ? 1 : 0);

            return $allowed > 0 && levenshtein($a, $b) <= $allowed;
        };

        $found = $this->index->filter(function ($row) use ($tokens, $close) {
            if (count($row['tokens']) !== count($tokens)) {
                return false;
            }
            foreach ($row['tokens'] as $i => $token) {
                if ($token !== $tokens[$i] && ! $close($token, $tokens[$i])) {
                    return false;
                }
            }

            return $row['tokens'] !== $tokens;
        })->pluck('ingredient')->unique('id');

        return $found->count() === 1 ? $found->first() : null;
    }

    /** Libellé normalisé servant de clé d'alias. */
    public static function key(string $label): string
    {
        return implode(' ', self::tokens($label));
    }

    /** @return array<int, string> */
    public static function tokens(string $text): array
    {
        $text = Str::lower(Str::ascii($text));
        $text = preg_replace("/['’`]/", ' ', $text);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
        $words = array_filter(explode(' ', $text), fn ($w) => $w !== '' && ! in_array($w, self::STOPWORDS, true));

        return array_values(array_map(fn ($w) => self::singular($w), $words));
    }

    private static function singular(string $word): string
    {
        return strlen($word) > 3 && preg_match('/[sx]$/', $word) ? substr($word, 0, -1) : $word;
    }

    /** « Pâtes (spaghetti, penne…) » → [« Pâtes », « spaghetti », « penne »] */
    private static function nameVariants(string $name): array
    {
        $main = trim(preg_replace('/\(.*?\)/u', ' ', $name));
        $variants = array_merge([$main], preg_split('/\s*,\s*/u', $main));

        if (preg_match('/\((.*?)\)/u', $name, $m)) {
            $variants = array_merge($variants, preg_split('/\s*,\s*/u', str_replace('…', '', $m[1])));
        }

        return array_values(array_unique(array_filter(array_map('trim', $variants))));
    }

    /** @return array<int, string> noms proches, pour aider à choisir à la relecture */
    private function candidates(array $tokens): array
    {
        // Une couleur ou une précision en commun ne suffit pas à proposer un ingrédient (« curry rouge » ≠ Oignon rouge)
        $weak = array_map(fn ($w) => self::singular($w), array_merge(self::COLORS, self::DESCRIPTORS));

        return $this->index
            ->map(fn ($row) => ['ingredient' => $row['ingredient'], 'score' => collect(array_intersect($row['tokens'], $tokens))
                ->sum(fn ($t) => in_array($t, $weak, true) ? 0.1 : 1)])
            ->filter(fn ($row) => $row['score'] >= 1)
            ->sortByDesc('score')
            ->pluck('ingredient.name')->unique()->take(5)->values()->all();
    }
}
