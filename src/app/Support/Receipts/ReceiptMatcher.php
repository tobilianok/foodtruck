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
        'FLEURETTE' => 'liquide', 'EPAISSE' => 'epaisse fraiche', 'SPAGHETTI' => 'pate spaghetti', 'PENNE' => 'pate penne',
        'TORTI' => 'pate', 'COQUILLETTES' => 'pate', 'FUSILLI' => 'pate', 'EMMENTAL' => 'emmental', 'RAPE' => 'rape',
    ];

    /** Mots sans intérêt pour reconnaître un produit. */
    private const NOISE = [
        'bio', 'mdd', 'uht', 'marque', 'repere', 'eco', 'lot', 'pack', 'sachet', 'barquette', 'barq', 'boite', 'bte', 'brique',
        'bouteille', 'btl', 'vrac', 'kg', 'cl', 'ml', 'france', 'origine', 'cat', 'pce', 'pcs', 'piece', 'pieces', 'plein', 'air',
        'sol', 'extra', 'fin', 'fins', 'classique', 'leclerc', 'lidl', 'carrefour', 'auchan', 'casino', 'format', 'familial',
        'pot', 'filet', 'botte', 'les', 'des', 'aux', 'avec', 'sans', 'pour', 'the', 'and',
    ];

    private const STOPWORDS = ['de', 'du', 'des', 'la', 'le', 'les', 'en', 'au', 'aux', 'et', 'pour', 'a', 'd', 'l', 'a'];

    public const MIN_SCORE = 0.66;

    /** @var Collection<int, array{ingredient: Ingredient, tokens: array<int, string>}> */
    private Collection $index;

    public function __construct(?Collection $ingredients = null)
    {
        $ingredients ??= Ingredient::with('packs')->get();

        $this->index = $ingredients->map(fn (Ingredient $i) => [
            'ingredient' => $i,
            'tokens' => $this->ingredientTokens($i->name),
        ])->filter(fn ($row) => $row['tokens'] !== [])->values();
    }

    /** @return array{status: string, pack: ?IngredientPack} */
    public function match(string $normalized, ?int $storeId, bool $weighted): array
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

        $ingredient = $this->bestIngredient($normalized);
        if ($ingredient) {
            $pack = $this->choosePack($ingredient, $normalized, $weighted);
            if ($pack) {
                return ['status' => ReceiptLine::STATUS_SUGGESTED, 'pack' => $pack];
            }
        }

        return ['status' => ReceiptLine::STATUS_UNKNOWN, 'pack' => null];
    }

    public function bestIngredient(string $normalized): ?Ingredient
    {
        $labelTokens = $this->labelTokens($normalized);
        if ($labelTokens === []) {
            return null;
        }

        $best = null;
        foreach ($this->index as $row) {
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

            $rank = [$score, $matched, count($row['tokens'])];
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

        return $packs->first();
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
        } elseif (preg_match('/(?:\bX\s*(\d{1,2})\b|\b(\d{1,2})\s*X\b(?!\s*\d))/', $normalized, $m)) {
            $value = (float) ($m[1] !== '' ? $m[1] : $m[2]);
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

    /** @return array<int, string> */
    private function ingredientTokens(string $name): array
    {
        $words = explode(' ', Str::slug($name, ' '));

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

    private function tokensMatch(string $candidate, string $token): bool
    {
        return $candidate === $token
            || (strlen($candidate) >= 4 && str_starts_with($token, $candidate))
            || (strlen($token) >= 4 && str_starts_with($candidate, $token));
    }

    private function singular(string $word): string
    {
        return strlen($word) > 4 && in_array(substr($word, -1), ['s', 'x'], true) ? substr($word, 0, -1) : $word;
    }
}
