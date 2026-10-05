@extends('layouts.app')

@section('title', 'Accueil')

@section('content')
    @php
        $household = auth()->user()->household->loadMissing('members', 'equipment', 'mainStore', 'produceStore');
    @endphp

    <section class="hero">
        <h1>Bonjour {{ auth()->user()->firstName() }}</h1>
        <p class="lead">Foyer « {{ $household->name }} » : les modules arrivent étape par étape, chacun validé avant le suivant.</p>
    </section>

    @php
        $portions = rtrim(rtrim(number_format($household->totalPortions(), 2, ',', ' '), '0'), ',');
    @endphp

    @php
        $todayMeals = $household->mealPlanEntries()->with('recipe', 'source.recipe')
            ->whereDate('date', now('Europe/Paris')->toDateString())->where('is_frozen', false)->get()
            ->sortBy(fn ($e) => array_search($e->slot, \App\Models\MealPlanEntry::slotCodes(), true));
        $weekCount = $household->mealPlanEntries()->where('kind', 'recette')
            ->whereDate('date', '>=', \App\Support\MealPlanner::weekStart()->toDateString())
            ->whereDate('date', '<=', \App\Support\MealPlanner::weekStart()->addDays(6)->toDateString())->count();
    @endphp

    <section class="grid">
        <a class="card card-link card-wide" href="{{ route('planning.index') }}">
            <h2>Planning · aujourd'hui</h2>
            @if ($todayMeals->isEmpty())
                <p>Rien de prévu aujourd'hui. {{ $weekCount }} plat{{ $weekCount > 1 ? 's' : '' }} cette semaine.</p>
            @else
                <ul class="today-meals">
                    @foreach ($todayMeals as $meal)
                        <li><strong>{{ $meal->slotLabel() }}</strong> :
                            @if ($meal->kind === 'hors_maison') hors maison{{ $meal->note ? ' ('.$meal->note.')' : '' }}
                            @elseif ($meal->kind === 'note') {{ $meal->note }}
                            @elseif ($meal->isLeftover()) restes de {{ $meal->source?->recipe?->title }}
                            @else {{ $meal->recipe?->title }} @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <span class="tag tag-accent">Disponible</span>
        </a>
        @php
            $shopping = $household->shoppingLists()->whereNull('archived_at')->first();
            $shoppingItems = $shopping?->items()->where('section', 'achat');
        @endphp
        <a class="card card-link" href="{{ route('shopping.index') }}">
            <h2>Liste de courses</h2>
            <p>
                @if ($shopping)
                    Courses du {{ $shopping->periodLabel() }}<br>
                    {{ (clone $shoppingItems)->where('is_checked', true)->count() }} / {{ (clone $shoppingItems)->count() }} articles cochés
                    · ≈ {{ \App\Models\Price::formatCents((int) (clone $shoppingItems)->sum('estimated_cents')) }}
                @else
                    Calculée d'après ton planning, par magasin et par rayon
                @endif
            </p>
            <span class="tag tag-accent">Disponible</span>
        </a>
        @php
            $pantryCount = $household->pantryItems()->count();
            $pantrySoon = \App\Support\AntiWaste::expiring($household)->count();
        @endphp
        <a class="card card-link" href="{{ route('stock.index') }}">
            <h2>Stock</h2>
            <p>
                @if ($pantryCount === 0) Rien en stock pour l'instant : les restes d'emballages s'y rangent à la fin des courses
                @else {{ $pantryCount }} ligne{{ $pantryCount > 1 ? 's' : '' }} en stock
                    @if ($pantrySoon) <br><strong>{{ $pantrySoon }} à consommer vite</strong> · que cuisiner ? @endif
                @endif
            </p>
            <span class="tag tag-accent">Disponible</span>
        </a>
        <a class="card card-link" href="{{ route('household.show') }}">
            <h2>Mon foyer</h2>
            <p>
                {{ $household->members->count() }} personne{{ $household->members->count() > 1 ? 's' : '' }} ·
                {{ $portions }} parts par repas<br>
                Budget {{ number_format($household->budgetEuros(), 0, ',', ' ') }} € / semaine ·
                {{ $household->equipment->count() }} appareil{{ $household->equipment->count() > 1 ? 's' : '' }}
            </p>
            <span class="tag tag-accent">Disponible</span>
        </a>
        <a class="card card-link" href="{{ route('recipes.index') }}">
            <h2>Recettes</h2>
            <p>
                {{ \App\Models\Recipe::visibleTo(auth()->user())->count() }} recettes partagées<br>
                {{ auth()->user()->favoriteRecipes()->count() }} en favoris
            </p>
            <span class="tag tag-accent">Disponible</span>
        </a>
        <a class="card card-link" href="{{ route('ingredients.index') }}">
            <h2>Ingrédients et prix</h2>
            <p>
                {{ \App\Models\Ingredient::count() }} ingrédients, prix par magasin<br>
                @if ($household->mainStore) Magasin principal : {{ $household->mainStore->name }} @endif
                @if ($household->produceStore) · fruits et légumes : {{ $household->produceStore->name }} @endif
            </p>
            <span class="tag tag-accent">Disponible</span>
        </a>
        @php
            $toReview = $household->receipts()->where('status', 'a_valider')->count();
            $ticketPrices = \App\Models\Price::where('source', 'ticket')->count();
        @endphp
        <a class="card card-link" href="{{ route('receipts.index') }}">
            <h2>Tickets de caisse</h2>
            <p>
                {{ $ticketPrices }} prix réels relevés<br>
                @if ($toReview) <strong>{{ $toReview }} ticket{{ $toReview > 1 ? 's' : '' }} à valider</strong>
                @elseif ($household->hasPaperless()) Relié à Paperless
                @else Paperless non relié @endif
            </p>
            <span class="tag tag-accent">Disponible</span>
        </a>
        @php
            $billed = $household->shoppingLists()->whereHas('receipts')->first();
            $billedCmp = $billed ? \App\Support\ListReconciliation::compare($billed->setRelation('household', $household)) : null;
            $lastList = $billed ?? $household->shoppingLists()->first();
        @endphp
        <a class="card card-link" href="{{ $lastList ? route('shopping.bilan', $lastList) : route('shopping.index') }}">
            <h2>Économies</h2>
            <p>
                @if ($billed)
                    Dernier bilan : {{ \App\Models\Price::formatCents($billedCmp['paid_cents']) }} payés
                    sur un budget de {{ \App\Models\Price::formatCents($billedCmp['budget_cents']) }}<br>
                    <span class="muted">courses du {{ $billed->periodLabel() }}</span>
                @else
                    Bilan payé / estimé d'après tes tickets, plats à remplacer quand le budget est entamé, alertes de prix
                @endif
            </p>
            <span class="tag tag-accent">Disponible</span>
        </a>
    </section>
@endsection
