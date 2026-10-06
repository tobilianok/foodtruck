<?php

namespace App\Support\RecipeScan;

use App\Models\Equipment;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeAlias;
use App\Models\RecipeImport;
use App\Models\User;
use App\Support\RecipeWriter;
use App\Support\UnitConversionException;
use App\Support\Units;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

/**
 * Transforme le texte d'une fiche en recette : lecture, rapprochement avec le référentiel, contrôle des quantités.
 *
 * - tout est reconnu et sans réserve  → recette publiée ;
 * - tout est reconnu avec des réserves → recette en brouillon à relire ;
 * - un ingrédient ou une unité pose problème → pas de recette, la fiche attend sa relecture (formulaire prérempli).
 */
class ScanImporter
{
    /** Un cube de bouillon pour ce volume : sert à convertir « 7 cl de bouillon » en cubes. */
    private const BOUILLON_ML_PER_CUBE = 500;

    /**
     * @return array{
     *   recipe: array, rows: array<int, array>, issues: array<int, string>, complete: bool, unresolved: array<int, string>
     * }
     */
    public function analyse(string $text, ?string $titleHint = null, ?IngredientMatcher $matcher = null): array
    {
        $parsed = RecipeTextParser::parse($text, $titleHint);
        $matcher ??= new IngredientMatcher;
        $issues = $parsed['issues'];

        $rows = [];
        $unresolved = [];

        foreach ($parsed['ingredients'] as $line) {
            $match = $matcher->match($line['name']);
            $ingredient = $match['ingredient'];

            $row = [
                'group' => $line['group'],
                'label' => $line['name'],
                'name' => $ingredient?->name ?? $line['name'],
                'ingredient_id' => $ingredient?->id,
                'quantity' => $line['quantity'],
                'unit' => $line['unit'],
                'note' => $line['note'],
                'optional' => $line['optional'],
                'raw' => $line['raw'],
                'problem' => null,
                'candidates' => $match['candidates'],
            ];

            if (! $ingredient) {
                $row['problem'] = 'Ingrédient absent du référentiel.';
                $unresolved[] = $line['name'];
            } elseif ($match['via'] === 'approchant') {
                $row['problem'] = 'lecture approximative, rapproché de « '.$ingredient->name.' » : à confirmer.';
            } elseif ($row['quantity'] !== null) {
                $row = self::checkQuantity($row, $ingredient);
            }

            // Lecture douteuse signalée par le lecteur (fraction perdue, ligne absorbée par la mise en page)
            if ($row['problem'] === null && ! empty($line['check'])) {
                $row['problem'] = $line['check'];
            }

            $rows[] = $row;
        }

        $problems = collect($rows)->whereNotNull('problem')->count();
        if ($problems > 0) {
            $issues[] = $problems === 1 ? '1 ingrédient à vérifier.' : "{$problems} ingrédients à vérifier.";
        }

        if (Recipe::where('title', $parsed['title'])->exists()) {
            $issues[] = 'Une recette porte déjà ce titre.';
        }

        $complete = $problems === 0 && $rows !== [] && $parsed['steps'] !== [];

        return [
            'recipe' => $parsed,
            'rows' => $rows,
            'issues' => array_values(array_unique($issues)),
            'complete' => $complete,
            'unresolved' => array_values(array_unique($unresolved)),
        ];
    }

    /** Lit ou relit une fiche et crée la recette si tout est reconnu. */
    public function ingest(RecipeImport $import, ?Household $household = null): RecipeImport
    {
        $analysis = $this->bestAnalysis($import);

        $import->forceFill([
            'parsed' => ['recipe' => Arr::except($analysis['recipe'], ['ingredients']), 'rows' => $analysis['rows']],
            'issues' => $analysis['issues'],
        ]);

        if ($analysis['complete'] && ! $import->recipe_id) {
            $household ??= $import->household;
            $author = self::author($household);

            if ($author) {
                try {
                    $recipe = RecipeWriter::save(new Recipe(['author_id' => $author->id]), self::writerData($analysis));
                    $import->forceFill([
                        'recipe_id' => $recipe->id,
                        'status' => RecipeImport::STATUS_CREATED,
                        'auto_published' => $recipe->isPublished(),
                    ]);
                } catch (Throwable $e) {
                    report($e);
                    $import->issues = array_merge($analysis['issues'], ['Création impossible : '.Str::limit($e->getMessage(), 160)]);
                }
            }
        }

        $import->save();

        return $import;
    }

    /**
     * Texte à lire : la fiche remise en forme d'après le scan (foodtruck-ocr) quand elle existe, sinon le texte de
     * Paperless. Si la lecture du scan trouve moins de choses (ni ingrédients ni étapes) que le texte de Paperless,
     * c'est ce dernier qui sert : la nouvelle lecture ne fait jamais moins bien que l'ancienne.
     */
    public function bestAnalysis(RecipeImport $import): array
    {
        $paperless = fn () => $this->analyse((string) $import->raw_text, $import->title);

        if ($import->layout_status === RecipeImport::LAYOUT_FAILED) {
            $analysis = $paperless();
            $analysis['issues'][] = 'Lecture du scan impossible ('.Str::limit((string) $import->layout_error, 120).') : texte de Paperless utilisé, à vérifier. « Relire la fiche » relance la lecture du scan.';

            return $analysis;
        }

        if ($import->layout_status !== RecipeImport::LAYOUT_DONE || empty($import->layout)) {
            return $paperless();
        }

        $fromScan = $this->analyse(LayoutComposer::compose($import->layout), $import->title);
        $score = fn (array $a) => (count($a['rows']) > 0 ? 2 : 0) + (count($a['recipe']['steps']) > 0 ? 2 : 0);
        if ($score($fromScan) < 4 && trim((string) $import->raw_text) !== '') {
            $fallback = $paperless();
            if ($score($fallback) > $score($fromScan)) {
                $fallback['issues'][] = 'Le scan a été mal compris : texte de Paperless utilisé, à vérifier.';

                return $fallback;
            }
        }

        return $fromScan;
    }

    /** Texte effectivement lu pour cette fiche (affiché à la relecture). */
    public static function readText(RecipeImport $import): string
    {
        return $import->layout_status === RecipeImport::LAYOUT_DONE && ! empty($import->layout)
            ? LayoutComposer::compose($import->layout)
            : (string) $import->raw_text;
    }

    /** Données au format attendu par RecipeWriter::save(). */
    public static function writerData(array $analysis): array
    {
        $recipe = $analysis['recipe'];
        $publish = $analysis['issues'] === [];

        return [
            'fields' => [
                'title' => $recipe['title'],
                'description' => $recipe['description'],
                'category' => $recipe['category'],
                'yield_quantity' => $recipe['yield_quantity'] ?? 4,
                'yield_unit' => $recipe['yield_unit'],
                'prep_minutes' => $recipe['prep_minutes'],
                'cook_minutes' => $recipe['cook_minutes'],
                'rest_minutes' => $recipe['rest_minutes'],
                'difficulty' => $recipe['difficulty'],
                'protein' => self::protein($analysis['rows']),
                'source' => $recipe['source'],
                'industrial_price_cents' => null,
                'status' => $publish ? Recipe::STATUS_PUBLISHED : Recipe::STATUS_DRAFT,
            ],
            'ingredients' => collect($analysis['rows'])->map(fn (array $row) => [
                'group_label' => $row['group'],
                'ingredient_id' => $row['ingredient_id'],
                'quantity' => $row['quantity'],
                'unit' => $row['unit'],
                'note' => $row['note'],
                'is_optional' => $row['optional'],
            ])->all(),
            'steps' => collect($recipe['steps'])->map(fn (array $step) => [
                'body' => $step['body'],
                'timer_minutes' => $step['timer'],
                'equipment_id' => self::stepEquipment($step),
            ])->all(),
            'tag_ids' => RecipeWriter::tagIds($recipe['tags']),
            'equipment_ids' => self::equipmentIds($recipe['steps']),
        ];
    }

    /** Lignes du formulaire de relecture. @return array{ingredients: array, steps: array} */
    public static function formRows(array $parsed): array
    {
        return [
            'ingredients' => collect($parsed['rows'] ?? [])->map(fn (array $row) => [
                'group' => $row['group'],
                'name' => $row['name'],
                'label' => $row['label'] ?? $row['name'],
                'quantity' => $row['quantity'] === null ? '' : Units::number((float) $row['quantity'], 2),
                'unit' => $row['unit'],
                'note' => $row['note'],
                'optional' => $row['optional'],
                'problem' => $row['problem'] ?? null,
                'candidates' => $row['candidates'] ?? [],
                'known' => ! empty($row['ingredient_id']),
            ])->all(),
            'steps' => collect($parsed['recipe']['steps'] ?? [])->map(fn (array $step) => [
                'body' => $step['body'], 'timer' => $step['timer'], 'equipment_id' => self::stepEquipment($step),
            ])->all(),
        ];
    }

    /** Retient le rapprochement fait à la main : « comté 24 mois râpé » → Comté, pour les prochaines fiches. */
    public static function learn(array $submittedRows): void
    {
        $index = [];
        foreach (Ingredient::all() as $ingredient) {
            $index[Str::slug($ingredient->name)] ??= $ingredient;
        }

        foreach ($submittedRows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $ingredient = $index[Str::slug((string) ($row['name'] ?? ''))] ?? null;
            if ($label === '' || ! $ingredient) {
                continue;
            }

            $key = IngredientMatcher::key($label);
            if ($key === '' || $key === IngredientMatcher::key($ingredient->name)) {
                continue;
            }

            $alias = RecipeAlias::firstOrNew(['normalized_label' => $key]);
            $alias->hits = $alias->exists ? $alias->hits + 1 : 1;
            $alias->ingredient_id = $ingredient->id;
            $alias->save();
        }
    }

    /** Compte de départ : l'administrateur du foyer, sinon le premier compte du foyer. */
    public static function author(?Household $household): ?User
    {
        if (! $household) {
            return null;
        }

        return User::where('household_id', $household->id)->where('household_role', User::HOUSEHOLD_ADMIN)->orderBy('id')->first()
            ?? User::where('household_id', $household->id)->orderBy('id')->first();
    }

    /** Vérifie que la quantité se convertit ; « 7 cl de bouillon » devient une fraction de cube. */
    private static function checkQuantity(array $row, Ingredient $ingredient): array
    {
        try {
            Units::toBase((float) $row['quantity'], $row['unit'], $ingredient);

            return $row;
        } catch (UnitConversionException $e) {
            $isBouillon = str_starts_with(Str::lower(Str::ascii($ingredient->name)), 'bouillon')
                && $ingredient->base_unit === 'piece'
                && Units::dimension($row['unit']) === Units::VOLUME;

            if ($isBouillon) {
                $ml = (float) $row['quantity'] * Units::UNITS[$row['unit']][2];
                $cubes = max(0.25, ceil($ml / self::BOUILLON_ML_PER_CUBE * 4) / 4);
                $label = Units::quantityLabel((float) $row['quantity'], $row['unit']);
                $row['quantity'] = $cubes;
                $row['unit'] = 'piece';
                $row['note'] = Str::limit(trim(($row['note'] ? $row['note'].', ' : '')."{$label} de bouillon préparé"), 120, '');

                return $row;
            }

            $row['problem'] = $e->getMessage().' Choisis une autre unité ou complète la fiche de l\'ingrédient.';

            return $row;
        }
    }

    /** Protéine principale devinée d'après les ingrédients (vide si rien de net). */
    private static function protein(array $rows): ?string
    {
        $names = Str::lower(Str::ascii(collect($rows)->pluck('name')->implode(' | ')));
        $names = preg_replace('/bouillon[^|]*/', '', $names);

        return match (true) {
            (bool) preg_match('/boeuf|veau|agneau|steak|bavette|entrecote/', $names) => 'boeuf',
            (bool) preg_match('/poulet|dinde|canard|volaille|pintade/', $names) => 'volaille',
            (bool) preg_match('/porc|jambon|lardon|saucisse|chorizo|andouille|bacon/', $names) => 'porc',
            (bool) preg_match('/saumon|cabillaud|thon|poisson|crevette|colin|sardine|maquereau|merlu|moule/', $names) => 'poisson',
            (bool) preg_match('/lentille|pois chiche|haricot (?:rouge|blanc|sec)|feve|tofu|pois casse/', $names) => 'legumineuses',
            default => null,
        };
    }

    private static function stepEquipment(array $step): ?int
    {
        return self::equipmentIds([$step])[0] ?? null;
    }

    /** @return array<int, int> */
    private static function equipmentIds(array $steps): array
    {
        $text = Str::lower(Str::ascii(collect($steps)->pluck('body')->implode(' ')));
        $slugs = [];

        foreach (['four' => '/\bfour\b/', 'micro-ondes' => '/micro-?ondes/', 'air-fryer' => '/air ?fryer/', 'cookeo' => '/cookeo/', 'mixeur-plongeant' => '/mixeur plongeant/', 'blender' => '/blender/', 'autocuiseur' => '/cocotte-?minute|autocuiseur/'] as $slug => $pattern) {
            if (preg_match($pattern, $text)) {
                $slugs[] = $slug;
            }
        }

        return $slugs === [] ? [] : Equipment::whereIn('slug', $slugs)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
