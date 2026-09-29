# Cadrage - Foodtruck (application menus / recettes / courses)

Version du document : v0.5.0 (étape 6 - tickets de caisse / Paperless livrés, en attente de validation)
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
- Rattachement d'un compte à un foyer (décision v0.2.0) : l'admin du foyer génère un lien d'invitation à usage unique, valable 7 jours ; sans invitation, un compte crée son propre foyer via l'assistant. Un compte = un seul foyer ; il peut le quitter.
- Droits sur le foyer (décision v0.2.0) : seuls les admins du foyer modifient réglages, membres, appareils, comptes et invitations ; les autres consultent. Le créateur du foyer en est admin ; le dernier admin ne peut ni partir ni être rétrogradé.
- Deux niveaux de rôle : rôle applicatif (users.role admin/membre) et rôle dans le foyer (users.household_role admin/membre).
- Composition du foyer paramétrable à la création du compte (assistant de première connexion). Coefficients : adulte 1, enfant 0,6, tout-petit 0 (modifiables).
- Équipements de cuisine du foyer paramétrables : liste commune de 14 appareils par défaut (four, plaques, micro-ondes, air fryer, Companion, Cookeo, yaourtière, robot pâtissier, blender, mixeur plongeant, machine à pain, autocuiseur, congélateur, barbecue/plancha), extensible par saisie libre (décision v0.2.0) ; chaque appareil a un slug qui servira aux recettes.
- Magasins : Leclerc Drive, Carrefour, Grand Frais, Hyper U, Lidl, Morin Fruits et Légumes (primeur). Toutes les enseignes sont fréquentées.
- Budget : 100 EUR par semaine, plafond ferme, calculé sur les ingrédients uniquement.
- Priorité au fait maison pour remplacer l'industriel : goûters (barres de céréales gourmandes...), yaourts (yaourtière), bases (pâte à tarte, bouillon, pain...).
- Prix : pas de scraping des enseignes. Mise à jour des prix à partir des tickets de caisse, rapprochés de la liste de courses de départ.
- Magasins du foyer (décision v0.3.0) : magasin principal Leclerc Drive, fruits et légumes chez Morin (primeur). Réglables dans Mon foyer (et dès l'assistant pour un nouveau foyer).
- Référentiel ouvert (décision v0.3.0) : tout membre d'un foyer peut ajouter des ingrédients, des conditionnements et corriger les prix. Liste d'ingrédients et prix communs à toute l'instance.
- Jeu de départ : 138 ingrédients courants avec rayon, saison, conditionnements et prix ESTIMÉS (Leclerc Drive, Morin) datés du 01/09/2026, marqués « estimé » jusqu'à confirmation. Fichier src/database/data/ingredients.php, import idempotent (foodtruck:reference) qui n'écrase jamais une correction.
- Premier lot de recettes de saison fourni par Claude, incluant goûters, yaourts et bases maison.
- Aucune contrainte alimentaire.
- Étiquettes de recettes.
- Saisie des ingrédients d'une recette (décision v0.4.0) : une ligne par ingrédient, ingrédient choisi dans le référentiel (autocomplétion), quantité + unité (vides = « selon goût »), précision, facultatif, groupe (« Pour la pâte »). Une quantité non convertible (ex. farine « à la pièce ») est refusée avec un message.
- Photos (décision v0.4.0) : stockées sur la VM (src/storage/app/public/recettes), redressées, redimensionnées à 1600 px + vignette 640 px, WebP.
- Brouillons : une recette en brouillon n'est visible que par son auteur. La duplication crée un brouillon rattaché à l'original (variante).
- Premier lot : 24 recettes (15 plats d'automne, 3 bases/yaourts, 6 goûters et petit-déjeuner), fichier src/database/data/recipes.php, import idempotent (foodtruck:recipes). Elles n'ont pas d'auteur : seul un admin de l'appli peut les modifier, les autres les dupliquent.

## Modèle de données

Comptes et foyers
- users : authentik_sub (identifiant unique), username, email (non unique), nom affiché, rôle applicatif (admin/membre), dernière connexion, household_id, household_role (admin/membre)
- households : nom, budget hebdo en centimes (10000 par défaut), magasin principal, magasin des fruits et légumes, créateur
- household_members : nom, catégorie (adulte/enfant/tout-petit), coefficient de portion (décimal 0 à 2), compte lié optionnel (un compte = une fiche au plus), position
- equipment : liste commune des appareils (nom, slug unique, par défaut ou ajouté, créé par)
- household_equipment : appareils dont dispose le foyer
- household_invitations : foyer, empreinte SHA-256 du jeton (jamais le jeton en clair), créée par, expiration, utilisée le / par

Référentiel
- Unités (définies dans le code, App\Support\Units, pas en base) : g, kg, pincée (0,5 g), ml, cl, dl, L, c. à café (5 ml), c. à soupe (15 ml), verre (200 ml), pièce ; trois dimensions (masse → g, volume → ml, pièce) ; conversions entre dimensions via la densité et le poids d'une pièce de l'ingrédient
- ingredients : nom, slug (stable, sert d'URL), rayon, unité de base (g, ml ou pièce, verrouillée dès qu'un conditionnement existe), poids d'une pièce (g), densité (g/ml), mois de saison (null = toute l'année), produit frais, produit de base (listé « à vérifier »), créé par
- ingredient_packs : conditionnements achetables (libellé, quantité dans l'unité de base, vrac oui/non)
- aisles : 14 rayons avec ordre par défaut ; stores : 6 magasins (Leclerc Drive, Morin, Lidl, Carrefour, Hyper U, Grand Frais) ; households.main_store_id et produce_store_id ; ordre des rayons propre à chaque magasin : prévu avec la liste de courses (v0.7.0)
- prices : prix par conditionnement et par magasin, historisé (centimes, date du prix, origine estimation/manuel/ticket, promo, saisi par) ; le prix courant est le plus récent par date ; meilleure offre = prix le plus bas ramené au kg / L / pièce

Recettes
- recipes : slug, auteur, titre, présentation, catégorie (plat, entrée, accompagnement, dessert, goûter, petit-déjeuner, base maison, boisson), rendement (quantité + personnes | parts | pièces | pots | grammes), temps (préparation/cuisson/repos en min), difficulté, protéine principale, source, prix de l'équivalent industriel (centimes), photo + vignette, statut (publie | brouillon), parent (duplication)
- recipe_ingredients : position, groupe ("Pour la sauce"), ingrédient, quantité, unité, note ("émincé"), optionnel
- recipe_steps : position, texte, minuteur optionnel, appareil utilisé (optionnel, ajouté automatiquement aux appareils requis)
- recipe_equipment : appareils requis (une recette dont un appareil manque au foyer est signalée ou masquée)
- tags, recipe_tag : 13 étiquettes manuelles
- recipe_favorites : favoris par compte (plus tard : notes, historique « cuisiné le »)
- Coût d'une recette (App\Support\RecipeCost) : quantité utilisée × meilleur prix connu ramené au kg/L/pièce (estimation au prorata, hors « selon goût » et facultatifs) ; par personne, par pot, par pièce ou pour 100 g. « Économique » : ≤ 1,50 € par personne avec des prix complets.

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
2. Socle : stack Docker, accès navigateur, dépôt Git, SSO Authentik (v0.1.0, correctif v0.1.1) - VALIDÉ le 2026-09-28
3. Mon foyer : assistant de première connexion (membres, coefficients, appareils), multi-foyers, rôles, invitations (v0.2.0) - VALIDÉ le 2026-09-28
4. Référentiel ingrédients, unités, rayons, magasins (dont Lidl), conditionnements, prix (v0.3.0) - VALIDÉ le 2026-09-28
5. Recettes : saisie, édition, étapes, photo, étiquettes, appareils ; premier lot de recettes de saison + goûters, yaourts, bases maison (v0.4.0) - VALIDÉ le 2026-09-29
6. Tickets de caisse : connexion Paperless, lecture, rapprochement, prix réels (v0.5.0) - LIVRÉ, en attente de validation ; ajustement aux vrais tickets en v0.5.x
7. Affichage d'une recette proratisée (v0.6.0)
8. Planning libre et repas cumulables (v0.7.0)
9. Liste de courses agrégée, par magasin et par rayon, cochable en temps réel (v0.8.0)
10. Économies : budget, stock, anti-gaspi, rapprochement ticket ↔ liste de courses (v0.9.0)
11. Menu de la semaine proposé automatiquement dans le budget (v0.10.0)
12. Bonus : import de recette par URL, sauvegardes automatiques (dump quotidien vers archive-nas), supervision (état de la synchro Paperless dans Prometheus/Talk), IA locale pour les libellés inconnus, équilibre nutritionnel hebdomadaire

Note : l'assistant "Mon foyer", initialement rattaché au socle, a été isolé en v0.2.0 pour que la première mise en ligne ne teste que l'infrastructure et la connexion.

## État d'avancement

- v0.0.1 : cadrage rédigé.
- v0.0.2 : cadrage validé, reconnaissance de la VM (lecture seule).
- v0.1.0 : socle installé sur la VM et en ligne (https://foodtruck.louisrousseaux.fr), connexion Authentik fonctionnelle.
- v0.1.1 : correctif - e-mail non unique (deux comptes Authentik avec la même adresse bloquaient la connexion) + commandes de gestion des comptes. Appliqué sur la VM ; v0.1.0 et v0.1.1 poussées sur GitHub (tags). Compte Tobilianok admin, compte akadmin retiré de Foodtruck.
- Socle validé par Louis le 2026-09-28 : groupe foodtruck lié à l'application dans Authentik (accès réservé au groupe), déconnexion testée.
- v0.2.0 : Mon foyer livré - assistant de création (nom, budget, membres et coefficients, appareils), page Mon foyer (réglages, membres, appareils, comptes, invitations), invitations à usage unique, messages de validation en français, 23 tests automatisés exécutés par le script de mise à jour. Installé et validé par Louis le 2026-09-28 (foyer « Famille Tobilianok » créé).

- v0.3.0 : référentiel livré - liste des ingrédients (recherche, rayon, de saison, meilleur prix), fiche ingrédient (prix par magasin, conditionnements, caractéristiques, historique), création, mise à jour rapide des prix par magasin (par défaut les produits déjà vendus dans ce magasin), magasins du foyer, 138 ingrédients de départ, 41 tests.

- v0.3.0 validée par Louis le 2026-09-28.
- v0.4.0 : recettes livrées - liste en cartes (recherche, catégorie, étiquette, de saison, 30 min max, faisable avec mes appareils, favoris, brouillons), fiche (ingrédients groupés, équivalences, étapes avec minuteur et appareil, coût, économie vs industriel, appareils manquants), formulaire (lignes dynamiques, autocomplétion), photo, brouillon, duplication, favoris, 24 recettes de départ, 50 tests. Choix de saisie et photos pris par défaut (voir décisions), à confirmer par Louis.

## Intégration Paperless (v0.5.0, décidée le 2026-09-29)

Objectif : Foodtruck lit les tickets de caisse rangés dans Paperless-ngx (étiquette « courses alimentaires », correspondant = magasin) et en tire les prix réellement payés ; avec le temps, les prix deviennent de plus en plus fiables.
- Tickets : scannés au scanner (propres) ou dématérialisés (PDF texte) quand l'enseigne le propose (la plupart des enseignes du foyer le font).
- Réglages par foyer, dans Mon foyer (admins) : adresse de Paperless (http://192.168.1.14:8010), jeton d'API d'un compte Paperless dédié en lecture seule (stocké chiffré), étiquette. Un workflow Paperless donne à ce compte la lecture des documents de l'étiquette.
- Synchronisation : toutes les heures (conteneur foodtruck-scheduler, schedule:work) et à la demande (bouton). Nouveaux documents importés ; documents modifiés relus tant que le ticket n'est pas traité.
- Lecture (App\Support\Receipts\ReceiptParser) : règles génériques des tickets français (prix en fin de ligne + code TVA, « 2 x 1,05 », « 0,856 kg x 2,49 €/kg », remises rattachées à l'article précédent, TOTAL / NET A PAYER, date, erreurs O/0). Contrôle : somme des lignes lues comparée au total du ticket. Règles par enseigne à ajuster avec de vrais tickets (v0.5.x).
- Rapprochement (ReceiptMatcher) : libellé mémorisé pour ce magasin → reconnu ; mémorisé ailleurs ou ressemblance avec un ingrédient (abréviations des tickets) → proposé ; sinon à associer. Choix du conditionnement d'après le libellé (« 1L », « 6X1L », « X12 », vrac pour une pesée).
- Application (ReceiptProcessor) : prix du conditionnement déduit (pesée → prix au kilo ramené au conditionnement ; « 6X1L » associé à la bouteille → prix d'une bouteille ; remise → promo), origine « ticket », daté du jour d'achat, pour le magasin du ticket. Libellés mémorisés (receipt_aliases, par magasin), y compris « toujours ignorer » (sac, non alimentaire). Un ticket dont toutes les lignes sont connues est traité automatiquement. Un ingrédient choisi sans conditionnement : conditionnement déduit du ticket, créé si la quantité lue n'existe pas (« 50 cl (ticket) »).
- Saisie manuelle : coller le texte d'un ticket (hors Paperless).
- Pas d'IA locale (VM docker trop juste en RAM, CPU du ML150 sur-souscrit, Quadro P620 trop petite) ; à réévaluer plus tard pour suggérer les libellés inconnus.
- Plus tard : rapprochement avec la liste de courses (v0.8.0).

- v0.4.0 validée par Louis le 2026-09-29.
- v0.5.0 : tickets de caisse livrés - réglages Paperless (Mon foyer), page Tickets (synchronisation, filtres, saisie manuelle), écran de rapprochement, prix « ticket », libellés mémorisés, traitement automatique, conteneur foodtruck-scheduler, 63 tests. Lecteur générique, en attente de vrais tickets pour l'ajuster.

## Questions ouvertes

- Textes de vrais tickets (dématérialisés et scannés) de Leclerc Drive, Lidl, Morin… pour ajuster le lecteur (v0.5.1).
- Pour la v0.6.0 (recette proratisée) : choix du nombre de personnes (membres présents + invités), arrondis (« 1,3 œuf » → 1 ou 2 ?).
