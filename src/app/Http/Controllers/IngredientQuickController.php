<?php

namespace App\Http\Controllers;

use App\Models\Aisle;
use App\Models\Ingredient;
use App\Support\TypicalUnits;
use App\Support\Units;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * v0.17.0 : corrections faites dans une fenêtre, sans quitter la recette en cours de relecture ou de saisie.
 * Réponses JSON ; la page met la ligne à jour et la passe au vert.
 */
class IngredientQuickController extends Controller
{
    /** Nouvel ingrédient (nom, rayon, unité de base, poids d'une pièce) ; unités courantes pré-remplies. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'aisle_id' => ['required', 'integer', 'exists:aisles,id'],
            'base_unit' => ['required', Rule::in(array_keys(Units::BASE_CHOICES))],
            'piece_weight_g' => ['nullable', 'numeric', 'gt:0', 'max:20000'],
        ], [], ['name' => 'nom', 'aisle_id' => 'rayon', 'base_unit' => 'unité de base', 'piece_weight_g' => 'poids d\'une pièce']);

        $name = trim(preg_replace('/\s+/u', ' ', $data['name']));
        $name = Str::ucfirst($name);
        $slug = Str::slug($name);
        if ($slug === '') {
            return response()->json(['message' => 'Nom invalide.', 'errors' => ['name' => ['Nom invalide.']]], 422);
        }

        // Déjà présent (même nom à l'accent ou à la majuscule près) : on le reprend tel quel
        $ingredient = Ingredient::where('slug', $slug)->first();
        $created = false;
        if (! $ingredient) {
            $ingredient = Ingredient::create([
                'name' => $name,
                'slug' => $slug,
                'aisle_id' => (int) $data['aisle_id'],
                'base_unit' => $data['base_unit'],
                'piece_weight_g' => $data['piece_weight_g'] ?? null,
                'is_fresh' => in_array(Aisle::find($data['aisle_id'])?->slug, ['fruits-legumes', 'boucherie', 'poissonnerie', 'cremerie', 'fromages', 'charcuterie-traiteur'], true),
                'created_by' => $request->user()->id,
            ]);
            TypicalUnits::seed($ingredient);
            $created = true;
        }

        return response()->json(self::payload($ingredient->fresh('units')) + [
            'created' => $created,
            'message' => $created ? "« {$ingredient->name} » ajouté aux ingrédients." : "« {$ingredient->name} » existait déjà : il est repris.",
        ], $created ? 201 : 200);
    }

    /** Unité propre (« 1 paquet = 200 g ») retenue sur l'ingrédient. */
    public function storeUnit(Request $request, Ingredient $ingredient): JsonResponse
    {
        $data = $request->validate([
            'word' => ['required', 'string', 'max:36'],
            'quantity' => ['required', 'string', 'max:12'],
        ]);
        $quantity = self::number($data['quantity']);
        $slug = Str::limit(Str::slug($data['word']), 36, '');
        if ($quantity === null || $slug === '') {
            return response()->json(['message' => 'Équivalence invalide (ex. 200 ou 0,5).', 'errors' => ['quantity' => ['Équivalence invalide.']]], 422);
        }

        $own = $ingredient->unitBySlug($slug);
        if ($own) {
            $own->update(['quantity' => $quantity, 'is_estimate' => false]);
        } else {
            $own = TypicalUnits::remember($ingredient, $slug, $quantity, false);
        }

        return response()->json(self::payload($ingredient->fresh('units')) + [
            'unit' => $own->code(),
            'message' => $own->equivalence($ingredient->base_unit).' : retenu pour toutes les recettes.',
        ]);
    }

    /** Poids d'une pièce, densité ou contenance d'une pièce (v0.20.1) manquants. */
    public function storeMeasure(Request $request, Ingredient $ingredient): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['piece', 'density', 'contains'])],
            'quantity' => ['required', 'string', 'max:12'],
            'unit' => ['nullable', 'string', 'max:10'],
        ]);
        $grams = self::number($data['quantity']);
        if ($grams === null) {
            return response()->json(['message' => 'Poids invalide (ex. 120).', 'errors' => ['quantity' => ['Poids invalide.']]], 422);
        }

        if ($data['kind'] === 'contains') {
            // « 1 bouteille = 2 000 ml » : densité de l'eau supposée si elle n'est pas connue (boissons, bouillons…)
            $assumed = ! $ingredient->density;
            $density = $ingredient->density ?: 1.0;
            $ingredient->update(['density' => $density, 'piece_weight_g' => round($grams * $density, 2)]);
            $message = "1 pièce de « {$ingredient->name} » = ".Units::number($grams).' ml : retenu.'
                .($assumed ? ' (Densité de l\'eau supposée, à corriger dans la fiche de l\'ingrédient si besoin.)' : '');
        } elseif ($data['kind'] === 'piece') {
            $ingredient->update(['piece_weight_g' => $grams]);
            $message = "1 pièce de « {$ingredient->name} » = ".Units::number($grams).' g : retenu.';
        } else {
            $unit = $data['unit'] ?? 'cl';
            $ml = Units::exists($unit) && Units::dimension($unit) === Units::VOLUME ? Units::UNITS[$unit][2] : 10;
            $ingredient->update(['density' => round($grams / $ml, 4)]);
            $message = '1 '.Units::label(Units::exists($unit) ? $unit : 'cl')." de « {$ingredient->name} » = ".Units::number($grams).' g : retenu.';
        }

        return response()->json(self::payload($ingredient->fresh('units')) + ['message' => $message]);
    }

    /** Ce dont la page a besoin pour mettre la ligne à jour. */
    public static function payload(Ingredient $ingredient): array
    {
        return [
            'name' => $ingredient->name,
            'slug' => $ingredient->slug,
            'base' => $ingredient->base_unit,
            'piece' => $ingredient->piece_weight_g,
            'density' => $ingredient->density,
            'units' => $ingredient->units->mapWithKeys(fn ($u) => [$u->code() => $u->name])->all(),
        ];
    }

    private static function number(string $value): ?float
    {
        $value = str_replace(',', '.', trim($value));

        return is_numeric($value) && (float) $value > 0 && (float) $value <= 100000 ? (float) $value : null;
    }
}
