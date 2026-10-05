<?php

/*
 * Foodtruck - jeu d'ingrédients de départ (v0.3.0).
 *
 * Les PRIX sont des ESTIMATIONS (septembre 2026) pour démarrer : ils sont marqués « estimé »
 * dans l'application et seront recalés avec les tickets de caisse.
 *
 * Chaque ligne :
 *   [nom, rayon, unité de base (g | ml | piece), poids d'une pièce en g, densité en g/ml,
 *    mois de saison (null = toute l'année), produit frais, produit de base (toujours en stock),
 *    conditionnements : [[libellé, quantité en unité de base, vrac ?, [magasin => prix en centimes]], ...]]
 *
 * L'import (./ft php artisan foodtruck:reference) n'ajoute que les ingrédients absents :
 * les corrections faites dans l'application ne sont jamais écrasées.
 */

$L = 'leclerc-drive';
$M = 'morin';

$toute = null;
$automne_hiver = [9, 10, 11, 12, 1, 2, 3];
$hiver = [11, 12, 1, 2, 3];
$ete = [6, 7, 8, 9];

return [
    // ---------------------------------------------------------------- Fruits et légumes
    ['Carotte', 'fruits-legumes', 'g', 125, null, $toute, true, false, [['Vrac au kg', 1000, true, [$M => 140, $L => 169]]]],
    ['Pomme de terre', 'fruits-legumes', 'g', 150, null, $toute, true, false, [['Vrac au kg', 1000, true, [$M => 130]], ['Filet 2,5 kg', 2500, false, [$L => 349]]]],
    ['Oignon jaune', 'fruits-legumes', 'g', 110, null, $toute, true, false, [['Vrac au kg', 1000, true, [$M => 180]], ['Filet 1 kg', 1000, false, [$L => 169]]]],
    ['Oignon rouge', 'fruits-legumes', 'g', 110, null, $toute, true, false, [['Vrac au kg', 1000, true, [$M => 290, $L => 299]]]],
    ['Échalote', 'fruits-legumes', 'g', 25, null, $toute, true, false, [['Vrac au kg', 1000, true, [$M => 590]], ['Filet 500 g', 500, false, [$L => 229]]]],
    ['Ail', 'fruits-legumes', 'g', 5, null, $toute, true, false, [['Tête (≈ 50 g)', 50, false, [$M => 70]], ['Filet 3 têtes', 150, false, [$L => 199]]]],
    ['Gingembre frais', 'fruits-legumes', 'g', null, null, $toute, true, false, [['Vrac au kg', 1000, true, [$M => 890, $L => 899]]]],
    ['Poireau', 'fruits-legumes', 'g', 200, null, [9, 10, 11, 12, 1, 2, 3, 4], true, false, [['Vrac au kg', 1000, true, [$M => 290, $L => 299]]]],
    ['Courge butternut', 'fruits-legumes', 'g', 1200, null, [9, 10, 11, 12, 1, 2], true, false, [['Vrac au kg', 1000, true, [$M => 220, $L => 249]]]],
    ['Potimarron', 'fruits-legumes', 'g', 1300, null, [9, 10, 11, 12, 1], true, false, [['Vrac au kg', 1000, true, [$M => 250, $L => 279]]]],
    ['Courgette', 'fruits-legumes', 'g', 250, null, [6, 7, 8, 9, 10], true, false, [['Vrac au kg', 1000, true, [$M => 290, $L => 299]]]],
    ['Tomate', 'fruits-legumes', 'g', 120, null, $ete, true, false, [['Vrac au kg', 1000, true, [$M => 350, $L => 349]]]],
    ['Tomate cerise', 'fruits-legumes', 'g', 12, null, $ete, true, false, [['Barquette 250 g', 250, false, [$M => 220, $L => 199]]]],
    ['Poivron', 'fruits-legumes', 'g', 180, null, [7, 8, 9, 10], true, false, [['Vrac au kg', 1000, true, [$M => 450, $L => 499]]]],
    ['Aubergine', 'fruits-legumes', 'g', 300, null, [7, 8, 9], true, false, [['Vrac au kg', 1000, true, [$M => 350, $L => 349]]]],
    ['Concombre', 'fruits-legumes', 'piece', 400, null, [5, 6, 7, 8, 9], true, false, [['Pièce', 1, false, [$M => 99, $L => 99]]]],
    ['Champignon de Paris', 'fruits-legumes', 'g', 20, null, $toute, true, false, [['Vrac au kg', 1000, true, [$M => 550]], ['Barquette 500 g', 500, false, [$L => 279]]]],
    ['Brocoli', 'fruits-legumes', 'g', 450, null, [6, 7, 8, 9, 10, 11], true, false, [['Vrac au kg', 1000, true, [$M => 350, $L => 399]]]],
    ['Chou-fleur', 'fruits-legumes', 'g', 900, null, [9, 10, 11, 12, 1, 2, 3, 4], true, false, [['Pièce (≈ 900 g)', 900, false, [$M => 250, $L => 279]]]],
    ['Chou vert', 'fruits-legumes', 'g', 1200, null, [10, 11, 12, 1, 2, 3], true, false, [['Pièce (≈ 1,2 kg)', 1200, false, [$M => 220, $L => 249]]]],
    ['Chou rouge', 'fruits-legumes', 'g', 1000, null, [10, 11, 12, 1, 2, 3], true, false, [['Pièce (≈ 1 kg)', 1000, false, [$M => 200]]]],
    ['Épinards frais', 'fruits-legumes', 'g', null, null, [3, 4, 5, 9, 10, 11], true, false, [['Vrac au kg', 1000, true, [$M => 590]]]],
    ['Salade (laitue, batavia)', 'fruits-legumes', 'piece', 300, null, [4, 5, 6, 7, 8, 9, 10], true, false, [['Pièce', 1, false, [$M => 120, $L => 129]]]],
    ['Mâche', 'fruits-legumes', 'g', null, null, [10, 11, 12, 1, 2, 3], true, false, [['Sachet 150 g', 150, false, [$L => 189]]]],
    ['Endive', 'fruits-legumes', 'g', 120, null, [10, 11, 12, 1, 2, 3, 4], true, false, [['Vrac au kg', 1000, true, [$M => 350, $L => 339]]]],
    ['Céleri-rave', 'fruits-legumes', 'g', 800, null, $automne_hiver, true, false, [['Vrac au kg', 1000, true, [$M => 290]]]],
    ['Céleri branche', 'fruits-legumes', 'piece', 500, null, [7, 8, 9, 10, 11], true, false, [['Pied', 1, false, [$M => 180]]]],
    ['Fenouil', 'fruits-legumes', 'g', 300, null, [6, 7, 8, 9, 10, 11], true, false, [['Vrac au kg', 1000, true, [$M => 350]]]],
    ['Navet', 'fruits-legumes', 'g', 150, null, [10, 11, 12, 1, 2, 3], true, false, [['Vrac au kg', 1000, true, [$M => 290]]]],
    ['Panais', 'fruits-legumes', 'g', 200, null, [10, 11, 12, 1, 2, 3], true, false, [['Vrac au kg', 1000, true, [$M => 390]]]],
    ['Patate douce', 'fruits-legumes', 'g', 350, null, $automne_hiver, true, false, [['Vrac au kg', 1000, true, [$M => 350, $L => 349]]]],
    ['Betterave cuite', 'fruits-legumes', 'g', 250, null, $toute, true, false, [['Sous vide 500 g', 500, false, [$M => 180, $L => 159]]]],
    ['Haricots verts frais', 'fruits-legumes', 'g', null, null, [6, 7, 8, 9], true, false, [['Vrac au kg', 1000, true, [$M => 590]]]],
    ['Avocat', 'fruits-legumes', 'piece', 200, null, [10, 11, 12, 1, 2, 3, 4, 5], true, false, [['Pièce', 1, false, [$M => 99, $L => 99]]]],
    ['Pomme', 'fruits-legumes', 'g', 180, null, [8, 9, 10, 11, 12, 1, 2, 3, 4], true, false, [['Vrac au kg', 1000, true, [$M => 250, $L => 269]]]],
    ['Poire', 'fruits-legumes', 'g', 180, null, [8, 9, 10, 11, 12, 1], true, false, [['Vrac au kg', 1000, true, [$M => 320, $L => 329]]]],
    ['Banane', 'fruits-legumes', 'g', 180, null, $toute, true, false, [['Vrac au kg', 1000, true, [$M => 199, $L => 189]]]],
    ['Citron', 'fruits-legumes', 'piece', 120, null, $toute, true, false, [['Pièce', 1, false, [$M => 50, $L => 49]]]],
    ['Orange', 'fruits-legumes', 'g', 200, null, [11, 12, 1, 2, 3, 4], true, false, [['Vrac au kg', 1000, true, [$M => 250, $L => 249]]]],
    ['Clémentine', 'fruits-legumes', 'g', 80, null, [11, 12, 1, 2], true, false, [['Vrac au kg', 1000, true, [$M => 350, $L => 349]]]],
    ['Kiwi', 'fruits-legumes', 'piece', 90, null, [11, 12, 1, 2, 3, 4], true, false, [['Pièce', 1, false, [$M => 35, $L => 35]]]],
    ['Raisin', 'fruits-legumes', 'g', null, null, [8, 9, 10], true, false, [['Vrac au kg', 1000, true, [$M => 390, $L => 399]]]],
    ['Prune, quetsche', 'fruits-legumes', 'g', 40, null, [8, 9], true, false, [['Vrac au kg', 1000, true, [$M => 350]]]],
    ['Persil', 'fruits-legumes', 'piece', 30, null, $toute, true, false, [['Botte', 1, false, [$M => 100, $L => 99]]]],
    ['Coriandre', 'fruits-legumes', 'piece', 30, null, $toute, true, false, [['Botte', 1, false, [$M => 100, $L => 119]]]],
    ['Ciboulette', 'fruits-legumes', 'piece', 20, null, $toute, true, false, [['Botte', 1, false, [$M => 100, $L => 119]]]],
    ['Basilic', 'fruits-legumes', 'piece', 20, null, [5, 6, 7, 8, 9], true, false, [['Botte', 1, false, [$M => 150, $L => 149]]]],
    ['Sauge fraîche', 'fruits-legumes', 'g', 0.5, null, $toute, true, false, [['Barquette 15 g', 15, false, [$M => 150, $L => 179]]]],
    ['Romarin frais', 'fruits-legumes', 'g', 1, null, $toute, true, false, [['Barquette 15 g', 15, false, [$M => 150, $L => 179]]]],
    ['Thym frais', 'fruits-legumes', 'g', 0.5, null, $toute, true, false, [['Barquette 15 g', 15, false, [$M => 150, $L => 179]]]],
    ['Menthe fraîche', 'fruits-legumes', 'piece', 15, null, [5, 6, 7, 8, 9], true, false, [['Botte', 1, false, [$M => 100, $L => 99]]]],

    // ---------------------------------------------------------------- Boucherie, volaille
    ['Filet de poulet', 'boucherie', 'g', 130, null, $toute, true, false, [['Barquette 500 g', 500, false, [$L => 649]], ['Barquette 1 kg', 1000, false, [$L => 1190]]]],
    ['Cuisse de poulet', 'boucherie', 'g', 250, null, $toute, true, false, [['Barquette 1 kg', 1000, false, [$L => 599]]]],
    ['Poulet entier', 'boucherie', 'g', 1400, null, $toute, true, false, [['Pièce (≈ 1,4 kg)', 1400, false, [$L => 849]]]],
    ['Bœuf haché 5 %', 'boucherie', 'g', null, null, $toute, true, false, [['Barquette 500 g', 500, false, [$L => 649]]]],
    ['Steak haché 5 %', 'boucherie', 'g', 100, null, $toute, true, false, [['4 × 100 g', 400, false, [$L => 469]]]],
    ['Bœuf à braiser', 'boucherie', 'g', null, null, $toute, true, false, [['Barquette 1 kg', 1000, false, [$L => 1390]]]],
    ['Sauté de porc', 'boucherie', 'g', null, null, $toute, true, false, [['Barquette 1 kg', 1000, false, [$L => 899]]]],
    ['Côte de porc', 'boucherie', 'g', 150, null, $toute, true, false, [['Barquette 1 kg', 1000, false, [$L => 799]]]],
    ['Saucisse de Toulouse', 'boucherie', 'g', 100, null, $toute, true, false, [['Barquette 6 (600 g)', 600, false, [$L => 549]]]],

    // ---------------------------------------------------------------- Poissonnerie
    ['Pavé de saumon', 'poissonnerie', 'g', 125, null, $toute, true, false, [['2 × 125 g', 250, false, [$L => 599]]]],

    // ---------------------------------------------------------------- Crèmerie
    ['Lait demi-écrémé', 'cremerie', 'ml', null, 1.03, $toute, true, false, [['Bouteille 1 L', 1000, false, [$L => 105]], ['Pack 6 × 1 L', 6000, false, [$L => 599]]]],
    ['Lait entier', 'cremerie', 'ml', null, 1.03, $toute, true, false, [['Bouteille 1 L', 1000, false, [$L => 125]], ['Pack 6 × 1 L', 6000, false, [$L => 719]]]],
    ['Lait fermenté (ribot)', 'cremerie', 'ml', null, 1.03, $toute, true, false, [['Bouteille 1 L', 1000, false, [$L => 189]]]],
    ['Œuf', 'cremerie', 'piece', 55, null, $toute, true, false, [['Boîte de 6', 6, false, [$L => 219]], ['Boîte de 12', 12, false, [$L => 399]]]],
    ['Beurre doux', 'cremerie', 'g', null, null, $toute, true, false, [['Plaquette 250 g', 250, false, [$L => 269]]]],
    ['Beurre demi-sel', 'cremerie', 'g', null, null, $toute, true, false, [['Plaquette 250 g', 250, false, [$L => 279]]]],
    ['Crème fraîche épaisse', 'cremerie', 'ml', null, 1.0, $toute, true, false, [['Pot 20 cl', 200, false, [$L => 115]], ['Pot 50 cl', 500, false, [$L => 259]]]],
    ['Crème liquide entière', 'cremerie', 'ml', null, 1.0, $toute, true, false, [['Brique 20 cl', 200, false, [$L => 99]], ['Brique 1 L', 1000, false, [$L => 369]]]],
    ['Yaourt nature', 'cremerie', 'piece', 125, null, $toute, true, false, [['Pack 4 × 125 g', 4, false, [$L => 105]]]],
    ['Fromage blanc', 'cremerie', 'g', null, null, $toute, true, false, [['Pot 500 g', 500, false, [$L => 179]], ['Pot 1 kg', 1000, false, [$L => 299]]]],

    // ---------------------------------------------------------------- Fromages
    ['Emmental râpé', 'fromages', 'g', null, null, $toute, true, false, [['Sachet 200 g', 200, false, [$L => 219]]]],
    ['Parmesan', 'fromages', 'g', null, null, $toute, true, false, [['Morceau 200 g', 200, false, [$L => 429]]]],
    ['Mozzarella', 'fromages', 'g', 125, null, $toute, true, false, [['Boule 125 g', 125, false, [$L => 99]]]],
    ['Chèvre (bûche)', 'fromages', 'g', null, null, $toute, true, false, [['Bûche 180 g', 180, false, [$L => 229]]]],
    ['Comté', 'fromages', 'g', null, null, $toute, true, false, [['Morceau 250 g', 250, false, [$L => 449]]]],
    ['Feta', 'fromages', 'g', null, null, $toute, true, false, [['Bloc 200 g', 200, false, [$L => 229]]]],

    // ---------------------------------------------------------------- Charcuterie, traiteur
    ['Lardons fumés', 'charcuterie-traiteur', 'g', null, null, $toute, true, false, [['2 × 100 g', 200, false, [$L => 229]]]],
    ['Jambon blanc', 'charcuterie-traiteur', 'g', 40, null, $toute, true, false, [['4 tranches (160 g)', 160, false, [$L => 269]]]],
    ['Tofu nature', 'charcuterie-traiteur', 'g', null, null, $toute, true, false, [['Bloc 250 g', 250, false, [$L => 229]]]],

    // ---------------------------------------------------------------- Boulangerie
    ['Baguette', 'boulangerie', 'piece', 250, null, $toute, true, false, [['Pièce', 1, false, [$L => 110]]]],
    ['Pain de mie', 'boulangerie', 'g', 25, null, $toute, false, false, [['Paquet 500 g', 500, false, [$L => 169]]]],
    ['Tortilla de blé', 'boulangerie', 'piece', 40, null, $toute, false, false, [['Paquet de 8', 8, false, [$L => 199]]]],

    // ---------------------------------------------------------------- Épicerie salée
    ['Pâtes (spaghetti, penne…)', 'epicerie-salee', 'g', null, null, $toute, false, false, [['Paquet 500 g', 500, false, [$L => 95]], ['Paquet 1 kg', 1000, false, [$L => 179]]]],
    ['Riz long', 'epicerie-salee', 'g', null, null, $toute, false, false, [['Paquet 1 kg', 1000, false, [$L => 189]]]],
    ['Riz basmati', 'epicerie-salee', 'g', null, null, $toute, false, false, [['Paquet 1 kg', 1000, false, [$L => 299]]]],
    ['Semoule de couscous', 'epicerie-salee', 'g', null, null, $toute, false, false, [['Paquet 1 kg', 1000, false, [$L => 179]]]],
    ['Lentilles vertes', 'epicerie-salee', 'g', null, null, $toute, false, false, [['Paquet 500 g', 500, false, [$L => 159]]]],
    ['Lentilles corail', 'epicerie-salee', 'g', null, null, $toute, false, false, [['Paquet 500 g', 500, false, [$L => 199]]]],
    ['Pois chiches secs', 'epicerie-salee', 'g', null, null, $toute, false, false, [['Paquet 500 g', 500, false, [$L => 179]]]],
    ['Pois chiches en conserve (égouttés)', 'epicerie-salee', 'g', null, null, $toute, false, false, [['Boîte 400 g (265 g égouttés)', 265, false, [$L => 89]]]],
    ['Haricots rouges en conserve (égouttés)', 'epicerie-salee', 'g', null, null, $toute, false, false, [['Boîte 400 g (250 g égouttés)', 250, false, [$L => 89]]]],
    ['Maïs doux en conserve (égoutté)', 'epicerie-salee', 'g', null, null, $toute, false, false, [['Boîte 285 g égouttés', 285, false, [$L => 89]]]],
    ['Tomates pelées en conserve', 'epicerie-salee', 'g', null, null, $toute, false, false, [['Boîte 400 g', 400, false, [$L => 79]]]],
    ['Coulis de tomate (passata)', 'epicerie-salee', 'g', null, null, $toute, false, false, [['Bouteille 680 g', 680, false, [$L => 139]]]],
    ['Concentré de tomate', 'epicerie-salee', 'g', null, null, $toute, false, false, [['Boîte 140 g', 140, false, [$L => 69]]]],
    ['Thon au naturel', 'epicerie-salee', 'g', null, null, $toute, false, false, [['Boîte 140 g (104 g égouttés)', 104, false, [$L => 169]]]],
    ['Sardines à l\'huile', 'epicerie-salee', 'g', null, null, $toute, false, false, [['Boîte 135 g', 135, false, [$L => 129]]]],
    ['Lait de coco', 'epicerie-salee', 'ml', null, 1.0, $toute, false, false, [['Boîte 40 cl', 400, false, [$L => 139]]]],
    ['Bouillon de volaille (cube)', 'epicerie-salee', 'piece', 10, null, $toute, false, true, [['Boîte de 12 cubes', 12, false, [$L => 129]]]],
    ['Bouillon de légumes (cube)', 'epicerie-salee', 'piece', 10, null, $toute, false, true, [['Boîte de 12 cubes', 12, false, [$L => 139]]]],
    ['Chapelure', 'epicerie-salee', 'g', null, null, $toute, false, false, [['Paquet 500 g', 500, false, [$L => 139]]]],
    ['Sauce soja', 'epicerie-salee', 'ml', null, 1.2, $toute, false, true, [['Bouteille 15 cl', 150, false, [$L => 169]]]],

    // ---------------------------------------------------------------- Épicerie sucrée
    ['Farine de blé T55', 'epicerie-sucree', 'g', null, null, $toute, false, true, [['Paquet 1 kg', 1000, false, [$L => 89]]]],
    ['Farine T65 (pain)', 'epicerie-sucree', 'g', null, null, $toute, false, false, [['Paquet 1 kg', 1000, false, [$L => 119]]]],
    ['Maïzena', 'epicerie-sucree', 'g', null, null, $toute, false, true, [['Boîte 400 g', 400, false, [$L => 229]]]],
    ['Sucre en poudre', 'epicerie-sucree', 'g', null, 0.85, $toute, false, true, [['Paquet 1 kg', 1000, false, [$L => 129]]]],
    ['Cassonade', 'epicerie-sucree', 'g', null, 0.9, $toute, false, false, [['Paquet 750 g', 750, false, [$L => 199]]]],
    ['Sucre vanillé', 'epicerie-sucree', 'piece', 7.5, null, $toute, false, true, [['Boîte de 10 sachets', 10, false, [$L => 99]]]],
    ['Levure chimique', 'epicerie-sucree', 'piece', 11, null, $toute, false, true, [['Boîte de 6 sachets', 6, false, [$L => 99]]]],
    ['Levure de boulanger sèche', 'epicerie-sucree', 'piece', 5.5, null, $toute, false, false, [['Boîte de 5 sachets', 5, false, [$L => 129]]]],
    ['Chocolat noir pâtissier', 'epicerie-sucree', 'g', null, null, $toute, false, false, [['Tablette 200 g', 200, false, [$L => 219]]]],
    ['Pépites de chocolat', 'epicerie-sucree', 'g', null, null, $toute, false, false, [['Sachet 200 g', 200, false, [$L => 229]]]],
    ['Cacao en poudre non sucré', 'epicerie-sucree', 'g', null, null, $toute, false, false, [['Boîte 250 g', 250, false, [$L => 299]]]],
    ['Flocons d\'avoine', 'epicerie-sucree', 'g', null, 0.4, $toute, false, false, [['Paquet 500 g', 500, false, [$L => 129]], ['Paquet 1 kg', 1000, false, [$L => 229]]]],
    ['Miel', 'epicerie-sucree', 'g', null, 1.4, $toute, false, false, [['Pot 500 g', 500, false, [$L => 549]]]],
    ['Confiture', 'epicerie-sucree', 'g', null, null, $toute, false, false, [['Pot 350 g', 350, false, [$L => 199]]]],
    ['Amandes entières', 'epicerie-sucree', 'g', null, null, $toute, false, false, [['Sachet 200 g', 200, false, [$L => 349]]]],
    ['Poudre d\'amande', 'epicerie-sucree', 'g', null, null, $toute, false, false, [['Sachet 200 g', 200, false, [$L => 399]]]],
    ['Noisettes', 'epicerie-sucree', 'g', null, null, $toute, false, false, [['Sachet 200 g', 200, false, [$L => 379]]]],
    ['Cerneaux de noix', 'epicerie-sucree', 'g', null, null, $toute, false, false, [['Sachet 200 g', 200, false, [$L => 399]]]],
    ['Raisins secs', 'epicerie-sucree', 'g', null, null, $toute, false, false, [['Sachet 250 g', 250, false, [$L => 199]]]],
    ['Graines de tournesol', 'epicerie-sucree', 'g', null, null, $toute, false, false, [['Sachet 200 g', 200, false, [$L => 169]]]],
    ['Lait en poudre écrémé', 'epicerie-sucree', 'g', null, null, $toute, false, false, [['Boîte 300 g', 300, false, [$L => 389]]]],

    // ---------------------------------------------------------------- Huiles, condiments, épices
    ['Huile d\'olive', 'condiments', 'ml', null, 0.91, $toute, false, true, [['Bouteille 50 cl', 500, false, [$L => 499]], ['Bouteille 1 L', 1000, false, [$L => 899]]]],
    ['Huile de tournesol', 'condiments', 'ml', null, 0.92, $toute, false, true, [['Bouteille 1 L', 1000, false, [$L => 219]]]],
    ['Vinaigre balsamique', 'condiments', 'ml', null, 1.05, $toute, false, true, [['Bouteille 50 cl', 500, false, [$L => 249]]]],
    ['Vinaigre de vin', 'condiments', 'ml', null, 1.0, $toute, false, true, [['Bouteille 50 cl', 500, false, [$L => 99]]]],
    ['Moutarde', 'condiments', 'g', null, 1.1, $toute, false, true, [['Pot 370 g', 370, false, [$L => 179]]]],
    ['Mayonnaise', 'condiments', 'g', null, 0.95, $toute, false, false, [['Pot 475 g', 475, false, [$L => 229]]]],
    ['Ketchup', 'condiments', 'g', null, 1.15, $toute, false, false, [['Flacon 560 g', 560, false, [$L => 199]]]],
    ['Sel fin', 'condiments', 'g', null, 1.2, $toute, false, true, [['Boîte 1 kg', 1000, false, [$L => 59]]]],
    ['Poivre noir moulu', 'condiments', 'g', null, 0.5, $toute, false, true, [['Pot 100 g', 100, false, [$L => 249]]]],
    ['Cumin moulu', 'condiments', 'g', null, 0.45, $toute, false, true, [['Pot 40 g', 40, false, [$L => 159]]]],
    ['Curry en poudre', 'condiments', 'g', null, 0.45, $toute, false, true, [['Pot 45 g', 45, false, [$L => 159]]]],
    ['Paprika', 'condiments', 'g', null, 0.45, $toute, false, true, [['Pot 40 g', 40, false, [$L => 159]]]],
    ['Cannelle moulue', 'condiments', 'g', null, 0.55, $toute, false, true, [['Pot 45 g', 45, false, [$L => 159]]]],
    ['Noix de muscade moulue', 'condiments', 'g', null, 0.55, $toute, false, true, [['Pot 40 g', 40, false, [$L => 199]]]],
    ['Herbes de Provence', 'condiments', 'g', null, 0.2, $toute, false, true, [['Pot 30 g', 30, false, [$L => 129]]]],

    // ---------------------------------------------------------------- Boissons
    ['Eau', 'boissons', 'ml', null, 1.0, $toute, false, true, []],

    // ---------------------------------------------------------------- Surgelés
    ['Petits pois surgelés', 'surgeles', 'g', null, null, $toute, false, false, [['Sachet 1 kg', 1000, false, [$L => 249]]]],
    ['Haricots verts surgelés', 'surgeles', 'g', null, null, $toute, false, false, [['Sachet 1 kg', 1000, false, [$L => 229]]]],
    ['Épinards hachés surgelés', 'surgeles', 'g', null, null, $toute, false, false, [['Sachet 1 kg', 1000, false, [$L => 199]]]],
    ['Filets de colin surgelés', 'surgeles', 'g', 120, null, $toute, false, false, [['Sachet 1 kg', 1000, false, [$L => 899]]]],
];
