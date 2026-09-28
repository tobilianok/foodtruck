# Cadrage - Foodtruck (application menus / recettes / courses)

Version du document : v0.1.1 (étape 2 - socle en ligne, validation en cours)
Dernière mise à jour : 2026-09-28
Langue de travail : français. Chaque livraison = numéro de version incrémenté (semver) + commandes exactes pour la VM et pour GitHub + mise à jour de ces docs (CADRAGE.md, DEPLOIEMENT.md, CHANGELOG.md).

## Décisions actées

Infrastructure (détails dans DEPLOIEMENT.md)
- VM "docker" (Ubuntu Server 26.04, 192.168.1.14) sur le ML150 Gen9 sous Proxmox. L'ancien Unraid est arrêté.
- Dossier du projet : /opt/stacks/foodtruck (dépôt Git = ce dossier).
- RÈGLE IMPÉRATIVE : respecter l'existant de la VM. Vérifications avant toute action ; rien n'est créé ni modifié en dehors de /opt/stacks/foodtruck (seule exception : ajout de la clé d'hôte github.com dans ~/.ssh/known_hosts) ; aucun port publié sur l'hôte ; conteneurs et réseau préfixés "foodtruck" ; chaque script s'arrête au moindre conflit.
- URL : https://foodtruck.louisrousseaux.fr via Nginx Proxy Manager (réseau Docker npm_default).
- Authentification : Authentik (https://auth.louisrousseaux.fr, hébergé hors de cette VM) en OIDC, application et fournisseur "foodtruck". Le premier compte connecté devient admin.
- GitHub : dépôt privé tobilianok/foodtruck, poussé depuis la VM en SSH.
- Stack : Laravel 13, PHP 8.4 (FPM Alpine), MariaDB 11.4, Nginx. Blade + Alpine.js/htmx, sans build Node. PWA pour la liste de courses (à venir). Client OIDC écrit dans l'appli (aucun paquet tiers).

Fonctionnel
- Code : repartir de zéro (pas de reprise de MenuSemaine).
- Recettes publiques, visibles par tous les comptes ; n'importe quel compte peut en saisir. Modification par l'auteur ou un admin, les autres dupliquent.
- Recette saisie pour un rendement donné, puis proratisée à la sélection.
- Mutualisation des ingrédients dans la liste de courses (ex. 20 cl + 40 cl de lait = 1 bouteille de 1 L, une seule ligne).
- Planning libre : on choisit les repas voulus, pas de grille obligatoire (imprévus, restaurant, invités).
- Multi-foyers ; un seul foyer créé au départ.
- Composition du foyer paramétrable à la création du compte (assistant de première connexion). Coefficients : adulte 1, enfant 0,6, tout-petit 0 (modifiables).
- Équipements de cuisine du foyer paramétrables : four, plaques, micro-ondes, air fryer, Companion Moulinex, Cookeo, yaourtière, robot pâtissier, mixeur/blender, machine à pain...
- Magasins : Leclerc Drive, Carrefour, Grand Frais, Hyper U, Lidl, Morin Fruits et Légumes (primeur). Toutes les enseignes sont fréquentées.
- Budget : 100 EUR par semaine, plafond ferme, calculé sur les ingrédients uniquement.
- Priorité au fait maison pour remplacer l'industriel : goûters (barres de céréales gourmandes...), yaourts (yaourtière), bases (pâte à tarte, bouillon, pain...).
- Prix : pas de scraping des enseignes. Mise à jour des prix à partir des tickets de caisse, rapprochés de la liste de courses de départ.
- Premier lot de recettes de saison fourni par Claude, incluant goûters, yaourts et bases maison.
- Aucune contrainte alimentaire.
- Étiquettes de recettes.

## Modèle de données

Comptes et foyers
- users : authentik_sub (identifiant unique), username, email (non unique), nom affiché, rôle (admin/membre), dernière connexion, foyer (v0.2.0)
- households : nom, budget hebdo (10000 centimes par défaut), magasin principal, onboarding terminé
- household_members : nom, catégorie (adulte/enfant/tout-petit), coefficient de portion, lié ou non à un compte
- equipment : référentiel des appareils (four, air fryer, Companion, Cookeo, yaourtière...)
- household_equipment : appareils dont dispose le foyer

Référentiel
- units : g, kg, ml, cl, dl, l, c. à soupe (15 ml), c. à café (5 ml), pièce, pot... avec dimension (masse/volume/pièce) et facteur vers l'unité de base
- ingredients : nom, rayon, unité de base, poids moyen d'une pièce, densité (optionnel), mois de saison, produit frais, produit de base "toujours en stock" (sel, huile...)
- ingredient_packs : conditionnements achetables (bouteille 1 L, brique 20 cl, vrac au kg...)
- aisles, stores, store_aisle_order : rayons, magasins, ordre des rayons par magasin
- prices : prix par conditionnement et par magasin, historisé (date, source manuelle/ticket, promo)

Recettes
- recipes : auteur, titre, catégorie (plat, entrée, dessert, goûter, petit-déjeuner, base maison, boisson), rendement (quantité + type : personnes, pots, pièces, grammes), temps (préparation/cuisson/repos), difficulté, photo, source, type de protéine, prix de l'équivalent industriel (optionnel), brouillon/publié, parent (duplication)
- recipe_ingredients : position, groupe ("Pour la sauce"), ingrédient, quantité, unité, note ("émincé"), optionnel
- recipe_steps : position, texte, minuteur optionnel, appareil utilisé (optionnel)
- recipe_equipment : appareils requis (une recette dont un appareil manque au foyer est signalée ou masquée)
- tags, recipe_tags : catégories (régime, temps, occasion, ambiance)
- favorites (et plus tard notes, historique "cuisiné le")

Planning et stock
- meal_plans : foyer, semaine, statut
- meal_entries : recette OU libellé libre (restaurant, invités...), date/créneau optionnels, type (cuisiner/dehors/restes), rendement voulu (personnes ou pots/pièces pour les bases et goûters), invités
- pantry_items : stock du foyer (ingrédient, quantité, date limite optionnelle)

Courses
- shopping_lists, shopping_items : quantité nécessaire, conditionnement choisi, nombre de conditionnements, magasin, prix estimé, prix réellement payé, coché par/quand, origine (auto/manuel), recettes à l'origine de la ligne
- receipts : ticket rattaché à une liste (magasin, date, total, photo), servant à recaler les prix

## Algorithme de la liste de courses

1. Facteur par repas = rendement voulu / rendement de base de la recette (personnes pondérées par coefficient, ou pots/pièces) ; quantités multipliées.
2. Conversion vers l'unité de base de l'ingrédient (densité / poids de pièce si nécessaire).
3. Somme par ingrédient sur tous les repas de la période.
4. Déduction du stock ; ingrédients "de base" listés en "à vérifier".
5. Choix du conditionnement le moins cher couvrant le besoin ; arrondi au vrac pour les fruits et légumes.
6. Affectation aux magasins selon la stratégie (un magasin / deux magasins / optimisé) avec comparaison des totaux.
7. Reliquats (ex. 40 cl de lait) proposés en stock et exploités par les suggestions anti-gaspi.

## Prix et tickets de caisse

Après les courses, écran de rapprochement : la liste est reprise ligne par ligne et on saisit le prix payé ; le prix du conditionnement est mis à jour pour ce magasin, avec historique. Complément : Louis peut envoyer la photo d'un ticket dans une conversation avec Claude, qui produit un fichier d'import prêt à charger dans l'appli. OCR automatique dans l'appli : bonus, plus tard.

## Budget

Jauge en direct pendant le choix des menus, alerte à 80 %, rouge au-delà de 100 EUR (ingrédients uniquement). Suggestions d'échange vers des recettes moins chères / de saison. Pour les recettes maison, affichage de l'économie réalisée par rapport à l'équivalent industriel.

## Étiquettes

Manuelles : veggy, végan, rapido (moins de 30 min), gourmand, batch cooking, enfant-friendly, one-pot, sans four, léger, réconfort, invités, lunchbox, congélation possible.
Calculées automatiquement : de saison, économique (coût par portion), maison rentable (écart avec l'industriel), compatible avec mes appareils.

## Feuille de route (validation à chaque étape)

1. Cadrage et modèle de données - VALIDÉ (v0.0.2)
2. Socle : stack Docker, accès navigateur, dépôt Git, SSO Authentik (v0.1.0, correctif v0.1.1) - EN LIGNE, validation en cours
3. Mon foyer : assistant de première connexion (membres, coefficients, appareils), multi-foyers, rôles (v0.2.0)
4. Référentiel ingrédients, unités, rayons, magasins (dont Lidl), conditionnements, prix (v0.3.0)
5. Recettes : saisie, édition, étapes, photo, étiquettes, appareils ; premier lot de recettes de saison + goûters, yaourts, bases maison (v0.4.0)
6. Affichage d'une recette proratisée (v0.5.0)
7. Planning libre et repas cumulables (v0.6.0)
8. Liste de courses agrégée, par magasin et par rayon, cochable en temps réel (v0.7.0)
9. Économies : coûts, rapprochement des tickets, saisonnalité, stock, anti-gaspi, maison vs industriel (v0.8.0)
10. Menu de la semaine proposé automatiquement dans le budget (v0.9.0)
11. Bonus : import de recette par URL, OCR des tickets, sauvegardes automatiques, équilibre nutritionnel hebdomadaire

Note : l'assistant "Mon foyer", initialement rattaché au socle, a été isolé en v0.2.0 pour que la première mise en ligne ne teste que l'infrastructure et la connexion.

## État d'avancement

- v0.0.1 : cadrage rédigé.
- v0.0.2 : cadrage validé, reconnaissance de la VM (lecture seule).
- v0.1.0 : socle installé sur la VM et en ligne (https://foodtruck.louisrousseaux.fr), connexion Authentik fonctionnelle.
- v0.1.1 : correctif - e-mail non unique (deux comptes Authentik avec la même adresse bloquaient la connexion) + commandes de gestion des comptes. Validation du socle en cours.

## Questions ouvertes

Aucune bloquante. À traiter en v0.2.0 : nom du foyer, composition exacte, appareils possédés (saisis dans l'assistant).
