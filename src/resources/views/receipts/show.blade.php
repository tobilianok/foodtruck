@extends('layouts.app')

@section('title', 'Ticket · '.($receipt->store?->name ?? 'à préciser'))

@section('content')
    @php
        $products = $receipt->lines->where('kind', 'produit');
        $gap = $receipt->total_cents !== null ? $receipt->total_cents - $linesTotal : null;
        $euros = fn (?int $cents) => $cents === null ? '' : number_format($cents / 100, 2, ',', '');
        $aisleIds = $aisles->pluck('id', 'slug');
        $vision = $receipt->vision;
    @endphp

    <section class="hero hero-compact">
        <p><a href="{{ route('receipts.index') }}">← Tickets</a></p>
        <h1>{{ $receipt->store?->name ?? $receipt->correspondent ?? 'Ticket' }} <span class="muted">{{ $receipt->purchased_on?->format('d/m/Y') }}</span></h1>
        <p class="lead">
            {{ $products->count() }} article{{ $products->count() > 1 ? 's' : '' }} lu{{ $products->count() > 1 ? 's' : '' }}
            @if ($receipt->expected_lines) sur {{ $receipt->expected_lines }} annoncé{{ $receipt->expected_lines > 1 ? 's' : '' }} @endif
            · {{ \App\Models\Price::formatCents($linesTotal) }}
            @if ($receipt->total_cents !== null) sur un total de {{ \App\Models\Price::formatCents($receipt->total_cents) }} @endif
            · <span @class(['badge-warn' => $receipt->status === 'a_valider', 'badge-season' => $receipt->status === 'traite', 'badge-off' => $receipt->status === 'ignore'])>{{ $receipt->statusLabel() }}</span>
        </p>
        @if (is_array($vision) && isset($vision['modele']))
            <p class="hint small">
                Lu par l'IA ({{ $vision['modele'] }}) le {{ \Illuminate\Support\Carbon::parse($vision['lu_le'] ?? now())->timezone('Europe/Paris')->format('d/m à H:i') }}
                · {{ (int) ($vision['images'] ?? 1) }} image{{ (int) ($vision['images'] ?? 1) > 1 ? 's' : '' }} · {{ (int) ($vision['secondes'] ?? 0) }} s.
                Les calculs sont vérifiés par Foodtruck, pas par l'IA.
            </p>
        @endif
        @if ($receipt->vision_status === 'echec' && $receipt->vision_error)
            <div class="alert alert-error">
                Analyse par l'IA impossible : {{ $receipt->vision_error }}
                @if ($receipt->canBeSent()) <a href="{{ route('receipts.ai', $receipt) }}">Renvoyer à l'IA</a> @endif
            </div>
        @endif
        @if (! empty($receipt->unread_lines))
            <div class="alert alert-info">
                Ligne{{ count($receipt->unread_lines) > 1 ? 's' : '' }} illisible{{ count($receipt->unread_lines) > 1 ? 's' : '' }} sur le ticket, non prise{{ count($receipt->unread_lines) > 1 ? 's' : '' }} en compte :
                @foreach ($receipt->unread_lines as $unread)
                    <span class="mono">« {{ $unread }} »</span>@if (! $loop->last), @endif
                @endforeach
            </div>
        @endif
        @if (! empty($ignored))
            {{-- v0.21.0 : lignes recopiées par l'IA mais écartées pour retomber au centime sur le total imprimé --}}
            <details class="hint small">
                <summary>{{ count($ignored) }} ligne{{ count($ignored) > 1 ? 's' : '' }} recopiée{{ count($ignored) > 1 ? 's' : '' }} par l'IA mais écartée{{ count($ignored) > 1 ? 's' : '' }} (après le total, ou en double d'une page à l'autre)</summary>
                @foreach ($ignored as $label)
                    <span class="mono">« {{ $label }} »</span>@if (! $loop->last), @endif
                @endforeach
            </details>
        @endif
        @if ($receipt->paperlessUrl())
            <p class="small"><a href="{{ $receipt->paperlessUrl() }}" target="_blank" rel="noopener">Voir le ticket dans Paperless ↗</a></p>
        @endif
    </section>

    <section class="panel">
        <h2>Liste de courses</h2>
        <form method="post" action="{{ route('receipts.link', $receipt) }}" class="inline-form">
            @csrf
            <select name="list_id" aria-label="Liste de courses">
                <option value="">— aucune —</option>
                @foreach ($lists as $candidate)
                    <option value="{{ $candidate->id }}" @selected($receipt->shopping_list_id === $candidate->id)>Courses du {{ $candidate->periodLabel() }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-small">Enregistrer</button>
            @if ($receipt->shopping_list_id)
                <a href="{{ route('shopping.bilan', $receipt->shopping_list_id) }}" class="btn btn-small btn-ghost">Voir le bilan</a>
            @endif
        </form>
        <p class="hint small">Un ticket validé est rattaché tout seul à la liste de sa période. Les articles retrouvés sur le ticket sont cochés dans la liste, et le bilan compare le payé à l'estimé.</p>
    </section>

    <form method="post" action="{{ route('receipts.update', $receipt) }}" class="stack" data-receipt-review>
        @csrf @method('put')

        <section class="panel">
            <div class="grid-2">
                <label class="field">
                    <span>Magasin</span>
                    <select name="store_id" required>
                        <option value="">— à préciser —</option>
                        @foreach ($stores as $item)
                            <option value="{{ $item->id }}" @selected($receipt->store_id === $item->id)>{{ $item->name }}</option>
                        @endforeach
                    </select>
                    @if ($receipt->correspondent) <small>Correspondant Paperless : {{ $receipt->correspondent }}</small> @endif
                </label>
                <label class="field">
                    <span>Date d'achat</span>
                    <input type="date" name="purchased_on" value="{{ $receipt->purchased_on?->toDateString() }}" max="{{ now('Europe/Paris')->toDateString() }}" required>
                </label>
                <label class="field">
                    <span>Total du ticket (€)</span>
                    <input type="text" name="total" value="{{ $euros($receipt->total_cents) }}" inputmode="decimal" maxlength="12" placeholder="0,00" data-receipt-total>
                </label>
            </div>
        </section>

        <section class="panel">
            <h2>Articles</h2>
            {{-- v0.19.0 : chaque ticket est validé ; chaque correction se fait dans une fenêtre, sans quitter le ticket --}}
            <div class="check-summary" data-check-summary>
                <span data-check-text>
                    @if (count($problems))
                        <strong>{{ count($problems) }} ligne{{ count($problems) > 1 ? 's' : '' }} à vérifier</strong> : « Corriger » sur chaque ligne, le ticket reste ouvert.
                    @else
                        <strong>✓ Tout est vérifié</strong> : tu peux valider le ticket.
                    @endif
                </span>
                @php $proposals = collect($problems)->where('kind', 'approx')->count(); @endphp
                @if ($proposals > 1)
                    <button type="button" class="btn btn-small" data-accept-all>Confirmer les {{ $proposals }} propositions</button>
                @endif
                <span class="small" data-receipt-sum>
                    @if ($gap === null)
                        Somme des lignes : {{ \App\Models\Price::formatCents($linesTotal) }}
                    @elseif (abs($gap) <= 2)
                        ✓ Somme des lignes = total ({{ \App\Models\Price::formatCents($linesTotal) }})
                    @else
                        Écart de {{ \App\Models\Price::formatCents(abs($gap)) }} avec le total
                    @endif
                </span>
            </div>
            <p class="hint small">
                <span class="badge-season">reconnu</span> libellé déjà validé ·
                <span class="badge-cheap">proposé</span> à confirmer ·
                <span class="badge-warn">à associer</span> ingrédient à choisir ou créer. Tu peux aussi taper le nom de l'ingrédient directement dans la ligne.
            </p>
            <datalist id="pack-choices">
                @foreach ($choices as $choice)
                    <option value="{{ $choice }}"></option>
                @endforeach
            </datalist>

            <div class="receipt-lines">
                @foreach ($receipt->lines as $line)
                    @if ($line->kind !== 'produit')
                        <div class="receipt-line is-muted" data-remise="{{ $line->total_cents }}">
                            <div class="rl-label"><span class="mono">{{ $line->raw_label }}</span></div>
                            <div class="rl-price">{{ \App\Models\Price::formatCents($line->total_cents) }}</div>
                            <div class="rl-assoc muted small">remise globale, non rattachée</div>
                        </div>
                        @continue
                    @endif
                    @php
                        $current = $line->pack ? \App\Http\Controllers\ReceiptController::packChoiceLabel($line->pack) : ($line->ingredient?->name ?? '');
                        $problem = $problems[$line->id] ?? null;
                        $ignored = $line->status === 'ignore';
                        $base = $line->isWeighted() ? 'g' : (\App\Http\Controllers\ReceiptController::labelMeasure($line->normalized_label) ?? 'piece');
                        $quantity = $line->isWeighted() ? number_format($line->quantity, 3, ',', '') : rtrim(rtrim(number_format($line->quantity, 3, ',', ''), '0'), ',');
                    @endphp
                    <div @class(['receipt-line', 'is-muted' => $ignored, 'has-problem' => $problem !== null]) data-line
                         data-label="{{ $line->raw_label }}" data-aisle="{{ $aisleIds[\App\Http\Controllers\ReceiptController::guessAisle($line)] ?? '' }}"
                         data-base="{{ $base }}" data-discount="{{ (int) $line->discount_cents }}" data-status="{{ $line->status }}">
                        <input type="hidden" name="lines[{{ $line->id }}][action]" value="{{ $ignored ? 'ignorer' : 'associer' }}" data-field="action">
                        <input type="hidden" name="lines[{{ $line->id }}][quantity]" value="{{ $quantity }}" data-field="quantity">
                        <input type="hidden" name="lines[{{ $line->id }}][unit]" value="{{ $line->isWeighted() ? 'kg' : 'piece' }}" data-field="unit">
                        <input type="hidden" name="lines[{{ $line->id }}][unit_price]" value="{{ $euros($line->unit_price_cents) }}" data-field="unit_price">
                        <input type="hidden" name="lines[{{ $line->id }}][price]" value="{{ $euros($line->total_cents) }}" data-field="price">
                        <input type="hidden" name="lines[{{ $line->id }}][checked]" value="0" data-field="checked">
                        <div class="rl-label">
                            <span class="mono">{{ $line->raw_label }}</span>
                            <span class="muted small" data-line-detail>
                                @if ($line->hasUnknownWeight())
                                    pesée, poids illisible
                                @elseif ($line->isWeighted())
                                    {{ $line->quantityLabel() }} × {{ \App\Models\Price::formatCents($line->unit_price_cents) }}/kg
                                @elseif ($line->quantity != 1)
                                    {{ $line->quantityLabel() }} × {{ \App\Models\Price::formatCents($line->unit_price_cents) }}
                                @endif
                            </span>
                        </div>
                        <div class="rl-price">
                            <span data-line-price>{{ \App\Models\Price::formatCents($line->total_cents) }}</span>
                            @if ($line->discount_cents) <span class="badge-promo">−{{ \App\Models\Price::formatCents($line->discount_cents) }}</span> @endif
                        </div>
                        <div class="rl-assoc">
                            <input type="text" name="lines[{{ $line->id }}][choice]" value="{{ $current }}" list="pack-choices" data-field="choice"
                                   placeholder="Ingrédient ou Ingrédient — conditionnement" autocomplete="off" aria-label="Association pour {{ $line->raw_label }}" @disabled($ignored)>
                            <div class="rl-meta">
                                <span data-line-status>
                                    @switch($line->status)
                                        @case('reconnu') <span class="badge-season">reconnu</span> @break
                                        @case('propose') <span class="badge-cheap">proposé</span> @break
                                        @case('a_associer') <span class="badge-warn">à associer</span> @break
                                        @case('applique') <span class="badge-season">prix enregistré</span> @break
                                        @case('ignore') <span class="badge-off">ignoré</span> @break
                                    @endswitch
                                </span>
                                @if ($line->status === 'propose' && ! $line->pack && $line->ingredient)
                                    <span class="muted small">quantité du ticket absente du référentiel : le conditionnement sera créé</span>
                                @endif
                                @if ($line->isNonFoodVat())
                                    <span class="tag" title="Taux de TVA des produits non alimentaires (entretien, hygiène, alcool…)">TVA {{ rtrim(rtrim(number_format($line->vat_rate, 2, ',', ''), '0'), ',') }} %</span>
                                @elseif ($line->non_food)
                                    <span class="tag" title="Rubrique non alimentaire">{{ $line->section ?: 'non alimentaire' }}</span>
                                @endif
                                @if ($line->note && ! $problem)
                                    <span class="muted small">{{ $line->note }}</span>
                                @endif
                                @if ($line->pack_price_cents && $line->pack)
                                    <span class="muted small">→ {{ \App\Models\Price::formatCents($line->pack_price_cents) }} le conditionnement « {{ $line->pack->label }} »</span>
                                @endif
                            </div>
                        </div>
                        <div class="rl-action">
                            <button type="button" class="btn btn-small btn-ghost" data-fix data-kind="edit">Corriger</button>
                        </div>
                        @if ($problem)
                            <div class="row-warning" data-row-warning>
                                <span class="row-warning-text"><strong>À vérifier</strong> · {{ $problem['text'] }}</span>
                                @if ($problem['kind'] === 'approx' && $current !== '')
                                    <button type="button" class="btn btn-small fix-btn" data-accept>Oui, c'est « {{ $current }} »</button>
                                    <button type="button" class="btn btn-small btn-ghost" data-fix data-kind="approx" data-problem="{{ $problem['text'] }}">Autre choix</button>
                                @else
                                    <button type="button" class="btn btn-small fix-btn" data-fix data-kind="{{ $problem['kind'] }}" data-problem="{{ $problem['text'] }}">{{ $problem['button'] }}</button>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        <div class="panel sticky-bar">
            <label class="check">
                <input type="hidden" name="remember" value="0">
                <input type="checkbox" name="remember" value="1" checked>
                <span>Mémoriser les libellés pour les prochains tickets</span>
            </label>
            <button type="submit" class="btn">Valider le ticket et enregistrer les prix</button>
        </div>
    </form>

    <div class="row-actions ticket-actions">
        @if ($receipt->status !== 'ignore')
            <form method="post" action="{{ route('receipts.reparse', $receipt) }}">
                @csrf
                <button type="submit" class="btn btn-small btn-ghost" title="{{ $receipt->visionAnswer() ? 'Relit la réponse de l\'IA avec les règles à jour, sans rien renvoyer à l\'IA' : 'Relit le texte du ticket avec les règles à jour' }}">Relire le ticket</button>
            </form>
        @endif
        @if ($receipt->canBeSent() && $receipt->vision_status !== 'echec')
            <a href="{{ route('receipts.ai', $receipt) }}" class="btn btn-small btn-ghost">Renvoyer à l'IA</a>
        @endif
        <form method="post" action="{{ route('receipts.ignore', $receipt) }}">
            @csrf
            <button type="submit" class="btn btn-small btn-ghost">{{ $receipt->status === 'ignore' ? 'Remettre à valider' : 'Ignorer ce ticket' }}</button>
        </form>
    </div>

    <details class="panel">
        @if ($receipt->visionAnswer())
            <summary>Ce que l'IA a recopié</summary>
            <pre class="mono raw-text">{{ json_encode($receipt->visionAnswer(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        @else
            <summary>Texte du ticket</summary>
            <pre class="mono raw-text">{{ $receipt->raw_text }}</pre>
        @endif
    </details>

    {{-- v0.19.0 : fenêtre de correction d'une ligne (lecture, ingrédient à choisir ou créer, ignorer), sans quitter le ticket --}}
    <dialog class="fix-dialog" id="receipt-fix" aria-labelledby="receipt-fix-title">
        <form method="dialog" class="fix-form" novalidate>
            <header class="fix-head">
                <h2 id="receipt-fix-title">Corriger la ligne</h2>
                <button type="submit" value="cancel" class="btn btn-icon" aria-label="Fermer">✕</button>
            </header>
            <div class="fix-body" data-fix-body></div>
            <p class="fix-error" data-fix-error hidden></p>
        </form>
    </dialog>
    <template id="fix-aisles">
        @foreach ($aisles as $aisle)
            <option value="{{ $aisle->id }}">{{ $aisle->name }}</option>
        @endforeach
    </template>
    <template id="fix-bases">
        @foreach (\App\Support\Units::BASE_CHOICES as $code => $label)
            <option value="{{ $code }}">{{ $label }}</option>
        @endforeach
    </template>
    <script type="application/json" id="receipt-fix-routes">@json(['create' => route('ingredients.quick.store')])</script>
@endsection
