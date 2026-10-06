<?php

namespace App\Support;

use App\Models\Ingredient;
use App\Models\IngredientUnit;
use Illuminate\Support\Str;

/**
 * Unités propres aux ingrédients (v0.16.0) : vocabulaire des fiches (« sachet », « gousse », « boîte »…) et valeurs
 * typiques pour pré-remplir les ingrédients courants. Chaque valeur typique est marquée « estimée » : on la corrige
 * une fois sur la fiche de l'ingrédient, et toutes les recettes suivent.
 */
class TypicalUnits
{
    /** slug => [singulier, pluriel] : mots des fiches reconnus comme unités. */
    public const WORDS = [
        'sachet' => ['sachet', 'sachets'],
        'paquet' => ['paquet', 'paquets'],
        'pot' => ['pot', 'pots'],
        'boite' => ['boîte', 'boîtes'],
        'brique' => ['brique', 'briques'],
        'barquette' => ['barquette', 'barquettes'],
        'bouteille' => ['bouteille', 'bouteilles'],
        'botte' => ['botte', 'bottes'],
        'bouquet' => ['bouquet', 'bouquets'],
        'brin' => ['brin', 'brins'],
        'branche' => ['branche', 'branches'],
        'tige' => ['tige', 'tiges'],
        'feuille' => ['feuille', 'feuilles'],
        'gousse' => ['gousse', 'gousses'],
        'tete' => ['tête', 'têtes'],
        'tranche' => ['tranche', 'tranches'],
        'morceau' => ['morceau', 'morceaux'],
        'cm' => ['cm', 'cm'],
        'poignee' => ['poignée', 'poignées'],
        'noix' => ['noix', 'noix'],
        'noisette' => ['noisette', 'noisettes'],
        'carre' => ['carré', 'carrés'],
        'tablette' => ['tablette', 'tablettes'],
        'rouleau' => ['rouleau', 'rouleaux'],
        'cube' => ['cube', 'cubes'],
        'pave' => ['pavé', 'pavés'],
        'filet' => ['filet', 'filets'],
        'boule' => ['boule', 'boules'],
        'portion' => ['portion', 'portions'],
        'c-a-soupe' => ['c. à soupe', 'c. à soupe'],
        'c-a-cafe' => ['c. à café', 'c. à café'],
    ];

    /** Cuillères d'un ingrédient pesé sans densité connue : poids typique d'une cuillère (densité en g/ml). */
    public const SPOONS = ['cas' => ['c-a-soupe', 15], 'cac' => ['c-a-cafe', 5]];

    private const DENSITIES = [
        '/beurre|margarine/' => 0.95, '/farine/' => 0.55, '/sucre/' => 0.85, '/(^|-)sel(-|$)/' => 1.2, '/miel/' => 1.4,
        '/concentre/' => 1.1, '/moutarde/' => 1.05, '/cacao/' => 0.45, '/maizena|fecule/' => 0.6, '/levure/' => 0.8,
        '/chapelure/' => 0.4, '/parmesan|rape/' => 0.4, '/flocon|avoine/' => 0.4, '/riz|semoule|quinoa|lentille/' => 0.8,
        '/poudre-d-amande|amande-en-poudre/' => 0.45, '/(^|-)epice|curry|paprika|cumin|curcuma|cannelle/' => 0.5,
    ];

    /** Contenants : à défaut de valeur typique, le seul conditionnement connu de l'ingrédient sert d'estimation. */
    public const CONTAINERS = ['sachet', 'paquet', 'pot', 'boite', 'brique', 'barquette', 'bouteille'];

    private const HERBS = 'basilic|coriandre|persil|menthe|ciboulette|aneth|estragon|cerfeuil|herbes|liveche';

    private const SPICES = 'curry|epice|paprika|cumin|curcuma|cannelle|ras-el|piment|cajun|mexicain|italienne|provence|garam|tandoori|colombo|muscade|quatre-epices|massale|za-atar|sumac|chili';

    /**
     * [motif sur le nom de l'ingrédient (en slug), unité, poids typique en g].
     * Converti dans l'unité de base de l'ingrédient (densité pour le ml, poids d'une pièce pour la pièce).
     */
    public const RULES = [
        ['/(^|-)ail(-|$)/', 'gousse', 5],
        ['/(^|-)ail(-|$)/', 'tete', 50],
        ['/gingembre|galanga|curcuma-frais/', 'cm', 5],
        ['/gingembre|galanga|curcuma-frais/', 'morceau', 30],
        ['/'.self::HERBS.'/', 'sachet', 10],
        ['/'.self::HERBS.'/', 'botte', 30],
        ['/'.self::HERBS.'/', 'bouquet', 30],
        ['/'.self::HERBS.'/', 'brin', 1],
        ['/'.self::HERBS.'/', 'feuille', 0.5],
        ['/'.self::HERBS.'/', 'poignee', 10],
        ['/thym|romarin|sarriette/', 'brin', 1],
        ['/thym|romarin|sarriette/', 'branche', 2],
        ['/laurier/', 'feuille', 0.2],
        ['/sauge/', 'feuille', 1],
        ['/citronnelle/', 'tige', 15],
        ['/oignon-nouveau|oignon-vert|cebette/', 'tige', 15],
        ['/levure-chimique/', 'sachet', 11],
        ['/levure-(boulangere|seche|de-boulanger)/', 'sachet', 8],
        ['/sucre-vanille/', 'sachet', 7.5],
        ['/gelatine/', 'feuille', 2],
        ['/'.self::SPICES.'/', 'sachet', 3],
        ['/jambon/', 'tranche', 40],
        ['/bacon|poitrine|lard(-|$)/', 'tranche', 15],
        ['/pain-de-mie/', 'tranche', 25],
        ['/(^|-)pain(-|$)/', 'tranche', 30],
        ['/saumon-fume/', 'tranche', 25],
        ['/fromage|emmental|comte|cheddar|raclette|gouda|mozzarella|reblochon|chevre/', 'tranche', 20],
        ['/tomate.*(pelee|concassee)|concassee|pulpe-de-tomate|coulis-de-tomate|passata/', 'boite', 400],
        ['/pois-chiche|haricot-rouge|haricot-blanc|haricots-rouges|haricots-blancs|lentille|flageolet/', 'boite', 400],
        ['/(^|-)mais(-|$)/', 'boite', 285],
        ['/(^|-)thon(-|$)/', 'boite', 140],
        ['/lait-de-coco|creme-de-coco/', 'boite', 400],
        ['/lait-de-coco|creme-de-coco/', 'brique', 200],
        ['/creme/', 'brique', 200],
        ['/creme/', 'pot', 200],
        ['/yaourt|yogourt/', 'pot', 125],
        ['/fromage-blanc/', 'pot', 500],
        ['/beurre/', 'noix', 10],
        ['/beurre/', 'noisette', 5],
        ['/salade|epinard|roquette|mache|pousse|mesclun/', 'poignee', 30],
        ['/(^|-)noix(-|$)|amande|noisette|cajou|pistache|raisin-sec|cacahuete|pignon/', 'poignee', 30],
        ['/chocolat/', 'carre', 5],
        ['/chocolat/', 'tablette', 100],
        ['/pate-(feuilletee|brisee|sablee)/', 'rouleau', 230],
    ];

    /** Mot d'unité au début d'une précision (« gousses » → gousse, « sachets » → sachet), sinon null. */
    public static function wordFrom(?string $note): ?string
    {
        if ($note === null || trim($note) === '') {
            return null;
        }
        $first = Str::slug(preg_split('/[\s,(]+/u', trim($note))[0] ?? '');
        if ($first === '') {
            return null;
        }
        foreach ([$first, preg_replace('/x$/', '', $first), preg_replace('/s$/', '', $first)] as $candidate) {
            if (isset(self::WORDS[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /** Précision sans le mot d'unité : « gousses, pelées » → « pelées ». */
    public static function noteWithout(?string $note, string $word): ?string
    {
        $rest = trim(preg_replace('/^\S+\s*(?:de\s+|d[\'’]\s*)?[,;:]?\s*/u', '', trim((string) $note)));

        return $rest === '' ? null : $rest;
    }

    public static function display(string $slug): array
    {
        return self::WORDS[$slug] ?? [str_replace('-', ' ', $slug), null];
    }

    /** Poids typique d'une unité pour cet ingrédient, converti dans son unité de base ; null si inconnu. */
    public static function typical(Ingredient $ingredient, string $slug): ?float
    {
        $name = Str::slug($ingredient->name);
        foreach (self::RULES as [$pattern, $unit, $grams]) {
            if ($unit === $slug && preg_match($pattern, $name) === 1) {
                return self::fromGrams($ingredient, (float) $grams);
            }
        }

        return null;
    }

    /**
     * Valeur estimée d'une unité lue sur une fiche : conditionnement du même nom (« Boîte 40 cl »), valeur typique,
     * puis, pour un contenant, le seul conditionnement de l'ingrédient. null si rien ne permet d'estimer.
     */
    public static function estimate(Ingredient $ingredient, string $slug): ?float
    {
        $packs = $ingredient->packs->reject(fn ($p) => $p->is_bulk);

        $named = $packs->first(fn ($p) => str_starts_with(Str::slug($p->label), $slug));
        if ($named) {
            return (float) $named->quantity;
        }

        $typical = self::typical($ingredient, $slug);
        if ($typical !== null) {
            return $typical;
        }

        if (in_array($slug, self::CONTAINERS, true) && $packs->count() === 1) {
            return (float) $packs->first()->quantity;
        }

        return null;
    }

    /** Crée l'unité propre (estimée) si elle n'existe pas encore. */
    public static function remember(Ingredient $ingredient, string $slug, float $quantity, bool $estimate = true): IngredientUnit
    {
        $existing = $ingredient->unitBySlug($slug);
        if ($existing) {
            return $existing;
        }

        [$name, $plural] = self::display($slug);
        $unit = $ingredient->units()->create([
            'slug' => $slug,
            'name' => $name,
            'plural' => $plural,
            'quantity' => round($quantity, 4),
            'is_estimate' => $estimate,
        ]);
        $ingredient->unsetRelation('units');

        return $unit;
    }

    /** Pré-remplit les unités typiques de l'ingrédient (jamais d'écrasement). Renvoie le nombre d'unités ajoutées. */
    public static function seed(Ingredient $ingredient): int
    {
        $added = 0;
        $name = Str::slug($ingredient->name);
        foreach (self::RULES as [$pattern, $slug, $grams]) {
            if (preg_match($pattern, $name) !== 1 || $ingredient->unitBySlug($slug)) {
                continue;
            }
            $quantity = self::fromGrams($ingredient, (float) $grams);
            if ($quantity !== null && $quantity > 0) {
                self::remember($ingredient, $slug, $quantity);
                $added++;
            }
        }

        return $added;
    }

    /** Cuillère d'un ingrédient pesé (g) sans densité : unité propre estimée (« 1 c. à soupe = 15 g »), sinon null. */
    public static function spoon(Ingredient $ingredient, string $unit): ?\App\Models\IngredientUnit
    {
        if (! isset(self::SPOONS[$unit]) || $ingredient->base_unit !== 'g' || $ingredient->density) {
            return null;
        }
        [$slug, $ml] = self::SPOONS[$unit];
        $own = $ingredient->unitBySlug($slug);
        if ($own) {
            return $own;
        }

        $name = Str::slug($ingredient->name);
        $density = 1.0;
        foreach (self::DENSITIES as $pattern => $value) {
            if (preg_match($pattern, $name) === 1) {
                $density = $value;
                break;
            }
        }

        return self::remember($ingredient, $slug, round($ml * $density, 1));
    }

    private static function fromGrams(Ingredient $ingredient, float $grams): ?float
    {
        return match ($ingredient->base_unit) {
            'g' => $grams,
            'ml' => $grams / ($ingredient->density ?: 1.0),
            default => $ingredient->piece_weight_g ? $grams / $ingredient->piece_weight_g : null,
        };
    }
}
