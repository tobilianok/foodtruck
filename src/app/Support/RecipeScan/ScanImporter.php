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
use App\Support\TypicalUnits;
use App\Support\UnitConversionException;
use App\Support\Units;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

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
                'info' => null,
                'ask' => null,
                'kind' => null,  // v0.17.0 : type de correction (unknown, approx, ask, quantity, other) pour la fenêtre de correction
            ];

            // v0.16.0 : l'unité lue devient une unité de l'ingrédient (sachet, gousse, boîte…) dès que possible
            if ($ingredient && $row['quantity'] !== null) {
                $row = self::checkQuantity($row, $ingredient);
            }

            if (! $ingredient) {
                $row['problem'] = 'Ingrédient absent du référentiel.';
                $row['kind'] = 'unknown';
                $unresolved[] = $line['name'];
            } elseif ($match['via'] === 'approchant') {
                $row['problem'] = 'lecture approximative, rapproché de « '.$ingredient->name.' » : à confirmer.';
                $row['kind'] = 'approx';
            } elseif ($row['problem'] !== null) {
                $row['kind'] = $row['ask'] ? 'ask' : 'other';
            }

            // Lecture douteuse signalée par le lecteur (fraction perdue, ligne absorbée par la mise en page) ;
            // « 1 cm » de gingembre n'est plus un problème quand l'ingrédient connaît le centimètre
            $check = $line['check'] ?? null;
            if ($check && $row['unit'] === Units::CUSTOM_PREFIX.'cm' && str_contains($check, 'centimètres')) {
                $check = null;
            }
            if ($row['problem'] === null && ! empty($check)) {
                $row['problem'] = $check;
                $row['kind'] = 'quantity';
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

    /**
     * Lit ou relit une fiche. v0.16.1 : plus aucune recette créée toute seule, même entièrement reconnue — chaque
     * fiche importée attend la validation de Louis (« Valider » sur l'écran de relecture).
     */
    public function ingest(RecipeImport $import, ?Household $household = null): RecipeImport
    {
        $analysis = $this->bestAnalysis($import);

        $import->forceFill([
            'parsed' => ['recipe' => Arr::except($analysis['recipe'], ['ingredients']), 'rows' => $analysis['rows'], 'complete' => $analysis['complete']],
            'issues' => $analysis['issues'],
        ]);
        $import->save();

        return $import;
    }

    /** Fiche entièrement reconnue : il ne reste qu'à la valider. */
    public static function isReady(RecipeImport $import): bool
    {
        return ! $import->recipe_id && ! empty($import->parsed['complete']);
    }

    /**
     * Ce qui est lu pour cette fiche. v0.18.0 : la recette rendue par le modèle de vision (Ollama) ; plus aucun autre
     * outil de reconnaissance de texte. Fiche pas encore lue (ou lecture impossible) : rien n'est deviné, la fiche
     * attend sa lecture. Sans modèle configuré (développement, tests) : le texte de Paperless.
     */
    public function bestAnalysis(RecipeImport $import): array
    {
        if (self::readByVision($import)) {
            $text = VisionComposer::compose($import->layout['recette']);
            $analysis = $this->analyse($text, null);
            if (trim((string) $analysis['recipe']['title']) === '') {
                $analysis = $this->analyse($text, $import->title);
            }

            return $analysis;
        }

        if (VisionClient::ready()) {
            $analysis = $this->analyse('', $import->title);
            $analysis['complete'] = false;
            $analysis['issues'] = [match ($import->layout_status) {
                RecipeImport::LAYOUT_FAILED => 'Analyse par l\'IA impossible : '.Str::limit((string) $import->layout_error, 160).'. « Renvoyer à l\'IA » pour réessayer.',
                RecipeImport::LAYOUT_PENDING => 'Analyse par l\'IA en cours.',
                default => 'Pas encore envoyée à l\'IA.',
            }];

            return $analysis;
        }

        return $this->analyse((string) $import->raw_text, $import->title);
    }

    /** Texte effectivement lu pour cette fiche (affiché à la relecture). */
    public static function readText(RecipeImport $import): string
    {
        return self::readByVision($import) ? VisionComposer::compose($import->layout['recette']) : (string) $import->raw_text;
    }

    /** v0.18.0 : fiche lue par le modèle de vision. */
    public static function readByVision(RecipeImport $import): bool
    {
        return $import->layout_status === RecipeImport::LAYOUT_DONE && ($import->layout['source'] ?? null) === 'vision'
            && is_array($import->layout['recette'] ?? null);
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
                'raw' => $row['raw'] ?? null,
                'quantity' => $row['quantity'] === null ? '' : Units::number((float) $row['quantity'], 3),
                'unit' => $row['unit'],
                'note' => $row['note'],
                'optional' => $row['optional'],
                'problem' => $row['problem'] ?? null,
                'candidates' => $row['candidates'] ?? [],
                'known' => ! empty($row['ingredient_id']),
                'info' => $row['info'] ?? null,
                'info_slug' => $row['info_slug'] ?? null,
                'ask' => $row['ask'] ?? null,
                'kind' => $row['kind'] ?? (empty($row['problem']) ? null : (empty($row['ingredient_id']) ? 'unknown' : 'quantity')),
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

    /**
     * Unité de la ligne ramenée à une unité que l'ingrédient sait convertir (v0.16.0) :
     * - « 4 gousses », « ½ sachet », « 1 boîte », « 2 cm » : unité propre de l'ingrédient, créée au besoin d'après son
     *   conditionnement ou une valeur typique (marquée estimée, modifiable sur la fiche de l'ingrédient) ;
     * - « 7 cl de bouillon » : fraction de cube ;
     * - sinon, si rien ne permet de convertir : UNE question (« 1 sachet de Crevettes = … g ») posée à la relecture,
     *   dont la réponse est retenue sur l'ingrédient pour toutes les fiches suivantes.
     */
    private static function checkQuantity(array $row, Ingredient $ingredient): array
    {
        $word = null;
        if ($row['unit'] === 'piece' && preg_match('/^(\d+(?:[.,]\d+)?)\s*cm\b\s*(.*)$/u', trim((string) $row['note']), $cm) === 1) {
            $word = 'cm';
            $row['quantity'] = (float) str_replace(',', '.', $cm[1]);
            $row['note'] = trim($cm[2]) ?: null;
        } elseif ($row['unit'] === 'piece' && ($word = TypicalUnits::wordFrom($row['note'])) !== null) {
            $row['note'] = TypicalUnits::noteWithout($row['note'], $word);
        }

        if ($word !== null) {
            $own = $ingredient->unitBySlug($word);
            if (! $own && ($estimate = TypicalUnits::estimate($ingredient, $word)) !== null) {
                $own = TypicalUnits::remember($ingredient, $word, $estimate);
            }
            if ($own) {
                $row['unit'] = $own->code();
                $row = self::estimateInfo($row, $own, $ingredient);

                return $row;
            }

            [$name] = TypicalUnits::display($word);
            $row['ask'] = ['kind' => 'unit', 'word' => $word, 'label' => '1 '.$name, 'base' => $ingredient->base_unit];
            $row['problem'] = "Combien vaut 1 {$name} de « {$ingredient->name} » ? Indique-le une fois, Foodtruck le retiendra.";

            return $row;
        }

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

            // « 1 c. à soupe de beurre » sans densité connue : cuillère estimée (≈ 15 g), modifiable sur l'ingrédient
            if (($spoon = TypicalUnits::spoon($ingredient, $row['unit'])) !== null) {
                $row['unit'] = $spoon->code();
                $row = self::estimateInfo($row, $spoon, $ingredient);

                return $row;
            }

            $row['ask'] = self::question($row['unit'], $ingredient);
            $row['problem'] = $row['ask']
                ? "Combien pèse {$row['ask']['label']} de « {$ingredient->name} » ? Indique-le une fois, Foodtruck le retiendra."
                : $e->getMessage().' Choisis une autre unité ou complète la fiche de l\'ingrédient.';

            return $row;
        }
    }

    /** Valeur typique utilisée : rappelée en petit sous la ligne, avec un lien pour la corriger une fois pour toutes. */
    private static function estimateInfo(array $row, \App\Models\IngredientUnit $unit, Ingredient $ingredient): array
    {
        if ($unit->is_estimate) {
            $row['info'] = $unit->equivalence($ingredient->base_unit).' (valeur typique)';
            $row['info_slug'] = $ingredient->slug;
        }

        return $row;
    }

    /** Question posée quand il manque le poids d'une pièce ou la densité de l'ingrédient. */
    public static function question(string $unit, Ingredient $ingredient): ?array
    {
        if (Units::isCustom($unit)) {
            return null;
        }
        $from = Units::dimension($unit);
        $to = Units::dimension($ingredient->base_unit);

        if ($from === Units::PIECE || $to === Units::PIECE) {
            return ['kind' => 'piece', 'word' => 'piece', 'label' => '1 pièce', 'base' => 'g'];
        }
        $volume = $from === Units::VOLUME ? $unit : 'cl';

        return ['kind' => 'density', 'word' => $volume, 'label' => '1 '.Units::label($volume), 'base' => 'g'];
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
