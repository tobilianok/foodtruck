<?php

namespace App\Support;

use App\Models\Price;
use App\Models\Receipt;
use App\Models\ReceiptAlias;
use App\Models\ReceiptLine;
use Illuminate\Support\Facades\DB;

/**
 * v0.19.0 (demande de Louis) : suppression de TOUS les tickets de caisse déjà importés, pour repartir sur une base
 * saine avec les tickets lus par l'IA.
 *
 * Effacé : tickets et leurs lignes, prix relevés sur les tickets (source « ticket »), libellés de tickets mémorisés
 * (ils seront réappris à la validation des nouveaux tickets).
 * Conservé : comptes, foyers et réglages Paperless, ingrédients et conditionnements, prix saisis à la main, magasins
 * (et les noms de correspondants appris), stock, listes de courses (les cases déjà cochées le restent), recettes.
 */
class ReceiptWipe
{
    /** @return array<string, int> libellé => nombre de lignes concernées */
    public static function counts(): array
    {
        return [
            'Tickets' => Receipt::count(),
            'Lignes de tickets' => ReceiptLine::count(),
            'Prix relevés sur les tickets' => Price::where('source', 'ticket')->count(),
            'Libellés de tickets mémorisés' => ReceiptAlias::count(),
        ];
    }

    public static function run(): void
    {
        DB::transaction(function () {
            ReceiptLine::query()->delete();
            Receipt::query()->delete();
            Price::where('source', 'ticket')->delete();
            ReceiptAlias::query()->delete();
        });
    }
}
