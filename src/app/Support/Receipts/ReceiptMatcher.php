<?php

namespace App\Support\Receipts;

use App\Models\Ingredient;
use App\Models\IngredientPack;
use App\Models\ReceiptAlias;
use App\Models\ReceiptLine;
use App\Support\UnitConversionException;
use App\Support\Units;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Rapprochement d'une ligne de ticket avec un conditionnement du référentiel :
 *   1. libellé mémorisé pour ce magasin → reconnu (automatique) ;
 *   2. libellé mémorisé dans un autre magasin → proposé ;
 *   3. ressemblance avec le nom d'un ingrédient → proposé ;
 *   4. sinon → à associer.
 */
class ReceiptMatcher
{
    /** Abréviations courantes des tickets. */
    private const ABBREVIATIONS = [
        '1/2' => 'demi', 'ECR' => 'ecreme', 'ECREM' => 'ecreme', 'PDT' => 'pomme terre', 'PDTERRE' => 'pomme terre',
        'BEUR' => 'beurre', 'FROM' => 'fromage', 'FROMAG' => 'fromage', 'CHOC' => 'chocolat', 'YAOU' => 'yaourt',
        'OEUF' => 'oeuf', 'OEUFS' => 'oeuf', 'POUL' => 'poulet', 'JAMB' => 'jambon', 'EMMENT' => 'emmental', 'EMMEN' => 'emmental',
        'LIQ' => 'liquide', 'FAR' => 'farine', 'HUIL' => 'huile', 'OLIV' => 'olive', 'CHAMPI' => 'champignon', 'CHAMP' => 'champignon',
        'STK' => 'steak', 'HACH' => 'hache', 'SUC' => 'sucre', 'CR' => 'creme', 'CRE' => 'creme', 'FRAICH' => 'fraiche',
        'FLEURETTE' => 'liquide', 'BUTTERNUT' => 'courge butternut', 'VIANDE' => 'boeuf', 'PDTS' => 'pomme terre', 'EPAISSE' => 'epaisse fraiche', 'SPAGHETTI' => 'pate spaghetti', 'PENNE' => 'pate penne',
        'TORTI' => 'pate', 'COQUILLETTES' => 'pate', 'FUSILLI' => 'pate', 'EMMENTAL' => 'emmental', 'RAPE' => 'rape',
    ];

    /** Mots sans intérêt pour reconnaître un produit. */
    private const NOISE = [
        'bio', 'mdd', 'uht', 'marque', 'repere', 'eco', 'lot', 'pack', 'sachet', 'barquette', 'barq', 'boite', 'bte', 'brique',
        'bouteille', 'btl', 'vrac', 'kg', 'cl', 'ml', 'france', 'origine', 'cat', 'pce', 'pcs', 'piece', 'pieces', 'plein', 'air',
        'sol', 'extra', 'fin', 'fins', 'classique', 'leclerc', 'lidl', 'carrefour', 'auchan', 'casino', 'format', 'familial',
        'pot', 'filet', 'botte', 'les', 'des', 'aux', 'avec', 'sans', 'pour', 'the', 'and',
    ];

    private const STOPWORDS = ['de', 'du', 'des', 'la', 'le', 'les', 'en', 'au', 'aux', 'et', 'pour', 'a', 'd', 'l'];

    /**
     * Mots qui désignent un produit transformé : « Compote pomme poire » n'est ni une pomme ni une poire.
     * Un ingrédient n'est proposé que si son nom contient aussi ce mot.
     */
    private const PRODUCT_WORDS = [
        'compote', 'jus', 'confiture', 'biscuit', 'chips', 'soupe', 'veloute', 'sauce', 'crouton', 'boisson', 'sirop',
        'gateau', 'tarte', 'pizza', 'quiche', 'dessert', 'menu', 'assaisonn', 'brisee', 'feuilletee', 'sablee', 'knack',
        'nugget', 'cordon', 'lasagne', 'ravioli', 'capsule', 'bonbon', 'barre', 'cereale', 'petit-suisse', 'suisse',
    ];

    /** Préfixes trompeurs : « poire » n'est pas le début de « poireau ». */
    private const NOT_PREFIX = ['poire' => 'poireau', 'pate' => 'patate', 'rose' => 'rosette'];

    /** Mots accentués à ne pas confondre une fois les accents retirés (pâté ≠ pâtes). */
    private const ACCENTED = ['pâtés' => 'terrine', 'pâté' => 'terrine'];

    public const MIN_SCORE = 0.66;

    /** @var Collection<int, array{ingredient: Ingredient, tokens: array<int, string>}> */
    private Collection $index;

    public function __construct(?Collection $ingredients = null)
    {
        $ingredients ??= Ingredient::with('packs')->get();

        // Un nom comme « Prune, quetsche » ou « Salade (laitue, batavia) » donne plusieurs variantes reconnaissables.
        $this->index = $ingredients->flatMap(fn (Ingredient $i) => collect($this->nameVariants($i->name))
            ->map(fn (string $variant) => ['ingredient' => $i, 'tokens' => $this->ingredientTokens($variant)]))
            ->filter(fn ($row) => $row['tokens'] !== [])->values();
    }

    /** @return array{status: string, pack: ?IngredientPack, ingredient?: ?Ingredient} */
    public function match(string $normalized, ?int $storeId, bool $weighted, ?string $raw = null): array
    {
        if ($storeId !== null) {
            $alias = ReceiptAlias::with('pack.ingredient')->where('store_id', $storeId)->where('normalized_label', $normalized)->first();
            if ($alias) {
                return $alias->is_ignored
                    ? ['status' => ReceiptLine::STATUS_IGNORED, 'pack' => null]
                    : ['status' => ReceiptLine::STATUS_KNOWN, 'pack' => $alias->pack];
            }
        }

        $elsewhere = ReceiptAlias::with('pack.ingredient')->where('normalized_label', $normalized)
            ->where('is_ignored', false)->whereNotNull('ingredient_pack_id')->orderByDesc('hits')->first();
        if ($elsewhere) {
            return ['status' => ReceiptLine::STATUS_SUGGESTED, 'pack' => $elsewhere->pack];
        }

        $ingredient = $this->bestIngredient($this->protectAccents($normalized, $raw));
        if ($ingredient) {
            // Quantité lue absente du référentiel (« Oeufs x30 ») : on propose l'ingrédient,
            // le conditionnement sera créé à la validation.
            $quantity = $weighted ? null : self::labelQuantity($normalized, $ingredient);
            $exact = $quantity === null || $ingredient->packs->contains(fn ($p) => abs($p->quantity - $quantity) <= $p->quantity * 0.02);
            $pack = $exact ? $this->choosePack($ingredient, $normalized, $weighted) : null;

            return ['status' => ReceiptLine::STATUS_SUGGESTED, 'pack' => $pack, 'ingredient' => $ingredient];
        }

        return ['status' => ReceiptLine::STATUS_UNKNOWN, 'pack' => null];
    }

    public function bestIngredient(string $normalized): ?Ingredient
    {
        $labelTokens = $this->labelTokens($normalized);
        if ($labelTokens === []) {
            return null;
        }

        $productWords = array_values(array_filter($labelTokens, fn ($t) => $this->isProductWord($t)));

        $best = null;
        foreach ($this->index as $row) {
            foreach ($productWords as $word) {
                foreach ($row['tokens'] as $token) {
                    if ($this->tokensMatch($word, $token)) {
                        continue 2;
                    }
                }

                continue 2;
            }

            $matched = 0;
            foreach ($row['tokens'] as $token) {
                foreach ($labelTokens as $candidate) {
                    if ($this->tokensMatch($candidate, $token)) {
                        $matched++;

                        continue 2;
                    }
                }
            }

            $score = $matched / count($row['tokens']);
            if ($matched === 0 || $score < self::MIN_SCORE) {
                continue;
            }

            // Le plus de mots reconnus d'abord (« Tomates pelées » → tomates pelées en conserve, pas tomate)
            $rank = [$matched, $score, count($row['tokens'])];
            if ($best === null || $rank > $best['rank']) {
                $best = ['rank' => $rank, 'ingredient' => $row['ingredient']];
            }
        }

        return $best['ingredient'] ?? null;
    }

    /**
     * Conditionnement le plus probable : vrac pour une pesée, sinon celui dont la quantité
     * correspond à celle écrite sur le ticket (« 1L », « 6X1L », « X12 »…), sinon le premier.
     */
    public function choosePack(Ingredient $ingredient, string $normalized, bool $weighted): ?IngredientPack
    {
        $packs = $ingredient->packs;
        if ($packs->isEmpty()) {
            return null;
        }

        if ($weighted) {
            return $packs->firstWhere('is_bulk', true) ?? $packs->first();
        }

        $quantity = self::labelQuantity($normalized, $ingredient);
        if ($quantity !== null) {
            $exact = $packs->first(fn (IngredientPack $p) => abs($p->quantity - $quantity) <= $p->quantity * 0.02);
            if ($exact) {
                return $exact;
            }

            return $packs->sortBy(fn (IngredientPack $p) => abs($p->quantity - $quantity))->first();
        }

        // Article à l'unité sans quantité écrite : jamais le vrac au kilo (le prix payé est celui d'une pièce)
        return $packs->firstWhere('is_bulk', false);
    }

    /**
     * Conditionnement à créer d'après le ticket quand le référentiel n'en a pas d'équivalent :
     * vrac au kilo pour une pesée, quantité du libellé (« 2kg », « x20 », « 1p »), sinon une pièce.
     * @return array{label: string, quantity: float, is_bulk: bool}|null
     */
    public static function ticketPack(Ingredient $ingredient, string $normalized, bool $weighted): ?array
    {
        if ($weighted) {
            return $ingredient->base_unit === 'g' ? ['label' => 'Vrac au kg', 'quantity' => 1000.0, 'is_bulk' => true] : null;
        }

        // Nombre de pièces écrit (« x20 », « 1p ») sans poids ni volume : conditionnement en pièces
        $count = preg_match('/\\d\\s*(KG|G|L|CL|ML)\\b/', $normalized) ? null : self::pieceCount($normalized);
        $quantity = self::labelQuantity($normalized, $ingredient);

        if ($quantity === null && $count === null) {
            $count = 1;
        }

        if ($count !== null) {
            try {
                $quantity = $ingredient->base_unit === 'piece' ? (float) $count : Units::toBase($count, 'piece', $ingredient);
            } catch (UnitConversionException) {
                return null;
            }

            return ['label' => ($count === 1 ? 'Pièce' : $count.' pièces').' (ticket)', 'quantity' => $quantity, 'is_bulk' => false];
        }

        return ['label' => Str::ucfirst(Units::format($quantity, $ingredient->base_unit)).' (ticket)', 'quantity' => $quantity, 'is_bulk' => false];
    }

    /** Nombre de pièces écrit dans le libellé : « X20 », « 20X », « 1P ». */
    private static function pieceCount(string $normalized): ?int
    {
        if (preg_match('/(?:\\bX\\s*(\\d{1,2})\\b|\\b(\\d{1,2})\\s*X\\b(?!\\s*\\d)|\\b(\\d{1,2})\\s*P\\b)/', $normalized, $m)) {
            $value = (int) ($m[1] !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : $m[3]));

            return $value > 0 ? $value : null;
        }

        return null;
    }

    /** Quantité écrite dans le libellé, exprimée dans l'unité de base de l'ingrédient (null si absente ou incompatible). */
    public static function labelQuantity(string $normalized, Ingredient $ingredient): ?float
    {
        $units = ['KG' => ['g', 1000], 'G' => ['g', 1], 'L' => ['ml', 1000], 'CL' => ['ml', 10], 'ML' => ['ml', 1]];
        $value = null;
        $unit = null;

        if (preg_match('/\b(\d{1,3})\s*X\s*(\d+(?:[.,]\d+)?)\s*(KG|G|L|CL|ML)\b/', $normalized, $m)) {
            $value = (int) $m[1] * (float) str_replace(',', '.', $m[2]) * $units[$m[3]][1];
            $unit = $units[$m[3]][0];
        } elseif (preg_match('/\b(\d+(?:[.,]\d+)?)\s*(KG|G|L|CL|ML)\b/', $normalized, $m)) {
            $value = (float) str_replace(',', '.', $m[1]) * $units[$m[2]][1];
            $unit = $units[$m[2]][0];
        } elseif (preg_match('/(?:\bX\s*(\d{1,2})\b|\b(\d{1,2})\s*X\b(?!\s*\d)|\b(\d{1,2})\s*P\b)/', $normalized, $m)) {
            $value = (float) ($m[1] !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : $m[3]));
            $unit = 'piece';
        }

        if ($value === null || $value <= 0) {
            return null;
        }

        if ($unit === $ingredient->base_unit) {
            return $value;
        }

        try {
            return Units::toBase($value, $unit, $ingredient);
        } catch (UnitConversionException) {
            return null;
        }
    }

    /** « Pâtes (spaghetti, penne…) » → [« Pâtes », « spaghetti », « penne »] */
    private function nameVariants(string $name): array
    {
        $main = trim(preg_replace('/\(.*?\)/u', ' ', $name));
        $variants = array_merge([$main], preg_split('/\s*,\s*/u', $main));

        if (preg_match('/\((.*?)\)/u', $name, $m)) {
            $variants = array_merge($variants, preg_split('/\s*,\s*/u', str_replace('…', '', $m[1])));
        }

        return array_values(array_unique(array_filter(array_map('trim', $variants))));
    }

    /** Remplace les mots accentués ambigus du libellé d'origine avant comparaison. */
    private function protectAccents(string $normalized, ?string $raw): string
    {
        if ($raw === null) {
            return $normalized;
        }

        $lower = mb_strtolower($raw);
        foreach (self::ACCENTED as $word => $replacement) {
            if (preg_match('/(?<!\pL)'.preg_quote($word, '/').'(?!\pL)/u', $lower)) {
                $normalized = preg_replace('/\bPATES?\b/', strtoupper($replacement), $normalized);
            }
        }

        return $normalized;
    }

    /** @return array<int, string> */
    private function ingredientTokens(string $name): array
    {
        $words = explode(' ', Str::slug(str_replace(["'", '’'], ' ', $name), ' '));

        return array_values(array_unique(array_filter(array_map(
            fn ($w) => $this->singular($w),
            array_filter($words, fn ($w) => strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true) && ! ctype_digit($w))
        ))));
    }

    /** @return array<int, string> */
    private function labelTokens(string $normalized): array
    {
        $tokens = [];
        foreach (explode(' ', $normalized) as $raw) {
            $expanded = self::ABBREVIATIONS[$raw] ?? null;
            foreach (explode(' ', $expanded ?? Str::lower($raw)) as $word) {
                $word = trim($word, '.,/');
                if (strlen($word) < 3 || preg_match('/\d/', $word) || in_array($word, self::NOISE, true)) {
                    continue;
                }
                $tokens[] = $this->singular($word);
            }
        }

        return array_values(array_unique($tokens));
    }

    private function isProductWord(string $token): bool
    {
        foreach (self::PRODUCT_WORDS as $word) {
            if (str_starts_with($token, $word)) {
                return true;
            }
        }

        return false;
    }

    private function tokensMatch(string $candidate, string $token): bool
    {
        foreach (self::NOT_PREFIX as $short => $long) {
            if (($candidate === $short && str_starts_with($token, $long)) || ($token === $short && str_starts_with($candidate, $long))) {
                return false;
            }
        }

        return $candidate === $token
            || (strlen($candidate) >= 4 && str_starts_with($token, $candidate))
            || (strlen($token) >= 4 && str_starts_with($candidate, $token));
    }

    private function singular(string $word): string
    {
        return strlen($word) > 4 && in_array(substr($word, -1), ['s', 'x'], true) ? substr($word, 0, -1) : $word;
    }
}
