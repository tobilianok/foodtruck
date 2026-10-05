<?php

namespace App\Support;

use App\Models\Household;
use App\Models\Price;
use App\Models\ShoppingList;

/**
 * Où en est le foyer dans son parcours de la semaine : choisir les repas, préparer la liste,
 * faire les courses, faire le bilan. Sert à l'accueil, qui propose toujours l'étape suivante.
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

    public int $planned = 0;

    public ?ShoppingList $list = null;

    public int $itemsTotal = 0;

    public int $itemsChecked = 0;

    public static function for(Household $household): self
    {
        $flow = new self;
        $start = MealPlanner::weekStart();
        $end = $start->copy()->addDays(6);

        $flow->planned = $household->mealPlanEntries()->where('kind', 'recette')->where('is_frozen', false)
            ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())->count();

        $active = $household->shoppingLists()->whereNull('archived_at')->first();
        $recent = $household->shoppingLists()->whereNotNull('archived_at')
            ->where('archived_at', '>=', now()->subDays(10))->whereDate('date_to', '>=', $start->toDateString())->first();

        if ($active) {
            $items = $active->items()->where('section', 'achat');
            $flow->list = $active;
            $flow->itemsTotal = (clone $items)->count();
            $flow->itemsChecked = (clone $items)->where('is_checked', true)->count();
            $flow->itemsTotal > 0 && $flow->itemsChecked === $flow->itemsTotal ? $flow->askBilan($active) : $flow->shop($active);
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
        $stops = [];
        $i = 0;
        foreach (self::STEPS as $key => $label) {
            $stops[] = ['key' => $key, 'label' => $label, 'state' => $this->done || $i < $this->index ? 'done' : ($i === $this->index ? 'current' : 'todo')];
            $i++;
        }

        return $stops;
    }

    private function plan(): void
    {
        $this->key = 'plan';
        $this->index = 0;
        $this->title = 'Choisis les repas de la semaine';
        $this->text = 'Ajoute quelques plats à ton planning. Foodtruck calcule les quantités pour ton foyer.';
        $this->button = 'Choisir mes repas';
        $this->url = route('planning.create');
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
            .($cents > 0 ? ', environ '.Price::formatCents($cents) : '').'. Coche au fur et à mesure, toute la famille voit la liste en direct.';
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
