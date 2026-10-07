<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v0.20.0 : les listes de courses vides (aucun article, aucun ticket rattaché, rien rangé dans le stock) sont
 * supprimées : elles faussaient l'accueil (camion sur « Courses » avec un planning vide) et doublaient l'historique.
 * Désormais, Foodtruck ne garde jamais de liste vide (ShoppingList::purgeEmpty).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('shopping_lists')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('shopping_list_items')->whereColumn('shopping_list_items.shopping_list_id', 'shopping_lists.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('receipts')->whereColumn('receipts.shopping_list_id', 'shopping_lists.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('pantry_items')->whereColumn('pantry_items.shopping_list_id', 'shopping_lists.id'))
            ->delete();
    }

    public function down(): void
    {
        // Rien à restaurer : des listes vides ne contenaient aucune donnée.
    }
};
