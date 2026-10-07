<?php

namespace App\Support;

use App\Models\Household;
use App\Models\Price;
use App\Models\ShoppingList;

/**
 * Où en est le foyer dans son parcours de la semaine : choisir les repas, préparer la liste,
 * faire les courses, faire le bilan. Sert à l'accueil, qui propose toujours l'étape suivante.
 *
 * v0.20.0 : chaque étape est établie par les faits. Une liste vide n'existe plus (supprimée avant le calcul) ; une
 * liste qui ne correspond plus au planning ramène à l'étape « Liste » (« Mettre à jour la liste ») tant que rien n'est
 * coché ; chaque étape a une ligne d'état (« 3 plats cette semaine », « 12 / 30 cochés »…) et un lien.
 */
class WeekFlow
{
    /** Étapes dans l'ordre : clé => libellé court de la route. */
    public const STEPS = ['plan' => 'Repas', 'list' => 'Liste', 'shop' => 'Courses', 'bilan' => 'Bilan'];

    public string $key = 'plan';

    /** Position du camion : 0 à 3 (étape en cours, ou dernière si tout est fait). */
    public int $index = 0;

    public bool $done = false;

    public string $title = '';

    public string $text = '';

    public string $button = '';

    public string $url = '';

    public ?string $more = null;

    public ?string $moreUrl = null;

    /** v0.20.0 : bouton principal envoyé en POST (mise à jour de la liste). */
    public string $method = 'get';

    /** La liste en cours ne correspond plus au planning (repas ajoutés, retirés ou modifiés). */
    public bool $stale = false;

    /** Tickets encore à envoyer à l'IA ou à valider. */
    public int $receiptsToReview = 0;

    public int $planned = 0;

    public ?ShoppingList $list = null;

    public int $itemsTotal = 0;

    public int $itemsChecked = 0;

    public static function for(Household $household): self
    {
        $flow = new self;
        $start = MealPlanner::weekStart();
        $end = $start->copy()->addDays(6);

        $flow->planned = $household->mealPlanEntries()->where('kind', 'recette')->where('is_frozen', false)->confirmed()
            ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())->count();

        $active = $household->shoppingLists()->whereNull('archived_at')->latest('id')->first();
        $recent = $household->shoppingLists()->whereNotNull('archived_at')
            ->where('archived_at', '>=', now()->subDays(10))->whereDate('date_to', '>=', $start->toDateString())->latest('archived_at')->first();
        $flow->receiptsToReview = $household->receipts()->where('status', \App\Models\Receipt::STATUS_TO_REVIEW)->count();

        if ($active) {
            $items = $active->items()->where('section', 'achat');
            $flow->list = $active;
            $flow->itemsTotal = (clone $items)->count();
            $flow->itemsChecked = (clone $items)->where('is_checked', true)->count();
            $flow->stale = ShoppingListBuilder::isStale($active->setRelation('household', $household));
            if ($flow->stale && $flow->itemsChecked === 0) {
                $flow->updateList($active);
            } elseif ($flow->itemsTotal > 0 && $flow->itemsChecked === $flow->itemsTotal) {
                $flow->askBilan($active);
            } else {
                $flow->shop($active);
            }
        } elseif ($recent) {
            $flow->list = $recent;
            $recent->receipts()->exists() ? $flow->finished($household, $recent, $start) : $flow->askBilan($recent);
        } elseif ($flow->planned === 0) {
            $flow->plan();
        } else {
            $flow->prepareList();
        }

        return $flow;
    }

    /** Étape en cours (1 à 4), ou 4 si tout est fait. */
    public function number(): int
    {
        return $this->index + 1;
    }

    public function stops(): array
    {
        $status = $this->statuses();
        $stops = [];
        $i = 0;
        foreach (self::STEPS as $key => $label) {
            $stops[] = [
                'key' => $key, 'label' => $label,
                'state' => $this->done || $i < $this->index ? 'done' : ($i === $this->index ? 'current' : 'todo'),
                'status' => $status[$key][0], 'url' => $status[$key][1],
            ];
            $i++;
        }

        return $stops;
    }

    /** v0.20.0 : ligne d'état et lien de chaque étape, d'après les données réelles. @return array<string, array{0: string, 1: string}> */
    private function statuses(): array
    {
        $plural = fn (int $n, string $word) => $n.' '.$word.($n > 1 ? 's' : '');
        $active = $this->list && ! $this->list->isArchived() ? $this->list : null;
        $closed = $this->list?->isArchived() ? $this->list : null;

        $list = match (true) {
            $active && $this->stale => 'À mettre à jour',
            $active !== null => $this->itemsTotal > 0 ? $plural($this->itemsTotal, 'article').' à acheter' : 'Rien à acheter',
            $closed !== null => 'Courses du '.$closed->periodLabel(),
            default => 'Pas encore de liste',
        };
        $shop = match (true) {
            $active !== null => $this->itemsChecked.' / '.$this->itemsTotal.' coché'.($this->itemsChecked > 1 ? 's' : ''),
            $closed !== null => 'Terminées le '.$closed->archived_at->timezone('Europe/Paris')->format('d/m'),
            default => 'Pas commencées',
        };
        $bilan = match (true) {
            $this->receiptsToReview > 0 => $plural($this->receiptsToReview, 'ticket').' à traiter',
            $closed !== null && $closed->receipts()->exists() => 'Ticket rattaché',
            $closed !== null => 'En attente du ticket',
            default => 'Après les courses',
        };

        return [
            'plan' => [$this->planned > 0 ? $plural($this->planned, 'plat').' cette semaine' : 'Aucun plat cette semaine', route('planning.index')],
            'list' => [$list, route('shopping.index')],
            'shop' => [$shop, $this->list ? route('shopping.show', $this->list) : route('shopping.index')],
            'bilan' => [$bilan, $this->receiptsToReview > 0 || ! $closed ? route('receipts.index') : route('shopping.bilan', $closed)],
        ];
    }

    /** v0.20.0 : la liste en cours ne correspond plus au planning et rien n'est encore coché. */
    private function updateList(ShoppingList $list): void
    {
        $this->key = 'list';
        $this->index = 1;
        $this->title = 'Ton planning a changé';
        $this->text = 'La liste du '.$list->periodLabel().' ne correspond plus aux repas prévus. Mets-la à jour : les articles ajoutés à la main et les magasins choisis sont gardés.';
        $this->button = 'Mettre à jour la liste';
        $this->url = route('shopping.refresh', $list);
        $this->method = 'post';
        $this->more = 'Voir la liste';
        $this->moreUrl = route('shopping.show', $list);
    }

    private function plan(): void
    {
        $this->key = 'plan';
        $this->index = 0;
        $this->title = 'Choisis les repas de la semaine';
        $this->text = 'Ajoute quelques plats à ton planning. Foodtruck calcule les quantités pour ton foyer.';
        $this->button = 'Choisir mes repas';
        // v0.20.1 (demande de Louis) : le planning de la semaine, pas directement « Ajouter un repas »
        $this->url = route('planning.index');
        $this->more = 'Comment ça marche ?';
        $this->moreUrl = route('help');
    }

    private function prepareList(): void
    {
        $this->key = 'list';
        $this->index = 1;
        $this->title = 'Prépare ta liste de courses';
        $this->text = $this->planned.' plat'.($this->planned > 1 ? 's' : '').' au menu. Foodtruck regroupe tout par magasin et par rayon.';
        $this->button = 'Créer ma liste';
        $this->url = route('shopping.index');
        $this->more = 'Ajouter d\'autres repas';
        $this->moreUrl = route('planning.index');
    }

    private function shop(ShoppingList $list): void
    {
        $this->key = 'shop';
        $this->index = 2;
        $this->title = 'Fais tes courses';
        $cents = (int) $list->items()->where('section', 'achat')->sum('estimated_cents');
        $this->text = $this->itemsChecked.' article'.($this->itemsChecked > 1 ? 's' : '').' coché'.($this->itemsChecked > 1 ? 's' : '').' sur '.$this->itemsTotal
            .($cents > 0 ? ', environ '.Price::formatCents($cents) : '').'. Coche au fur et à mesure, toute la famille voit la liste en direct.'
            .($this->stale ? ' Le planning a changé depuis : mets la liste à jour depuis la liste.' : '');
        $this->button = 'Ouvrir ma liste';
        $this->url = route('shopping.show', $list);
    }

    private function askBilan(ShoppingList $list): void
    {
        $this->key = 'bilan';
        $this->index = 3;
        if ($list->isArchived()) {
            $this->title = 'Fais le bilan';
            $this->text = 'Rattache ton ticket de caisse : Foodtruck compare ce que tu as payé à ce qui était prévu.';
            $this->button = 'Voir le bilan';
            $this->url = route('shopping.bilan', $list);
        } else {
            $this->title = 'Courses terminées ?';
            $this->text = 'Tout est coché. Termine la liste pour ranger les restes en stock et passer au bilan.';
            $this->button = 'Terminer les courses';
            $this->url = route('shopping.show', $list);
        }
    }

    private function finished(Household $household, ShoppingList $list, $start): void
    {
        $this->key = 'bilan';
        $this->index = 3;
        $this->done = true;
        $cmp = ListReconciliation::compare($list->setRelation('household', $household));
        $this->title = 'Semaine bouclée';
        $this->text = 'Tu as payé '.Price::formatCents($cmp['paid_cents']).' pour un budget de '.Price::formatCents($cmp['budget_cents']).'. Prépare la suite quand tu veux.';
        $this->button = 'Planifier la semaine suivante';
        $this->url = route('planning.week', $start->copy()->addWeek()->toDateString());
        $this->more = 'Revoir le bilan';
        $this->moreUrl = route('shopping.bilan', $list);
    }
}
