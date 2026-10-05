<?php

namespace App\Http\Controllers;

use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\IngredientPack;
use App\Models\Receipt;
use App\Models\ReceiptLine;
use App\Models\Store;
use App\Support\ListReconciliation;
use App\Support\Receipts\PaperlessClient;
use App\Support\Receipts\ReceiptMatcher;
use App\Support\Receipts\ReceiptProcessor;
use App\Support\Receipts\ReceiptSync;
use App\Support\Units;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Tickets de caisse : synchronisation Paperless, saisie manuelle, rapprochement et prix réels.
 * Visibles et traitables par tous les membres du foyer ; réglages Paperless réservés aux admins.
 */
class ReceiptController extends Controller
{
    public function index(Request $request)
    {
        $household = $request->user()->household;
        $status = $request->query('statut');

        $receipts = $household->receipts()->with(['store', 'lines'])
            ->when(in_array($status, array_keys(Receipt::STATUSES), true), fn ($q) => $q->where('status', $status))
            ->orderByRaw("case when status = 'a_valider' then 0 else 1 end")
            ->orderByDesc('purchased_on')->orderByDesc('id')
            ->paginate(30)->withQueryString();

        return view('receipts.index', [
            'household' => $household,
            'receipts' => $receipts,
            'status' => $status,
            'counts' => $household->receipts()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'stores' => Store::active(),
            'canManage' => $request->user()->can('manage-household'),
        ]);
    }

    public function sync(Request $request, ReceiptSync $sync)
    {
        $household = $request->user()->household;
        abort_unless($household->hasPaperless(), 404);

        $counts = $sync->run($household);

        return $counts['error']
            ? back()->withErrors(['paperless' => ReceiptSync::summary($counts)])
            : back()->with('status', ReceiptSync::summary($counts));
    }

    /** Ticket saisi à la main (copier-coller d'un ticket dématérialisé hors Paperless). */
    public function store(Request $request, ReceiptProcessor $processor)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'purchased_on' => ['required', 'date', 'before_or_equal:'.now('Europe/Paris')->toDateString(), 'after:2020-01-01'],
            'raw_text' => ['required', 'string', 'min:10', 'max:30000'],
        ], [], ['store_id' => 'magasin', 'purchased_on' => 'date d\'achat', 'raw_text' => 'texte du ticket']);

        $receipt = Receipt::create([
            'household_id' => $request->user()->household_id,
            'source' => 'manuel',
            'store_id' => $data['store_id'],
            'purchased_on' => $data['purchased_on'],
            'raw_text' => $data['raw_text'],
        ]);
        $processor->ingest($receipt);

        if ($receipt->lines()->count() === 0) {
            return redirect()->route('receipts.show', $receipt)->withErrors(['raw_text' => 'Aucune ligne d\'article n\'a été reconnue dans ce texte.']);
        }

        return redirect()->route('receipts.show', $receipt)->with('status', $receipt->status === Receipt::STATUS_DONE
            ? 'Ticket lu et entièrement reconnu : les prix sont enregistrés.'
            : 'Ticket lu : vérifie les associations puis enregistre les prix.');
    }

    public function show(Request $request, Receipt $receipt)
    {
        $this->authorizeReceipt($request, $receipt);
        $receipt->load(['store', 'lines.pack.ingredient', 'lines.ingredient', 'lines.price', 'household', 'processor']);

        return view('receipts.show', [
            'receipt' => $receipt,
            'stores' => Store::active(),
            'choices' => self::packChoices(),
            'aisles' => Aisle::ordered(),
            'linesTotal' => $receipt->linesTotalCents(),
            'lists' => $receipt->household->shoppingLists()->limit(8)->get(),
        ]);
    }

    /** Rattache le ticket à une liste de courses (ou le détache) et coche les articles retrouvés. */
    public function link(Request $request, Receipt $receipt)
    {
        $this->authorizeReceipt($request, $receipt);
        $data = $request->validate(['list_id' => ['nullable', 'integer']]);

        $list = null;
        if (! empty($data['list_id'])) {
            $list = $request->user()->household->shoppingLists()->find($data['list_id']);
            abort_if($list === null, 404);
        }

        $ticked = ListReconciliation::link($receipt, $list);

        if ($list === null) {
            return redirect()->route('receipts.show', $receipt)->with('status', 'Ticket détaché de sa liste de courses.');
        }

        return redirect()->route('receipts.show', $receipt)->with('status', 'Ticket rattaché à la liste du '.$list->periodLabel().'.'
            .($ticked > 0 ? ' '.$ticked.' article'.($ticked > 1 ? 's' : '').' coché'.($ticked > 1 ? 's' : '').' d\'après le ticket.' : '')
            .($list->isArchived() && $ticked === 0 ? ' La liste est classée : ses cases ne sont pas modifiées.' : ''));
    }

    /** Validation du rapprochement et enregistrement des prix. */
    public function update(Request $request, Receipt $receipt, ReceiptProcessor $processor)
    {
        $this->authorizeReceipt($request, $receipt);

        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'purchased_on' => ['required', 'date', 'before_or_equal:'.now('Europe/Paris')->toDateString(), 'after:2020-01-01'],
            'lines' => ['nullable', 'array'],
            'lines.*.choice' => ['nullable', 'string', 'max:200'],
            'lines.*.action' => ['nullable', Rule::in(['associer', 'creer', 'ignorer', 'ignorer_toujours'])],
            'lines.*.aisle_id' => ['nullable', 'integer', 'exists:aisles,id'],
            'remember' => ['nullable', 'boolean'],
        ], [], ['store_id' => 'magasin', 'purchased_on' => 'date d\'achat']);

        $receipt->load('lines.pack.ingredient');
        $storeChanged = (int) $data['store_id'] !== $receipt->store_id;
        $receipt->forceFill(['store_id' => (int) $data['store_id'], 'purchased_on' => $data['purchased_on']])->save();
        $store = Store::find($receipt->store_id);

        if ($storeChanged && $receipt->correspondent) {
            ReceiptProcessor::learnStoreName($store, $receipt->correspondent);
        }

        $packs = self::packIndex();
        $unresolved = [];
        $created = [];
        $remember = $request->boolean('remember', true);

        foreach ($receipt->lines->where('kind', 'produit') as $line) {
            $input = $data['lines'][$line->id] ?? null;
            if ($input === null) {
                continue;
            }

            $action = $input['action'] ?? 'associer';

            if ($action === 'ignorer' || $action === 'ignorer_toujours') {
                $line->forceFill(['status' => ReceiptLine::STATUS_IGNORED, 'ingredient_pack_id' => null])->save();
                if ($action === 'ignorer_toujours') {
                    $processor->remember($receipt->store_id, $line->normalized_label, null, true, $request->user());
                }

                continue;
            }

            $choice = trim((string) ($input['choice'] ?? ''));
            if ($choice === '') {
                if ($line->status !== ReceiptLine::STATUS_APPLIED) {
                    $line->forceFill(['status' => ReceiptLine::STATUS_UNKNOWN, 'ingredient_pack_id' => null])->save();
                }

                continue;
            }

            if ($action === 'creer') {
                $ingredient = $this->createIngredient($choice, isset($input['aisle_id']) ? (int) $input['aisle_id'] : null, $line, $request);
                $pack = $ingredient ? self::packFromTicket($ingredient, $line) : null;
                if ($ingredient && $ingredient->wasRecentlyCreated) {
                    $created[] = $ingredient->name;
                    $packs = self::packIndex();
                }
            } else {
                $pack = $this->resolveChoice($choice, $line, $packs, $request);
            }

            if (! $pack) {
                // La ligne reste à associer : le ticket ne passe pas en « traité »
                if ($line->status !== ReceiptLine::STATUS_APPLIED) {
                    $line->forceFill(['status' => ReceiptLine::STATUS_UNKNOWN, 'ingredient_pack_id' => null])->save();
                }
                $unresolved[] = "« {$line->raw_label} » → « {$choice} »";

                continue;
            }

            // Changement d'association : le prix déjà enregistré est corrigé à l'application
            $line->forceFill([
                'ingredient_pack_id' => $pack->id,
                'status' => $line->status === ReceiptLine::STATUS_APPLIED && $line->ingredient_pack_id === $pack->id
                    ? ReceiptLine::STATUS_APPLIED
                    : ReceiptLine::STATUS_SUGGESTED,
            ])->save();
        }

        $applied = $processor->apply($receipt->fresh(), $request->user(), $remember);

        $redirect = redirect()->route('receipts.show', $receipt);
        if ($unresolved !== []) {
            $redirect->withErrors(['lines' => 'Ingrédient introuvable pour : '.implode(', ', $unresolved).'. Choisis un élément de la liste, ou « Créer l\'ingrédient » dans le menu de la ligne pour l\'ajouter au référentiel.']);
        }

        $receipt->refresh();

        return $redirect->with('status', $applied.' prix enregistré'.($applied > 1 ? 's' : '').' pour '.$store->name.' ('.$receipt->purchased_on->format('d/m/Y').').'
            .($created !== [] ? ' Ingrédient'.(count($created) > 1 ? 's' : '').' ajouté'.(count($created) > 1 ? 's' : '').' au référentiel : '.implode(', ', $created).' (fiche à compléter dans Ingrédients).' : '')
            .($receipt->status === Receipt::STATUS_DONE ? ' Ticket traité.' : ' Il reste des lignes à associer ou à ignorer.'));
    }

    public function reparse(Request $request, Receipt $receipt, ReceiptProcessor $processor)
    {
        $this->authorizeReceipt($request, $receipt);
        abort_if($receipt->status === Receipt::STATUS_IGNORED, 409);

        $receipt->total_cents = null;
        $processor->ingest($receipt);

        return back()->with('status', $receipt->status === Receipt::STATUS_DONE
            ? 'Ticket relu : tout est reconnu, prix mis à jour.'
            : 'Ticket relu avec les règles de lecture à jour. Les libellés déjà validés sont reconnus.');
    }

    public function ignore(Request $request, Receipt $receipt)
    {
        $this->authorizeReceipt($request, $receipt);
        $receipt->forceFill([
            'status' => $receipt->status === Receipt::STATUS_IGNORED ? Receipt::STATUS_TO_REVIEW : Receipt::STATUS_IGNORED,
        ])->save();

        return redirect()->route('receipts.index')->with('status', $receipt->status === Receipt::STATUS_IGNORED
            ? 'Ticket ignoré : il ne sera plus proposé.'
            : 'Ticket remis à valider.');
    }

    /** Réglages Paperless du foyer (admins). */
    public function settings(Request $request)
    {
        $household = $request->user()->household;

        $data = $request->validate([
            'paperless_url' => ['nullable', 'url:http,https', 'max:255'],
            'paperless_token' => ['nullable', 'string', 'max:255'],
            'paperless_tag' => ['nullable', 'string', 'max:80'],
            'paperless_recipe_tag' => ['nullable', 'string', 'max:80'],
            'disconnect' => ['nullable', 'boolean'],
        ], [], ['paperless_url' => 'adresse de Paperless', 'paperless_token' => 'jeton', 'paperless_tag' => 'étiquette des tickets', 'paperless_recipe_tag' => 'étiquette des recettes']);

        if ($request->boolean('disconnect')) {
            $household->forceFill(['paperless_url' => null, 'paperless_token' => null, 'paperless_last_error' => null])->save();

            return back()->with('status', 'Paperless déconnecté. Les tickets déjà importés sont conservés.');
        }

        if (empty($data['paperless_url'])) {
            throw ValidationException::withMessages(['paperless_url' => 'Indique l\'adresse de Paperless, par exemple http://192.168.1.14:8010.']);
        }
        if (empty($data['paperless_token']) && ! $household->paperless_token) {
            throw ValidationException::withMessages(['paperless_token' => 'Indique le jeton d\'API du compte Paperless dédié.']);
        }

        // Jeton collé avec « Token » devant, des espaces ou un retour à la ligne
        $data['paperless_token'] = PaperlessClient::cleanToken($data['paperless_token'] ?? null);
        if ($data['paperless_token'] !== null && ! preg_match('/^[A-Za-z0-9]{20,128}$/', $data['paperless_token'])) {
            throw ValidationException::withMessages(['paperless_token' => 'Ce jeton ne ressemble pas à un jeton d\'API Paperless (40 caractères, lettres et chiffres). Vérifie qu\'un gestionnaire de mots de passe ne l\'a pas rempli à ta place.']);
        }

        $tag = trim((string) ($data['paperless_tag'] ?? '')) ?: 'courses alimentaires';
        $client = new PaperlessClient(rtrim($data['paperless_url'], '/'), $data['paperless_token'] ?: (string) $household->paperless_token);

        try {
            $check = $client->check($tag);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['paperless_url' => $e->getMessage()]);
        }

        // Étiquette des fiches de recettes : vérifiée seulement si elle change (« recettes » par défaut)
        $recipeTag = trim((string) ($data['paperless_recipe_tag'] ?? '')) ?: 'recettes';
        $recipeTagNote = '';
        if ($recipeTag !== $household->paperlessRecipeTag() || ! $household->paperless_recipe_tag) {
            try {
                $client->tagId($recipeTag);
            } catch (Throwable $e) {
                if ($recipeTag !== 'recettes' || $household->paperless_recipe_tag) {
                    throw ValidationException::withMessages(['paperless_recipe_tag' => $e->getMessage()]);
                }
                $recipeTagNote = " L'étiquette « recettes » n'existe pas encore dans Paperless : crée-la pour y déposer tes fiches de recettes.";
            }
        }

        $household->forceFill([
            'paperless_url' => rtrim($data['paperless_url'], '/'),
            'paperless_token' => $data['paperless_token'] ?: $household->paperless_token,
            'paperless_tag' => $tag,
            'paperless_recipe_tag' => $recipeTag,
            'paperless_last_error' => null,
        ])->save();

        return back()->with('status', "Paperless relié : {$check['documents']} document(s) avec l'étiquette « {$tag} ». Lance une synchronisation depuis « Tickets ».".$recipeTagNote);
    }

    private function authorizeReceipt(Request $request, Receipt $receipt): void
    {
        abort_unless($receipt->household_id === $request->user()->household_id, 404);
    }

    /**
     * Interprète le choix saisi : « Ingrédient — Conditionnement » ou « Ingrédient » seul
     * (conditionnement déduit du ticket, créé si la quantité lue n'existe pas encore).
     */
    private function resolveChoice(string $choice, ReceiptLine $line, array $packs, Request $request): ?IngredientPack
    {
        $key = Str::lower(Str::ascii($choice));
        if (isset($packs['packs'][$key])) {
            return IngredientPack::with('ingredient')->find($packs['packs'][$key]);
        }

        if (! isset($packs['ingredients'][$key])) {
            return null;
        }

        return self::packFromTicket(Ingredient::with('packs')->find($packs['ingredients'][$key]), $line);
    }

    /** Conditionnement correspondant à la ligne : existant si la quantité concorde, sinon créé d'après le ticket. */
    private static function packFromTicket(Ingredient $ingredient, ReceiptLine $line): ?IngredientPack
    {
        $weighted = $line->isWeighted();
        $matcher = new ReceiptMatcher(collect([$ingredient]));
        $pack = $matcher->choosePack($ingredient, $line->normalized_label, $weighted);
        $quantity = $weighted ? null : ReceiptMatcher::labelQuantity($line->normalized_label, $ingredient);

        if ($pack && ($weighted || $quantity === null || abs($pack->quantity - $quantity) <= $pack->quantity * 0.02)) {
            return $pack->setRelation('ingredient', $ingredient);
        }

        $spec = ReceiptMatcher::ticketPack($ingredient, $line->normalized_label, $weighted);
        if ($spec === null) {
            return null;
        }

        $existing = $ingredient->packs->first(fn (IngredientPack $p) => $p->label === $spec['label'] || (! $weighted && abs($p->quantity - $spec['quantity']) <= $p->quantity * 0.02));
        if ($existing) {
            return $existing->setRelation('ingredient', $ingredient);
        }

        return $ingredient->packs()->create([
            'label' => $spec['label'],
            'quantity' => $spec['quantity'],
            'is_bulk' => $spec['is_bulk'],
            'position' => ((int) $ingredient->packs()->max('position')) + 10,
        ])->setRelation('ingredient', $ingredient);
    }

    /**
     * Nouvel ingrédient créé depuis une ligne de ticket : unité déduite du libellé (poids, volume, pièce),
     * rayon choisi (ou déduit de la rubrique Leclerc Drive). Renvoie null si le nom est invalide.
     */
    private function createIngredient(string $name, ?int $aisleId, ReceiptLine $line, Request $request): ?Ingredient
    {
        $name = Str::limit(trim(preg_replace('/\s+/u', ' ', $name)), 80, '');
        $slug = Str::slug($name);
        if (mb_strlen($name) < 2 || $slug === '') {
            return null;
        }

        if ($existing = Ingredient::with('packs')->where('slug', $slug)->first()) {
            return $existing;
        }

        $aisle = ($aisleId ? Aisle::find($aisleId) : null) ?? Aisle::firstWhere('slug', self::guessAisle($line)) ?? Aisle::ordered()->first();
        $measure = self::labelMeasure($line->normalized_label);
        $baseUnit = $line->isWeighted() ? 'g' : ($measure ?? 'piece');

        return Ingredient::create([
            'name' => Str::ucfirst($name),
            'slug' => $slug,
            'aisle_id' => $aisle->id,
            'base_unit' => $baseUnit,
            'is_fresh' => in_array($aisle->slug, ['fruits-legumes', 'boucherie', 'poissonnerie', 'cremerie', 'fromages', 'charcuterie-traiteur', 'boulangerie'], true),
            'is_staple' => false,
            'created_by' => $request->user()->id,
        ])->load('packs');
    }

    /** Unité suggérée par le libellé : « 500g » → g, « 1L », « 3x20cl » → ml. */
    private static function labelMeasure(string $normalized): ?string
    {
        if (preg_match('/\d\s*(KG|G)\b/', $normalized)) {
            return 'g';
        }

        return preg_match('/\d\s*(L|CL|ML)\b/', $normalized) ? 'ml' : null;
    }

    /** Rayon déduit de la rubrique Leclerc Drive (« LAITIER OEUFS VÉGÉTAL »), sinon épicerie salée. */
    public static function guessAisle(ReceiptLine $line): string
    {
        $section = Str::upper(Str::ascii((string) $line->section));
        $map = [
            'FRUIT' => 'fruits-legumes', 'LEGUME' => 'fruits-legumes', 'POISSON' => 'poissonnerie', 'VIANDE' => 'boucherie',
            'BOUCHER' => 'boucherie', 'VOLAILLE' => 'boucherie', 'FROMAGE' => 'fromages', 'LAITIER' => 'cremerie', 'CREMERIE' => 'cremerie',
            'OEUF' => 'cremerie', 'CHARCUTERIE' => 'charcuterie-traiteur', 'TRAITEUR' => 'charcuterie-traiteur', 'PAIN' => 'boulangerie',
            'BOULANGERIE' => 'boulangerie', 'SURGELE' => 'surgeles', 'SUCRE' => 'epicerie-sucree', 'SALE' => 'epicerie-salee',
            'BOISSON' => 'boissons', 'BEBE' => 'bebe', 'HYGIENE' => 'maison', 'ENTRETIEN' => 'maison',
        ];
        foreach ($map as $word => $slug) {
            if ($section !== '' && str_contains($section, $word)) {
                return $slug;
            }
        }

        return $line->isWeighted() ? 'fruits-legumes' : 'epicerie-salee';
    }

    /** Libellés proposés dans la liste de choix : « Ingrédient — Conditionnement » et « Ingrédient ». */
    public static function packChoices(): array
    {
        $choices = [];
        foreach (Ingredient::with('packs')->get()->sortBy(fn ($i) => Str::lower(Str::ascii($i->name))) as $ingredient) {
            $choices[] = $ingredient->name;
            foreach ($ingredient->packs as $pack) {
                $choices[] = self::packChoiceLabel($pack, $ingredient);
            }
        }

        return $choices;
    }

    public static function packChoiceLabel(IngredientPack $pack, ?Ingredient $ingredient = null): string
    {
        return ($ingredient ?? $pack->ingredient)->name.' — '.$pack->label;
    }

    /** @return array{packs: array<string, int>, ingredients: array<string, int>} */
    private static function packIndex(): array
    {
        $index = ['packs' => [], 'ingredients' => []];
        foreach (Ingredient::with('packs')->get() as $ingredient) {
            $index['ingredients'][Str::lower(Str::ascii($ingredient->name))] = $ingredient->id;
            foreach ($ingredient->packs as $pack) {
                $index['packs'][Str::lower(Str::ascii(self::packChoiceLabel($pack, $ingredient)))] = $pack->id;
            }
        }

        return $index;
    }
}
