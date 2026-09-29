<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\IngredientPack;
use App\Models\Receipt;
use App\Models\ReceiptLine;
use App\Models\Store;
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
        $receipt->load(['store', 'lines.pack.ingredient', 'lines.price', 'household', 'processor']);

        return view('receipts.show', [
            'receipt' => $receipt,
            'stores' => Store::active(),
            'choices' => self::packChoices(),
            'linesTotal' => $receipt->linesTotalCents(),
        ]);
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
            'lines.*.action' => ['nullable', Rule::in(['associer', 'ignorer', 'ignorer_toujours'])],
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

            $pack = $this->resolveChoice($choice, $line, $packs, $request);
            if (! $pack) {
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
            $redirect->withErrors(['lines' => 'Association non comprise pour : '.implode(', ', $unresolved).'. Choisis un élément de la liste proposée.']);
        }

        $receipt->refresh();

        return $redirect->with('status', $applied.' prix enregistré'.($applied > 1 ? 's' : '').' pour '.$store->name.' ('.$receipt->purchased_on->format('d/m/Y').').'
            .($receipt->status === Receipt::STATUS_DONE ? ' Ticket traité.' : ' Il reste des lignes à associer ou à ignorer.'));
    }

    public function reparse(Request $request, Receipt $receipt, ReceiptProcessor $processor)
    {
        $this->authorizeReceipt($request, $receipt);
        abort_if($receipt->status === Receipt::STATUS_DONE, 409);

        $receipt->total_cents = null;
        $processor->ingest($receipt);

        return back()->with('status', 'Ticket relu.');
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
            'disconnect' => ['nullable', 'boolean'],
        ], [], ['paperless_url' => 'adresse de Paperless', 'paperless_token' => 'jeton', 'paperless_tag' => 'étiquette']);

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

        $tag = trim((string) ($data['paperless_tag'] ?? '')) ?: 'courses alimentaires';
        $client = new PaperlessClient(rtrim($data['paperless_url'], '/'), $data['paperless_token'] ?: (string) $household->paperless_token);

        try {
            $check = $client->check($tag);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['paperless_url' => $e->getMessage()]);
        }

        $household->forceFill([
            'paperless_url' => rtrim($data['paperless_url'], '/'),
            'paperless_token' => $data['paperless_token'] ?: $household->paperless_token,
            'paperless_tag' => $tag,
            'paperless_last_error' => null,
        ])->save();

        return back()->with('status', "Paperless relié : {$check['documents']} document(s) avec l'étiquette « {$tag} ». Lance une synchronisation depuis « Tickets ».");
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

        $ingredient = Ingredient::with('packs')->find($packs['ingredients'][$key]);
        $matcher = new ReceiptMatcher(collect([$ingredient]));
        $quantity = ReceiptMatcher::labelQuantity($line->normalized_label, $ingredient);

        if ($line->isWeighted() || $quantity === null || $ingredient->packs->contains(fn ($p) => abs($p->quantity - $quantity) <= $p->quantity * 0.02)) {
            $pack = $matcher->choosePack($ingredient, $line->normalized_label, $line->isWeighted());
            if ($pack) {
                return $pack->setRelation('ingredient', $ingredient);
            }
        }

        if ($line->isWeighted() && $ingredient->base_unit === 'g') {
            return $ingredient->packs()->create(['label' => 'Vrac au kg', 'quantity' => 1000, 'is_bulk' => true, 'position' => 5])->setRelation('ingredient', $ingredient);
        }

        if ($quantity === null) {
            return null;
        }

        return $ingredient->packs()->create([
            'label' => Str::ucfirst(Units::format($quantity, $ingredient->base_unit)).' (ticket)',
            'quantity' => $quantity,
            'is_bulk' => false,
            'position' => ((int) $ingredient->packs()->max('position')) + 10,
        ])->setRelation('ingredient', $ingredient);
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
