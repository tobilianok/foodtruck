<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use App\Support\RecipeWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * v0.22.0 : étiquettes des recettes (« Viandes », « Accompagnement », « Fêtes »…). Comme les recettes, elles sont
 * communes à tous : chacun peut en créer (ici ou depuis le formulaire d'une recette) ; renommer et supprimer sont
 * réservés aux administrateurs, une étiquette servant à tout le monde.
 */
class TagController extends Controller
{
    public function index(Request $request)
    {
        return view('recipes.tags', [
            'tags' => Tag::query()->withCount('recipes')->orderBy('position')->orderBy('name')->get(),
            'canManage' => $request->user()->isAdmin(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:200']], [], ['name' => 'étiquette']);
        $names = RecipeWriter::tagNames($data['name']);
        if ($names === [] || count($names) > 10) {
            return back()->withErrors(['name' => 'Indique de 1 à 10 étiquettes, séparées par des virgules.'])->withInput();
        }
        foreach ($names as $name) {
            if (mb_strlen($name) > Tag::NAME_MAX || Str::slug($name) === '') {
                return back()->withErrors(['name' => 'Étiquette « '.Str::limit($name, 30).' » : '.Tag::NAME_MAX.' caractères au plus, avec au moins une lettre ou un chiffre.'])->withInput();
            }
        }

        $created = [];
        $existing = [];
        foreach ($names as $name) {
            $tag = Tag::findOrCreateNamed($name);
            $tag->wasRecentlyCreated ? $created[] = $tag->name : $existing[] = $tag->name;
        }

        $message = $created ? 'Étiquette'.(count($created) > 1 ? 's' : '').' créée'.(count($created) > 1 ? 's' : '').' : '.implode(', ', $created).'.' : '';
        if ($existing) {
            $message .= ($message ? ' ' : '').'Déjà là : '.implode(', ', $existing).'.';
        }

        return redirect()->route('recipes.tags')->with('status', $message);
    }

    public function update(Request $request, Tag $tag)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:'.Tag::NAME_MAX]], [], ['name' => 'étiquette']);
        $name = Str::ucfirst(trim(preg_replace('/\s+/u', ' ', $data['name'])));
        $slug = Str::limit(Str::slug($name), Tag::NAME_MAX, '');
        $request->merge(['slug' => $slug]);
        $request->validate(['slug' => ['required', Rule::unique('tags', 'slug')->ignore($tag->id)]], ['slug.unique' => 'Une autre étiquette porte déjà ce nom.', 'slug.required' => 'Le nom doit contenir au moins une lettre ou un chiffre.']);

        // L'adresse du filtre (slug) des étiquettes de départ ne change pas
        $tag->update(['name' => $name] + ($tag->isBuiltIn() ? [] : ['slug' => $slug]));

        return redirect()->route('recipes.tags')->with('status', 'Étiquette renommée : « '.$tag->name.' ».');
    }

    public function destroy(Request $request, Tag $tag)
    {
        abort_unless($request->user()->isAdmin(), 403);
        if ($tag->isBuiltIn()) {
            return redirect()->route('recipes.tags')->with('status', '« '.$tag->name.' » est une étiquette de départ (le menu automatique s\'en sert) : elle peut être renommée, pas supprimée.');
        }
        $count = $tag->recipes()->count();
        $tag->delete();

        return redirect()->route('recipes.tags')->with('status', 'Étiquette « '.$tag->name.' » supprimée'.($count ? ', retirée de '.$count.' recette'.($count > 1 ? 's' : '') : '').'.');
    }
}
