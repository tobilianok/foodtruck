@extends('layouts.app')

@section('title', 'Mon foyer')

@section('content')
    <section class="hero hero-compact">
        <h1>{{ $household->name }}</h1>
        <p class="lead">
            {{ $household->members->count() }} personne{{ $household->members->count() > 1 ? 's' : '' }}
            · {{ rtrim(rtrim(number_format($household->totalPortions(), 2, ',', ' '), '0'), ',') }} parts par repas
            · budget {{ number_format($household->budgetEuros(), 0, ',', ' ') }} € par semaine
        </p>
        @unless ($canManage)
            <p class="hint">Seuls les administrateurs du foyer peuvent modifier ces informations.</p>
        @endunless
    </section>

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

    {{-- Réglages --}}
    <section class="panel">
        <h2>Réglages</h2>
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
                <div class="actions"><button type="submit" class="btn">Enregistrer</button></div>
            </form>
        @else
            <p>Budget : {{ number_format($household->budgetEuros(), 0, ',', ' ') }} € par semaine.</p>
            <p>Magasin principal : {{ $household->mainStore->name ?? 'non défini' }} · fruits et légumes : {{ $household->produceStore->name ?? 'non défini' }}.</p>
        @endif
    </section>

    {{-- Membres --}}
    <section class="panel">
        <h2>Membres et portions</h2>
        <p class="hint">Adulte 1 · enfant 0,6 · tout-petit 0 par défaut. Le compte lié permet de savoir qui est qui.</p>

        <div class="list">
            @foreach ($household->members as $member)
                @if ($canManage)
                    <form method="post" action="{{ route('household.members.update', $member) }}" class="member-row">
                        @csrf @method('put')
                        <label class="field">
                            <span>Prénom</span>
                            <input type="text" name="name" value="{{ $member->name }}" maxlength="60" required>
                        </label>
                        <label class="field">
                            <span>Catégorie</span>
                            <select name="category">
                                @foreach ($categories as $value => $category)
                                    <option value="{{ $value }}" @selected($member->category === $value)>{{ $category['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="field field-small">
                            <span>Coefficient</span>
                            <input type="number" name="coefficient" value="{{ $member->portion_coefficient }}" min="0" max="2" step="0.05" required>
                        </label>
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
                        <span>{{ $member->categoryLabel() }}</span>
                        <span>coefficient {{ str_replace('.', ',', (string) $member->portion_coefficient) }}</span>
                        @if ($member->user)
                            <span class="tag">{{ $member->user->name }}</span>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>

        @if ($canManage)
            <details class="add-block">
                <summary>+ Ajouter une personne</summary>
                <form method="post" action="{{ route('household.members.store') }}" class="member-row">
                    @csrf
                    <label class="field">
                        <span>Prénom</span>
                        <input type="text" name="name" maxlength="60" required>
                    </label>
                    <label class="field">
                        <span>Catégorie</span>
                        <select name="category" data-category>
                            @foreach ($categories as $value => $category)
                                <option value="{{ $value }}" data-coefficient="{{ $category['coefficient'] }}">{{ $category['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="field field-small">
                        <span>Coefficient</span>
                        <input type="number" name="coefficient" value="1" min="0" max="2" step="0.05" required data-coefficient>
                    </label>
                    <div class="row-actions"><button type="submit" class="btn btn-small">Ajouter</button></div>
                </form>
            </details>
        @endif
    </section>

    {{-- Appareils --}}
    <section class="panel">
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

    {{-- Paperless --}}
    @if ($canManage)
        <section class="panel" id="paperless">
            <h2>Tickets de caisse : Paperless</h2>
            <p class="hint">
                Foodtruck lit les documents Paperless portant l'étiquette choisie et en tire les prix réellement payés.
                Utilise un compte Paperless dédié, en lecture seule, qui ne voit que ces documents.
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
        </section>
    @endif

    {{-- Comptes --}}
    <section class="panel">
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
@endsection
