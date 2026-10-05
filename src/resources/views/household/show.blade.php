@extends('layouts.app')

@section('title', 'Mon foyer')

@section('content')
    <x-page-header :title="$household->name"
        :lead="$household->members->count().' personne'.($household->members->count() > 1 ? 's' : '').' · '.rtrim(rtrim(number_format($household->totalPortions(), 2, ',', ' '), '0'), ',').' parts par repas · budget '.number_format($household->budgetEuros(), 0, ',', ' ').' € par semaine'" />
    @unless ($canManage)
        <p class="hint">Seuls les administrateurs du foyer peuvent modifier ces informations.</p>
    @endunless

    <nav class="jump" aria-label="Sections">
        <a class="chip is-static" href="#personnes">Personnes</a>
        <a class="chip is-static" href="#reglages">Budget et magasins</a>
        <a class="chip is-static" href="#semaine-type">Semaine type</a>
        <a class="chip is-static" href="#appareils">Appareils</a>
        <a class="chip is-static" href="#comptes">Comptes</a>
        @if ($canManage) <a class="chip is-static" href="#avance">Avancé</a> @endif
    </nav>

    @if (session('invitation_link'))
        <div class="alert alert-ok invitation-link">
            <p><strong>Lien à envoyer</strong> (affiché une seule fois) :</p>
            <div class="copy-row">
                <input type="text" value="{{ session('invitation_link') }}" readonly data-copy-source>
                <button type="button" class="btn" data-copy>Copier</button>
            </div>
            <small>La personne doit avoir un compte Authentik dans le groupe foodtruck.</small>
        </div>
    @endif

    {{-- Membres --}}
    <section class="panel" id="personnes">
        <h2>Les personnes du foyer</h2>
        <details class="more"><summary>Comment sont calculées les parts ?</summary><p class="hint">        Avec la date de naissance, le coefficient suit l'âge tout seul, à la date de chaque repas : {{ \App\Models\HouseholdMember::gridSummary() }}.
            Sans date de naissance, le coefficient saisi reste fixe ; « Régler à la main » le fixe aussi (ex. gros mangeur : 1,5).</p></details>

        <div class="list">
            @foreach ($household->members as $member)
                @if ($canManage)
                    <form method="post" action="{{ route('household.members.update', $member) }}" class="member-row">
                        @csrf @method('put')
                        @include('household._member-fields', ['prefix' => '', 'member' => $member])
                        <label class="field">
                            <span>Compte lié</span>
                            <select name="user_id">
                                <option value="">Aucun</option>
                                @foreach ($household->users as $account)
                                    <option value="{{ $account->id }}" @selected($member->user_id === $account->id)>{{ $account->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <div class="row-actions">
                            <button type="submit" class="btn btn-small">Enregistrer</button>
                            <button type="submit" class="btn btn-small btn-danger" form="delete-member-{{ $member->id }}"
                                    data-confirm="Retirer {{ $member->name }} du foyer ?">Retirer</button>
                        </div>
                    </form>
                    <form method="post" action="{{ route('household.members.destroy', $member) }}" id="delete-member-{{ $member->id }}" hidden>
                        @csrf @method('delete')
                    </form>
                @else
                    <div class="member-line">
                        <strong>{{ $member->name }}</strong>
                        @if ($member->ageLabel()) <span>{{ $member->ageLabel() }}</span> @else <span>{{ $member->categoryLabel() }}</span> @endif
                        <span>coefficient {{ str_replace('.', ',', (string) $member->coefficientOn()) }}</span>
                        @if ($member->user)
                            <span class="tag">{{ $member->user->name }}</span>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>

        @if ($canManage)
            <details class="add-block">
                <summary>Ajouter une personne</summary>
                <form method="post" action="{{ route('household.members.store') }}" class="member-row">
                    @csrf
                    @include('household._member-fields', ['prefix' => '', 'member' => ['name' => '', 'coefficient' => 1]])
                    <div class="row-actions"><button type="submit" class="btn btn-small">Ajouter</button></div>
                </form>
            </details>
        @endif
    </section>

    {{-- Réglages --}}
    <section class="panel" id="reglages">
        <h2>Budget et magasins</h2>
        @if ($canManage)
            <form method="post" action="{{ route('household.settings') }}" class="grid-2 align-end">
                @csrf @method('put')
                <label class="field">
                    <span>Nom du foyer</span>
                    <input type="text" name="name" value="{{ $household->name }}" maxlength="60" required>
                </label>
                <label class="field">
                    <span>Budget courses par semaine</span>
                    <span class="input-suffix">
                        <input type="number" name="budget" value="{{ $household->budgetEuros() }}" min="10" max="2000" step="1" required>
                        <span>€</span>
                    </span>
                </label>
                @include('household.store-fields', ['main' => $household->main_store_id, 'produce' => $household->produce_store_id])
                <fieldset class="field span-2 serving-row">
                    <legend>Repas du planning</legend>
                    @foreach (\App\Models\MealPlanEntry::SLOTS as $code => [$label])
                        <label class="chip-check">
                            <input type="checkbox" name="meal_slots[]" value="{{ $code }}" @checked(in_array($code, $household->mealSlots(), true))>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </fieldset>
                <div class="actions"><button type="submit" class="btn">Enregistrer</button></div>
            </form>
        @else
            <p>Budget : {{ number_format($household->budgetEuros(), 0, ',', ' ') }} € par semaine.</p>
            <p>Magasin principal : {{ $household->mainStore->name ?? 'non défini' }} · fruits et légumes : {{ $household->produceStore->name ?? 'non défini' }}.</p>
        @endif
    </section>

    {{-- Semaine type --}}
    @php
        $eatingSlots = $household->eatingSlots();
        $weekdays = [1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam', 7 => 'Dim'];
    @endphp
    @if ($household->members->isNotEmpty() && $eatingSlots !== [])
        <section class="panel" id="semaine-type">
            <h2>Semaine type</h2>
            <p class="hint">
                Qui mange habituellement à la maison ? Décoche les absences régulières (travail, cantine…).
                Le planning part de ces présences pour chaque repas (modifiable repas par repas) et ne place pas de restes
                sur un repas où personne n'est là.
            </p>
            <form method="post" action="{{ route('household.usual-week') }}">
                @csrf @method('put')
                <div class="usual-week-scroll">
                    <table class="usual-week">
                        <thead>
                            <tr>
                                <th scope="col" rowspan="{{ count($eatingSlots) > 1 ? 2 : 1 }}">Membre</th>
                                @foreach ($weekdays as $weekday => $label)
                                    <th scope="colgroup" colspan="{{ count($eatingSlots) }}" @class(['is-weekend' => $weekday >= 6])>{{ $label }}</th>
                                @endforeach
                            </tr>
                            @if (count($eatingSlots) > 1)
                                <tr>
                                    @foreach ($weekdays as $weekday => $label)
                                        @foreach ($eatingSlots as $slot)
                                            <th scope="col" class="small muted">{{ \App\Models\MealPlanEntry::SLOTS[$slot][1] }}</th>
                                        @endforeach
                                    @endforeach
                                </tr>
                            @endif
                        </thead>
                        <tbody>
                            @foreach ($household->members as $member)
                                <tr>
                                    <th scope="row">{{ $member->name }}</th>
                                    @foreach ($weekdays as $weekday => $label)
                                        @foreach ($eatingSlots as $slot)
                                            <td @class(['is-weekend' => $weekday >= 6])>
                                                <input type="checkbox" name="presents[{{ $slot }}][{{ $weekday }}][]" value="{{ $member->id }}"
                                                       aria-label="{{ $member->name }}, {{ mb_strtolower(\App\Models\MealPlanEntry::SLOTS[$slot][0]) }} du {{ $label }}"
                                                       @checked(! $household->isUsuallyAbsent($member->id, $weekday, $slot)) @disabled(! $canManage)>
                                            </td>
                                        @endforeach
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($canManage)
                    <div class="actions"><button type="submit" class="btn">Enregistrer la semaine type</button></div>
                @endif
            </form>
        </section>
    @endif

    {{-- Appareils --}}
    <section class="panel" id="appareils">
        <h2>Appareils de cuisine</h2>
        @if ($canManage)
            <form method="post" action="{{ route('household.equipment') }}" class="stack">
                @csrf @method('put')
                @include('household.equipment-fields', ['equipment' => $equipment, 'owned' => $owned])
                <div class="actions"><button type="submit" class="btn">Enregistrer les appareils</button></div>
            </form>
        @elseif ($household->equipment->isEmpty())
            <p class="hint">Aucun appareil renseigné.</p>
        @else
            <div class="chips">
                @foreach ($household->equipment as $item)
                    <span class="chip is-static">{{ $item->name }}</span>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Comptes --}}
    <section class="panel" id="comptes">
        <h2>Comptes du foyer</h2>
        <div class="list">
            @foreach ($household->users as $account)
                <div class="account-line">
                    <div>
                        <strong>{{ $account->name }}</strong>
                        <span class="muted">{{ $account->username }}</span>
                        <span @class(['tag', 'tag-accent' => $account->isHouseholdAdmin()])>{{ $account->household_role }}</span>
                    </div>
                    @if ($canManage && ! $account->is(auth()->user()))
                        <div class="row-actions">
                            <form method="post" action="{{ route('household.accounts.update', $account) }}">
                                @csrf @method('put')
                                @if ($account->isHouseholdAdmin())
                                    <input type="hidden" name="household_role" value="membre">
                                    <button type="submit" class="btn btn-small btn-ghost">Retirer admin</button>
                                @else
                                    <input type="hidden" name="household_role" value="admin">
                                    <button type="submit" class="btn btn-small btn-ghost">Nommer admin</button>
                                @endif
                            </form>
                            <form method="post" action="{{ route('household.accounts.remove', $account) }}">
                                @csrf @method('delete')
                                <button type="submit" class="btn btn-small btn-danger" data-confirm="Retirer {{ $account->name }} du foyer ?">Retirer du foyer</button>
                            </form>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <form method="post" action="{{ route('household.leave') }}" class="leave">
            @csrf
            <button type="submit" class="btn btn-small btn-ghost" data-confirm="Quitter le foyer {{ $household->name }} ?">Quitter ce foyer</button>
        </form>
    </section>

    {{-- Invitations --}}
    @if ($canManage)
        <section class="panel">
            <h2>Inviter quelqu'un</h2>
            <p class="hint">Chaque lien est personnel : valable {{ \App\Models\HouseholdInvitation::VALIDITY_DAYS }} jours et utilisable une seule fois. La personne doit d'abord avoir un compte Authentik dans le groupe foodtruck.</p>
            <form method="post" action="{{ route('household.invitations.store') }}">
                @csrf
                <button type="submit" class="btn">Créer un lien d'invitation</button>
            </form>

            @if ($invitations->isNotEmpty())
                <div class="list invitations">
                    @foreach ($invitations as $invitation)
                        <div class="account-line">
                            <div>
                                Créée le {{ $invitation->created_at->timezone('Europe/Paris')->format('d/m à H:i') }}
                                <span @class(['tag', 'tag-accent' => $invitation->status() === 'en attente'])>{{ $invitation->status() }}</span>
                                @if ($invitation->user)
                                    <span class="muted">par {{ $invitation->user->name }}</span>
                                @elseif ($invitation->isUsable())
                                    <span class="muted">expire le {{ $invitation->expires_at->timezone('Europe/Paris')->format('d/m') }}</span>
                                @endif
                            </div>
                            @if ($invitation->isUsable())
                                <form method="post" action="{{ route('household.invitations.destroy', $invitation) }}">
                                    @csrf @method('delete')
                                    <button type="submit" class="btn btn-small btn-ghost">Révoquer</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @endif
    {{-- Paperless --}}
    @if ($canManage)
        <details class="panel" id="avance" @if ($errors->has('paperless_url') || $errors->has('paperless_token') || $errors->has('paperless_recipe_tag')) open @endif>
            <summary class="more-summary">Avancé : relier Paperless (tickets de caisse et fiches de recettes)</summary>
            <p class="hint">
                Foodtruck lit les documents Paperless portant l'étiquette choisie : les tickets donnent les prix réellement payés,
                les fiches de recettes scannées deviennent des recettes. Utilise un compte Paperless dédié, en lecture seule, qui ne voit que ces documents.
            </p>
            <form method="post" action="{{ route('household.paperless') }}" class="grid-2 align-end">
                @csrf @method('put')
                <label class="field">
                    <span>Adresse de Paperless</span>
                    <input type="url" name="paperless_url" value="{{ old('paperless_url', $household->paperless_url) }}" placeholder="http://192.168.1.14:8010">
                </label>
                <label class="field">
                    <span>Étiquette des tickets</span>
                    <input type="text" name="paperless_tag" value="{{ old('paperless_tag', $household->paperlessTag()) }}" maxlength="80">
                </label>
                <label class="field">
                    <span>Étiquette des fiches de recettes</span>
                    <input type="text" name="paperless_recipe_tag" value="{{ old('paperless_recipe_tag', $household->paperlessRecipeTag()) }}" maxlength="80">
                </label>
                <label class="field span-2">
                    <span>Jeton d'API</span>
                    {{-- Champ texte masqué (pas un mot de passe) : les gestionnaires de mots de passe ne le remplissent pas --}}
                    <input type="text" name="paperless_token" value="" class="mono secret-field" autocomplete="off" autocapitalize="off" spellcheck="false"
                           data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other" maxlength="200"
                           placeholder="{{ $household->paperless_token ? 'laisser vide pour conserver le jeton enregistré' : 'colle ici les 40 caractères du jeton' }}">
                    @if ($household->paperless_token)
                        <small>Jeton enregistré, se termine par « …{{ \Illuminate\Support\Str::substr($household->paperless_token, -6) }} ».</small>
                    @else
                        <small>Uniquement le jeton : « Token » devant ou des espaces sont retirés automatiquement.</small>
                    @endif
                </label>
                <div class="actions span-2">
                    @if ($household->hasPaperless())
                        <button type="submit" name="disconnect" value="1" class="btn btn-ghost" data-confirm="Déconnecter Paperless ?">Déconnecter</button>
                    @endif
                    <button type="submit" class="btn">Tester et enregistrer</button>
                </div>
            </form>
        </details>
    @endif

@endsection
