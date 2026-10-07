<?php

namespace App\Support;

use App\Models\Ingredient;
use App\Models\Price;
use App\Models\Recipe;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation et enregistrement d'une recette (formulaire et import du jeu de départ).
 */
class RecipeWriter
{
    /**
     * Valide le formulaire et renvoie des données normalisées :
     * champs de la recette + ingredients (ingredient_id résolu) + steps + tag_ids + equipment_ids.
     */
    public static function validate(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', Rule::in(array_keys(Recipe::CATEGORIES))],
            'yield_quantity' => ['required', 'numeric', 'gt:0', 'max:5000'],
            'yield_unit' => ['required', Rule::in(array_keys(Recipe::YIELD_UNITS))],
            'prep_minutes' => ['nullable', 'integer', 'min:0', 'max:2880'],
            'cook_minutes' => ['nullable', 'integer', 'min:0', 'max:2880'],
            'rest_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'difficulty' => ['required', Rule::in(array_keys(Recipe::DIFFICULTIES))],
            'protein' => ['nullable', Rule::in(array_keys(Recipe::PROTEINS))],
            'source' => ['nullable', 'string', 'max:255'],
            'industrial_price' => ['nullable', 'string', 'max:12'],
            'draft' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer', 'exists:tags,id'],
            'new_tags' => ['nullable', 'string', 'max:200', function ($attribute, $value, $fail) {
                foreach (self::tagNames($value) as $name) {
                    if (mb_strlen($name) > Tag::NAME_MAX || Str::slug($name) === '') {
                        $fail('Étiquette « '.Str::limit($name, 30).' » : '.Tag::NAME_MAX.' caractères au plus, avec au moins une lettre ou un chiffre.');
                    }
                }
                if (count(self::tagNames($value)) > 5) {
                    $fail('Au plus 5 nouvelles étiquettes à la fois.');
                }
            }],
            'equipment' => ['nullable', 'array'],
            'equipment.*' => ['integer', 'exists:equipment,id'],
            'ingredients' => ['nullable', 'array', 'max:60'],
            'ingredients.*.group' => ['nullable', 'string', 'max:60'],
            'ingredients.*.name' => ['nullable', 'string', 'max:80'],
            'ingredients.*.label' => ['nullable', 'string', 'max:160'],
            'ingredients.*.quantity' => ['nullable', 'string', 'max:12'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:40', function ($attribute, $value, $fail) {
                if ($value !== null && $value !== '' && ! Units::validCode($value)) {
                    $fail('Unité inconnue.');
                }
            }],
            'ingredients.*.ask_kind' => ['nullable', Rule::in(['unit', 'piece', 'density'])],
            'ingredients.*.ask_word' => ['nullable', 'string', 'max:36'],
            'ingredients.*.ask_value' => ['nullable', 'string', 'max:12'],
            'ingredients.*.note' => ['nullable', 'string', 'max:120'],
            'ingredients.*.optional' => ['nullable', 'boolean'],
            'steps' => ['nullable', 'array', 'max:40'],
            'steps.*.body' => ['nullable', 'string', 'max:2000'],
            'steps.*.timer' => ['nullable', 'integer', 'min:1', 'max:2880'],
            'steps.*.equipment_id' => ['nullable', 'integer', 'exists:equipment,id'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:15360'],
            'remove_photo' => ['nullable', 'boolean'],
        ], [], [
            'title' => 'titre', 'category' => 'catégorie', 'yield_quantity' => 'rendement', 'yield_unit' => 'unité du rendement',
            'prep_minutes' => 'temps de préparation', 'cook_minutes' => 'temps de cuisson', 'rest_minutes' => 'temps de repos',
            'difficulty' => 'difficulté', 'photo' => 'photo',
        ]);

        $industrial = null;
        if (trim((string) ($data['industrial_price'] ?? '')) !== '') {
            $industrial = Price::parseEuros($data['industrial_price']);
            if ($industrial === null) {
                throw ValidationException::withMessages(['industrial_price' => 'Prix de l\'équivalent industriel invalide (ex. 2,49).']);
            }
        }

        $ingredients = self::resolveIngredients($data['ingredients'] ?? []);

        $steps = collect($data['steps'] ?? [])
            ->filter(fn ($s) => trim((string) ($s['body'] ?? '')) !== '')
            ->map(fn ($s) => [
                'body' => trim($s['body']),
                'timer_minutes' => $s['timer'] ?? null,
                'equipment_id' => $s['equipment_id'] ?? null,
            ])->values()->all();

        if ($ingredients === []) {
            throw ValidationException::withMessages(['ingredients' => 'Ajoute au moins un ingrédient.']);
        }
        if ($steps === []) {
            throw ValidationException::withMessages(['steps' => 'Ajoute au moins une étape.']);
        }

        return [
            'fields' => [
                'title' => trim($data['title']),
                'description' => $data['description'] ?? null,
                'category' => $data['category'],
                'yield_quantity' => $data['yield_quantity'],
                'yield_unit' => $data['yield_unit'],
                'prep_minutes' => $data['prep_minutes'] ?? null,
                'cook_minutes' => $data['cook_minutes'] ?? null,
                'rest_minutes' => $data['rest_minutes'] ?? null,
                'difficulty' => $data['difficulty'],
                'protein' => $data['protein'] ?? null,
                'source' => $data['source'] ?? null,
                'industrial_price_cents' => $industrial,
                'status' => ! empty($data['draft']) ? Recipe::STATUS_DRAFT : Recipe::STATUS_PUBLISHED,
            ],
            'ingredients' => $ingredients,
            'steps' => $steps,
            'tag_ids' => array_map('intval', $data['tags'] ?? []),
            'new_tags' => self::tagNames($data['new_tags'] ?? null),
            'equipment_ids' => array_map('intval', $data['equipment'] ?? []),
        ];
    }

    /** Résout les noms d'ingrédients saisis et vérifie que chaque quantité est convertible. */
    public static function resolveIngredients(array $rows): array
    {
        $index = [];
        foreach (Ingredient::all() as $ingredient) {
            $index[Str::slug($ingredient->name)] ??= $ingredient;
            $index[$ingredient->slug] ??= $ingredient;
        }

        $resolved = [];
        $unknown = [];
        $errors = [];
        $group = null;

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $group = trim((string) ($row['group'] ?? '')) ?: null;

            if ($name === '') {
                continue;
            }

            $ingredient = $index[Str::slug($name)] ?? null;
            if (! $ingredient) {
                $unknown[] = $name;

                continue;
            }

            $quantityInput = str_replace(',', '.', trim((string) ($row['quantity'] ?? '')));
            $quantity = $quantityInput === '' ? null : (is_numeric($quantityInput) && (float) $quantityInput > 0 ? (float) $quantityInput : false);
            $unit = ($row['unit'] ?? null) ?: null;
            $note = trim((string) ($row['note'] ?? '')) ?: null;

            // v0.16.0 : réponse à « Combien vaut 1 sachet de … ? » posée à la relecture, retenue sur l'ingrédient
            if ($unit !== null && ($learned = self::learnAnswer($row, $ingredient, $unit, $note)) !== null) {
                [$unit, $note] = $learned;
            }

            if ($quantity === false) {
                $errors[] = "« {$name} » : quantité invalide.";

                continue;
            }
            if (($quantity === null) !== ($unit === null)) {
                $errors[] = "« {$name} » : indique la quantité et l'unité, ou aucune des deux (« selon goût »).";

                continue;
            }
            if ($quantity !== null) {
                try {
                    Units::toBase($quantity, $unit, $ingredient);
                } catch (UnitConversionException $e) {
                    $errors[] = $e->getMessage().' Le bouton « Indiquer l\'équivalence » de la ligne la demande sans quitter la recette.';

                    continue;
                }
            }

            $resolved[] = [
                'group_label' => $group,
                'ingredient_id' => $ingredient->id,
                'quantity' => $quantity,
                'unit' => $unit,
                'note' => $note,
                'is_optional' => ! empty($row['optional']),
            ];
        }

        if ($unknown !== []) {
            $errors[] = 'Ingrédient(s) absent(s) du référentiel : '.implode(', ', array_unique($unknown)).'. Choisis-les dans la liste proposée ou ajoute-les depuis « Ingrédients ».';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['ingredients' => $errors]);
        }

        return $resolved;
    }

    /**
     * Retient sur l'ingrédient la réponse donnée à la relecture : une unité propre (« 1 sachet = 10 g »), le poids
     * d'une pièce ou la densité. Renvoie [unité, précision] de la ligne, ou null s'il n'y a rien à retenir.
     */
    private static function learnAnswer(array $row, Ingredient $ingredient, string $unit, ?string $note): ?array
    {
        $value = str_replace(',', '.', trim((string) ($row['ask_value'] ?? '')));
        $kind = $row['ask_kind'] ?? null;
        if ($kind === null || ! is_numeric($value) || (float) $value <= 0) {
            return null;
        }
        $value = (float) $value;

        if ($kind === 'unit') {
            $slug = Str::slug((string) ($row['ask_word'] ?? ''));
            if ($slug === '' || strlen($slug) > 36) {
                return null;
            }
            $own = $ingredient->unitBySlug($slug);
            if ($own) {
                $own->update(['quantity' => $value, 'is_estimate' => false]);
            } else {
                $own = TypicalUnits::remember($ingredient, $slug, $value, false);
            }
            $ingredient->unsetRelation('units');
            // La ligne passe dans la nouvelle unité si elle était encore « pièce » (« 1 pièce, sachet »)
            if ($unit === 'piece' || $unit === $own->code()) {
                return [$own->code(), TypicalUnits::wordFrom($note) === $slug ? TypicalUnits::noteWithout($note, $slug) : $note];
            }

            return [$unit, $note];
        }

        if ($kind === 'piece' && ! $ingredient->piece_weight_g) {
            $ingredient->update(['piece_weight_g' => $value]);
        } elseif ($kind === 'density' && ! $ingredient->density) {
            $volume = (string) ($row['ask_word'] ?? 'cl');
            $ml = Units::exists($volume) && Units::dimension($volume) === Units::VOLUME ? Units::UNITS[$volume][2] : 10;
            $ingredient->update(['density' => round($value / $ml, 4)]);
        }

        return [$unit, $note];
    }

    /** Enregistre la recette et ses éléments (remplace ingrédients, étapes, étiquettes et appareils). */
    public static function save(Recipe $recipe, array $data): Recipe
    {
        return DB::transaction(function () use ($recipe, $data) {
            $recipe->fill($data['fields']);

            if (! $recipe->exists || ! $recipe->slug) {
                $recipe->slug = self::uniqueSlug($data['fields']['title']);
            }
            $recipe->save();

            $recipe->ingredients()->delete();
            foreach (array_values($data['ingredients']) as $i => $line) {
                $recipe->ingredients()->create($line + ['position' => ($i + 1) * 10]);
            }

            $recipe->steps()->delete();
            foreach (array_values($data['steps']) as $i => $step) {
                $recipe->steps()->create($step + ['position' => ($i + 1) * 10]);
            }

            // v0.22.0 : étiquettes créées depuis la recette (« Viandes, Accompagnement »), ou reprises si elles existent déjà
            $recipe->tags()->sync(array_values(array_unique([...$data['tag_ids'], ...array_map(fn ($name) => Tag::findOrCreateNamed($name)->id, $data['new_tags'] ?? [])])));

            // Les appareils cités dans les étapes sont forcément requis.
            $equipment = collect($data['equipment_ids'])
                ->merge(collect($data['steps'])->pluck('equipment_id')->filter())
                ->map(fn ($id) => (int) $id)->unique()->values()->all();
            $recipe->equipment()->sync($equipment);

            return $recipe;
        });
    }

    public static function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title), 120, '') ?: 'recette';
        // Adresses déjà prises par des pages (/recettes/nouvelle, /recettes/importees, /recettes/etiquettes)
        if (in_array($base, ['nouvelle', 'importees', 'etiquettes'], true)) {
            $base .= '-recette';
        }
        $slug = $base;
        $n = 2;

        while (Recipe::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /** « viandes, Accompagnement ; Fêtes » → noms d'étiquettes nettoyés, sans doublon. @return array<int, string> */
    public static function tagNames(?string $text): array
    {
        $names = [];
        foreach (preg_split('/[,;\n]+/u', (string) $text) as $name) {
            $name = trim(preg_replace('/\s+/u', ' ', $name));
            if ($name !== '' && ! isset($names[Str::slug($name)])) {
                $names[Str::slug($name)] = Str::ucfirst($name);
            }
        }

        return array_values($names);
    }

    public static function tagIds(array $slugs): array
    {
        return Tag::whereIn('slug', $slugs)->pluck('id')->all();
    }
}
