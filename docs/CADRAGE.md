# Cadrage - Foodtruck (application menus / recettes / courses)

Version du document : v0.12.2 (étape 13 - recettes scannées dans Paperless : v0.12.0, puis v0.12.1 pour les cartes HelloFresh et v0.12.2 pour les fiches imprimées Leclerc, livrée le 2026-10-05, en attente de validation ; ensuite : v0.13.0 menu automatique)
Dernière mise à jour : 2026-10-05
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
- Planning (décidé le 2026-09-29, v0.7.0) : semaine du lundi au dimanche, partagée par tout le foyer (tous les membres planifient). Créneaux affichés par défaut : déjeuner, dîner et « À préparer » (fournées maison : yaourts, goûters, granola) ; petit-déjeuner et goûter activables dans Mon foyer. Semaine type dans Mon foyer (qui mange habituellement à la maison, par jour et par repas ; seules les absences sont enregistrées) : chaque repas en part, modifiable repas par repas (convives, invités, réglage libre). Plat pour plusieurs repas : restes placés automatiquement sur les déjeuners/dîners libres suivants où quelqu'un mange à la maison, déplaçables, « au congélateur » (liste « Restes mis de côté ») ou retirés ; comptés une seule fois dans le coût et les courses. Repas « hors maison » et notes libres. Coût estimé de la semaine comparé au budget (jauge : alerte à 80 %, rouge au-delà de 100 %). Ajout au planning depuis la fiche recette avec ses réglages.
- Proratisation (décidée le 2026-09-29, v0.6.0) : recette « pour N personnes » affichée par défaut pour les parts du foyer (somme des coefficients, 2,5 parts chez Louis) ; réglage sur la fiche : qui mange (membres cochés), invités adultes (1 part) et enfants (0,6 part), nombre de repas (1 à 4 : ce soir + demain midi, une part à congeler), ou parts par repas en réglage libre (au ½). Recettes en pots, pièces, parts de gâteau ou grammes : par fournée (×½, ×1, ×2, ×3 ou quantité saisie), indépendamment du foyer. Arrondi pratique : pièces à l'entier (½ sous 1, jamais 0), g/ml à 1, 5, 10 ou 50 près, cl à 1 (½ sous 10), cuillères et verres au ½, pincées à l'unité ; valeur exacte au survol ; quantités d'origine affichées telles quelles au facteur 1.
- Mutualisation des ingrédients dans la liste de courses (ex. 20 cl + 40 cl de lait = 1 bouteille de 1 L, une seule ligne).
- Planning libre : on choisit les repas voulus, pas de grille obligatoire (imprévus, restaurant, invités).
- Multi-foyers ; un seul foyer créé au départ.
- Rattachement d'un compte à un foyer (décision v0.2.0) : l'admin du foyer génère un lien d'invitation à usage unique, valable 7 jours ; sans invitation, un compte crée son propre foyer via l'assistant. Un compte = un seul foyer ; il peut le quitter.
- Droits sur le foyer (décision v0.2.0) : seuls les admins du foyer modifient réglages, membres, appareils, comptes et invitations ; les autres consultent. Le créateur du foyer en est admin ; le dernier admin ne peut ni partir ni être rétrogradé.
- Deux niveaux de rôle : rôle applicatif (users.role admin/membre) et rôle dans le foyer (users.household_role admin/membre).
- Composition du foyer paramétrable à la création du compte (assistant de première connexion). Coefficients : voir « Âge des membres » (v0.10.0), qui remplace adulte 1 / enfant 0,6 / tout-petit 0.
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

Liste de courses (décisions du 2026-10-03, v0.8.0)
- Période : au choix (du jour des courses jusqu'aux suivantes, par défaut aujourd'hui + 6 jours) ; des repas peuvent être écartés (invités ailleurs, restaurant). Les restes ne sont jamais recomptés (un plat cuisiné = une fois). Plafond : 31 jours.
- Magasins : magasin principal (Leclerc Drive) + magasin des fruits et légumes (Morin) pour ce rayon ; à défaut de prix dans ce magasin, le moins cher qui en a un. L'écart avec le magasin le moins cher est affiché, l'article peut être déplacé d'un clic.
- Liste partagée par le foyer, cochable en direct : les cases cochées par un téléphone apparaissent sur les autres en quelques secondes (interrogation toutes les 6 s, sans service supplémentaire).
- Produits de base (sel, huile, farine, épices…) : section « À vérifier chez vous », hors budget ; « Il m'en manque » les ajoute aux courses. Ajouts libres possibles (article connu = rayon et prix repris).
- Budget de la liste : budget hebdomadaire au prorata des jours, comparé au prix en caisse (paquets entiers) ; le surplus d'emballages est chiffré.

Stock et anti-gaspi (décisions du 2026-10-05, v0.9.0)
- Stock du foyer alimenté automatiquement et corrigeable à la main : en classant une liste (« Courses terminées »), le surplus des articles cochés (40 cl de lait restants d'une bouteille de 1 L…) entre au stock et ce qui a servi en sort ; ajout, modification et suppression à la main à tout moment.
- Un lot = ingrédient, quantité (unité de base), date limite optionnelle, lieu (placard, frigo, congélateur), remarque, origine (manuel | courses). Lieu par défaut selon le rayon (surgelés → congélateur ; crèmerie, fromages, boucherie, poissonnerie, charcuterie → frigo ; sinon placard).
- Consommation du plus proche de la date limite d'abord ; lots périmés ignorés par les listes. Mises à jour du stock appliquées une seule fois par liste (stock_applied_at).
- Listes de courses : le stock est déduit du besoin ; un besoin entièrement couvert passe dans « Déjà en stock » (rien à acheter, hors budget) ; « Ne pas utiliser le stock » (ou « Il n'y en a plus : l'acheter ») est possible article par article. Le stock fait partie de l'empreinte de la liste : le modifier invite à la recalculer.
- Anti-gaspi : « À consommer vite » (périmé ou date limite dans 3 jours ou moins) sur Stock et en bandeau du Planning ; « Que cuisiner ? » classe les recettes selon le stock (d'abord celles qui utilisent un produit à consommer vite, puis le plus d'ingrédients déjà là, puis le moins cher à compléter) avec bouton « Ajouter au planning ».
- Pas encore : durées de conservation proposées par défaut, et sortie du stock quand un plat est cuisiné (le stock ne diminue qu'à la fin des courses ou à la main).
- Découpage : v0.9.0 = stock et anti-gaspi ; v0.9.1 = rapprochement ticket ↔ liste, plat trop cher à remplacer, bilan, alerte de prix, commande de reset.

Âge des membres (décisions du 2026-10-05, v0.10.0)
- Chaque membre peut avoir une date de naissance (facultative pour un adulte). Le coefficient de portion suit l'âge à la date de chaque repas (planning à venir compris) : moins de 1 an 0 (lait, purées : pas compté) ; 1 à 3 ans 0,3 (plat simple à part) ; 3 à 5 ans 0,5 (mange comme les adultes) ; 5 à 12 ans 0,7 ; 12 à 15 ans 0,8 ; 15 ans et plus 1. Le changement a lieu le jour de l'anniversaire. Grille dans HouseholdMember::AGE_GRID (une seule source : serveur et formulaires).
- Réglage manuel possible (case « Régler à la main », ex. gros mangeur à 1,5) : le coefficient reste fixe. Sans date de naissance, le coefficient saisi reste fixe (comportement des versions précédentes : rien ne change tant qu'aucune date n'est saisie).
- Invités : adulte 1 part, enfant 0,6 part (inchangé).
- Age affiché dans Mon foyer (« 2 ans aujourd'hui · coefficient automatique · plat simple à part »), en mois sous 2 ans.

Interface (décision du 2026-10-05, livrée en v0.11.0)
- Constat de Louis : le fonctionnement est bon mais l'appli est floue et compliquée pour quelqu'un qui la découvre. Refonte complète, appliquée directement (sans maquettes préalables, au choix de Louis).
- Navigation à 5 onglets : Accueil, Menus (planning), Courses, Recettes, Plus. « Plus » est une page qui range Frigo et placards, Tickets de caisse, Prix et ingrédients, Mon foyer, Comment ça marche, et la déconnexion. Barre d'onglets en bas sur mobile, barre du haut sur ordinateur.
- Accueil guidé « la semaine en 4 étapes » : choisir les repas, préparer la liste, faire les courses, faire le bilan. Un camion indique l'étape en cours et un gros bouton mène à la suivante (classe WeekFlow). Dessous : repas du jour, budget de la semaine, dernier bilan, produits à consommer vite.
- Page « Comment ça marche » (/aide) : les 4 étapes et une foire aux questions.
- Principes : un titre et une phrase d'explication sur chaque page, états vides qui disent quoi faire, options avancées repliées (qui mange et combien de repas, filtres de recettes, options de la liste, Paperless sous « Avancé »), vocabulaire courant.
- Identité visuelle : fond papier, vert feuille (action), jaune beurre (aujourd'hui, à consommer vite), rouge tomate (alertes) ; polices Bricolage Grotesque et Figtree auto-hébergées (aucun appel externe) ; mode sombre automatique.

Recettes scannées (décision du 2026-10-05, livrée en v0.12.0)
- Fiches de recettes scannées dans Paperless (étiquette « recettes », réglable dans Mon foyer → Avancé) : Foodtruck les récupère automatiquement chaque heure (20 minutes après les tickets), ou à la demande avec « Chercher dans Paperless » (Recettes → Fiches Paperless), et crée la recette avec tous ses détails. Lecteur intégré gratuit, sans IA ni API externe, réglé sur de vraies fiches (Louis les fournit une par une, formats différents). L'option API Claude a été écartée pour l'instant.
- Ce que le lecteur comprend : titre (celui de Paperless, sinon l'en-tête imprimé ou le grand titre), nombre de personnes (« Nombre de couverts », « Pour 4 personnes »), temps de préparation / cuisson / repos (« 10 min », « 1 h 30 »), ingrédients (quantité, unité, précision entre parenthèses, « ou » en variante, groupes « Pour la pâte »), étapes, conseil, source (auteur et site de l'en-tête d'impression), étiquette « végétarien » / « végan » d'après le titre, catégorie probable, protéine principale probable, appareils cités (four, mixeur plongeant…), minuteur d'une étape quand une seule durée y est citée.
- Nettoyage : pieds de page (« 1 sur 2 05/10/2026 »), adresses web, en-têtes répétés, lignes © ; ligatures perdues à l'impression (« �nement » devient « finement »).
- Étapes : cinq mises en page reconnues, dans l'ordre : numéro seul sur sa ligne ; « 1. », « 2) », « Étape 3 » ; numéro au milieu du bloc de l'étape (impression de site à deux colonnes, cas de julieandrieu.com) ; puces ; paragraphes. Un encadré « conseil » imprimé à côté des étapes est séparé et rangé dans la présentation de la recette (« Conseil de Julie : … »).
- Rapprochement avec le référentiel d'ingrédients, dans l'ordre : rapprochement déjà validé à la main (appris) ; synonymes courants (oignon → Oignon jaune, crème épaisse, sel, poivre, pâtes courtes…) ; nom identique ; ingrédient contenu dans le libellé avec seulement une précision en plus (« comté 24 mois râpé » → Comté, mais « pâte à tartiner » ne devient pas « Pâtes ») ; libellé contenu dans l'ingrédient (« sauge » → Sauge fraîche). En cas d'égalité ou de doute, aucun choix : la fiche attend une relecture.
- Quantités : chaque quantité doit se convertir dans l'unité de l'ingrédient. « 7 cl de bouillon » devient une fraction de cube (1 cube pour 50 cl, arrondi au quart de cube au-dessus).
- Décision après lecture : tout reconnu et sans réserve → recette publiée automatiquement ; tout reconnu avec une réserve (titre déjà pris, nombre de personnes absent, étape au découpage incertain) → recette créée en brouillon, à relire ; un ingrédient ou une unité pose problème → pas de recette, la fiche attend sa relecture (formulaire de recette prérempli, lignes à vérifier marquées en rouge avec les ingrédients proches et un lien « Créer cet ingrédient »). L'auteur est l'administrateur du foyer.
- Relecture : à la validation, les rapprochements corrigés à la main sont retenus (table recipe_aliases) et servent aux fiches suivantes. « Relire la fiche » (ou ./ft php artisan foodtruck:relire-recettes) reprend le texte avec les règles à jour, par exemple après l'ajout d'un ingrédient. « L'ignorer » met de côté un document qui n'est pas une recette. Une fiche déjà transformée en recette n'est jamais relue (le travail de relecture est conservé).
- Un document sans texte (reconnaissance de Paperless pas encore terminée) est simplement réessayé à la synchronisation suivante.
- Référentiel : ajout de Lait fermenté (ribot), Sauge fraîche, Romarin frais, Thym frais, Menthe fraîche (prix estimés).
- Le lecteur se règle fiche après fiche : chaque nouveau format apporté par Louis devient un test automatique (tests/Fixtures/paperless).

Fiches de kits repas HelloFresh (décision du 2026-10-05, livrée en v0.12.1)
- Format reconnu (MealKitSheetParser) : « Ingrédients pour N personnes » avec « Mes ustensiles » et « C'est parti ! » ou la mention HelloFresh. Lecteur dédié, séparé de celui des autres fiches (celui de Julie Andrieu reste inchangé, sortie identique). Premier exemple : Curry thaï léger aux crevettes & coco (document Paperless n° 481), fixture tests/Fixtures/paperless/hellofresh-curry-thai-crevettes.txt.
- Particularités du texte Paperless de ces cartes : colonnes mélangées (le tableau des ingrédients et les valeurs nutritionnelles sont imprimés à gauche des étapes), numéros d'étapes absents du texte (ils sont sur les photos), fractions ½ et ¼ perdues ou lues « % », « # », « Z », « 12 », « 4 », mots collés, ligne du tableau absorbée par la mise en page.
- Lecture : ingrédients « nom puis quantité » ; bloc « À ajouter vous-même » en groupe ; légende des photos utilisée pour retrouver un ingrédient absent du tableau (« Lait de coco », quantité retrouvée dans les étapes « le paquet de lait de coco ») ; étapes titrées reconstituées (colonnes séparées par repère de puce, fin de phrase ou équilibre des longueurs) et remises dans l'ordre de la carte : première colonne, colonnes du milieu et de droite, deuxième rangée ; encadrés « L'astuce du chef » et « Zoom nutrition » en conseils.
- Sachets, paquets, cm : comptés en pièces avec la précision (« sachet », « paquet », « 1 cm ») ; leur conversion en poids dépend de l'ingrédient du référentiel (poids d'une pièce) : si elle échoue, la ligne est signalée à la relecture comme les autres unités non convertibles.
- Règle de publication : une fiche de ce format n'est jamais publiée toute seule (réserve « fiche à colonnes ») ; toute fraction supposée ou ligne absorbée met la ligne en rouge et la fiche attend sa relecture (pas de recette créée tant que la relecture n'a pas eu lieu).
- Temps : « À table dans : 35 - 45 Min » = temps total, rangé en préparation (borne haute).
- Limites connues : une fraction lue comme un chiffre (« 2 cc » pour ½ cc) n'est pas réparable à coup sûr : réserve affichée, à comparer au PDF ; le texte d'une carte bien mélangée peut mal se couper (étapes en deux colonnes) ; chaque nouvelle carte (autre semaine, autre recette) devient un test.

Fiches imprimées et bruit de reconnaissance de texte (décision du 2026-10-05, livrée en v0.12.2)
- Troisième format : fiche imprimée Leclerc « Pâtes carbonara » (document Paperless n° 484, fixture tests/Fixtures/paperless/leclerc-pates-carbonara.txt) : deux colonnes (ingrédients / recette), « Etape 1 » à « Etape 6 », bandeau « 4 pers 20 mn », pied de page avec adresse.
- Règles générales ajoutées (pas propres à ce format) : puces lues « e » ou « ?? » ; ligne qui commence par une quantité = nouvel ingrédient ; quantité démesurée corrigée en retirant le début pris pour une puce (« 227100 g » → 100 g) et toujours signalée ; bandeau personnes + temps ; titre répété après la liste ignoré ; « Étape N » seule sur sa ligne avec le texte après une ligne vide ; pied de page « Retrouvez … sur adresse » → source.
- Principe inchangé : tout ce qui est corrigé ou douteux met la ligne en rouge et la fiche attend la relecture de Louis (jamais de publication automatique sur une lecture supposée).
- Temps d'une carte (« 20 mn ») : rangé en préparation (la carte ne distingue pas préparation et cuisson).

Suppression des fiches Paperless à relire (décision du 2026-10-05, v0.13.1 puis v0.13.2)
- Demande de Louis : pouvoir supprimer les fiches qui arrivent dans « À relire ». Réglage final (v0.13.2) : « Supprimer » efface la fiche complètement, pour que « Chercher dans Paperless » la retraite depuis zéro si le document porte toujours l'étiquette « recettes ». (La v0.13.1 mettait les fiches de côté sans jamais les relire : abandonné à la demande de Louis.)
- Conséquence assumée : un document supprimé mais toujours étiqueté « recettes » revient à la prochaine recherche (manuelle ou horaire). Pour qu'il ne revienne plus : retirer l'étiquette dans Paperless.
- Brouillon de recette issu de la fiche : supprimé avec elle (sauf s'il est au planning). Recette publiée : jamais supprimée par ce bouton. Rapprochements d'ingrédients appris : conservés.

Menu automatique (décisions du 2026-10-05, v0.13.0)
- Réponses de Louis : repas remplis = dîners et déjeuners, restes comptés ; règles = pas deux fois la même protéine de suite, au moins N repas végétariens par semaine, plats rapides (moins de 30 minutes) en semaine, recettes de saison en priorité ; priorité « Équilibre : budget, stock, variété » ; usage « Semaine entière à valider ».
- Fonctionnement : MenuScorer (classe pure, sans base de données, testée) note chaque recette pour chaque repas ; MenuGenerator parcourt la semaine dans l'ordre, retient la meilleure, place les restes (MealPlanner::placeLeftovers) et recompte budget, protéines et végétarien après chaque choix. MenuController gère proposer, garder, autre idée, valider, effacer.
- Une proposition est un repas du planning marqué proposed_at (avec proposal_reason). Tant qu'il est marqué, il est exclu de la liste de courses (scope MealPlanEntry::confirmed), de l'accueil et de « Que faire maintenant ? », mais visible et compté dans le coût du planning. « Garder » ou « Valider » retire la marque ; modifier le repas la retire aussi.
- Réglage : households.menu_veggy_min (repas végétariens minimum par semaine, 2 par défaut), choisi dans le bloc du planning.
- Hors périmètre de cette version : goûters et petits-déjeuners, plats d'une autre catégorie que « plat », rendements autres que « personnes », préférences par membre (aliments exclus, allergies), équilibre nutritionnel (étape bonus).
- Recettes éligibles : publiées, catégorie plat, rendement en personnes, appareils du foyer disponibles. Il faut assez de recettes différentes pour remplir la semaine (8 plats différents pour 14 repas) : plus le catalogue est riche, meilleure est la proposition.

Lecture fiable des fiches : service foodtruck-ocr (décision du 2026-10-05, v0.15.0)
- Demande de Louis : « je veux que le processus soit fiable même si je dois rajouter des outils à la stack ». Toujours tout en local : pas de cloud, rien sur le PC de jeu.
- Constat : les fiches sont des scans d'image sans texte (photocopieuse Ricoh) ; le texte « à plat » de Paperless perd la mise en page et mélange les colonnes. Essai du 2026-10-05 : une lecture qui garde la position des mots (Tesseract, découpage par bandes et colonnes) sépare parfaitement les colonnes de la Croziflette et de l'Orzo HelloFresh.
- Choix de Louis : niveau 1 maintenant, niveau 2 plus tard. Niveau 1 (v0.15.0) = conteneur foodtruck-ocr (Tesseract français + Poppler, découpage par position, environ 300 Mo de mémoire en lecture) + LayoutComposer (remise en forme par règles) + relecture humaine. Niveau 2 (plus tard, si les photos de livres résistent) = petite IA locale (Ollama, modèle d'environ 4 milliards de paramètres) qui rangerait les blocs en titre / ingrédients / étapes, en arrière-plan.
- RAM : Louis peut donner +4 Go à la VM Docker dans Proxmox (VM à environ 11 Go). Inutile pour le niveau 1, utile avant un éventuel niveau 2.
- Découpage des colonnes (v0.15.1) : gouttière = bande verticale traversée par presque aucune ligne de texte (tolérance de quelques mots si la bande a au moins 8 lignes, vrai blanc sinon) ; un tableau nom/quantité n'est jamais coupé ; un bout de ligne reste avec sa ligne (à gauche), y compris quand plusieurs rangées débordent dans la même gouttière (v0.15.2 : jusqu'à 8 bouts de 1 à 3 mots).
- Fichier lu (v0.15.4) : toujours l'original du document Paperless (/api/documents/{n}/download/?original=true). La version archivée (PDF/A) a des images recompressées qui dégradent la lecture (mots collés).
- Précision de la lecture (v0.15.2) : modèle français tessdata_best (somme de contrôle fixée dans le Dockerfile), gris par le canal le plus sombre + contraste + netteté, 300 dpi, puis seconde lecture « texte épars » (--psm 11) dont on n'ajoute que les lignes nettes que la première lecture n'a pas vues. Banc d'essai : 39 repères sur 39 sur les cinq fiches de test.
- Rapprochement des ingrédients (v0.15.2) : alias appris > synonymes > nom exact > ingrédient contenu dans le libellé > libellé contenu dans l'ingrédient > lecture approchante (une ou deux lettres de différence, toujours signalée). Une couleur n'est ignorée que si l'ingrédient n'en précise aucune ; une couleur ou une précision commune ne suffit jamais à proposer.
- Écran de relecture (v0.15.2) : liste compacte, une ligne par ingrédient ; lignes à vérifier bordées de rouge avec le texte lu, le problème et les propositions cliquables ; filtre « seulement les lignes à vérifier ».
- Fonctionnement : synchronisation → fiche « en cours de lecture » → lecture chaque minute par le planificateur (foodtruck:lire-fiches) → analyse. Secours : texte de Paperless si le service échoue ou comprend moins bien. Les fiches déjà transformées en recettes ne sont jamais relues.

Fiches à colonnes mélangées (v0.14.0, 2026-10-05)
- Quatrième format : fiche Leclerc « Croziflette » (document n° 487, fixture tests/Fixtures/paperless/leclerc-croziflette.txt), même mise en page que la carbonara mais colonnes mélangées ligne par ligne par la reconnaissance de texte de Paperless. Le PDF de la photocopieuse (Ricoh) n'a pas de texte : tout dépend de la lecture de Paperless.
- Règle générale ajoutée (ColumnSplitter) : titres « Les ingrédients » et « La recette » sur la même ligne → chaque ligne à puce coupée entre l'ingrédient et la suite de la recette, lignes sans puce rangées dans la recette, repères d'étapes mal lus renumérotés. Toujours une réserve (fiche à relire).

Lecture des fiches : tout reste local (décision du 2026-10-05)
- Louis aura beaucoup de fiches, de formats très différents (cartes de kits repas, photos prises sur Internet, photos de pages de livres).
- Contrainte ferme : aucune API cloud (pas d'API Claude ni autre) et RIEN d'installé sur son PC de jeu. Tout tourne sur la VM Docker.
- Matériel de la VM : 6 vCPU Xeon E5-2630L v3 à 1,8 GHz, 7,2 Go de RAM dont environ 3,6 Go disponibles, aucune carte graphique, 105 Go de disque libres. Un modèle de vision local utile (7-8 milliards de paramètres, 6 Go) n'y tient pas sans pénaliser Paperless et Authentik : piste écartée tant que la VM n'a pas plus de RAM (à rouvrir si Louis lui en ajoute dans Proxmox ; Qwen3-VL 4B ou 8B via Ollama, file d'attente en arrière-plan).
- Choix retenu : garder le lecteur à règles (RecipeTextParser, MealKitSheetParser), amélioré fiche après fiche, et l'écran de relecture (lignes douteuses en rouge, validation par Louis avant publication) comme filet de sécurité. Une photo de livre mal reconnue reste à corriger à la main dans le formulaire prérempli ; la saisie manuelle d'une recette reste toujours possible.
- Paperless : workflow « Foodtruck - lecture des recettes » créé par Louis le 2026-10-05 (déclencheurs : document ajouté et document mis à jour, filtre étiquette « recettes » ; action : droit de consultation pour l'utilisateur foodtruck). Les nouvelles fiches étiquetées « recettes » sont donc visibles par Foodtruck sans manipulation.

Économies et bilan (décisions du 2026-10-05, v0.9.1)
- Un ticket traité est rattaché automatiquement à la liste de courses dont la période correspond à sa date d'achat (courses faites de 4 jours avant le début à 1 jour après la fin ; la liste dont le début est le plus proche l'emporte). Le rattachement se change ou se retire à la main depuis la fiche du ticket, et un ticket peut être rattaché depuis le bilan.
- Articles retrouvés sur le ticket (même ingrédient, quel que soit le magasin) : cochés automatiquement dans la liste (cases partagées prévenues). Sur une liste déjà classée, rien n'est coché (le stock a déjà été mis à jour sans eux).
- Prix : les tickets recalent déjà les prix du référentiel depuis la v0.5.0 (prix « ticket » datés du jour d'achat, utilisés par les listes suivantes) ; la v0.9.1 en ajoute la comparaison et l'alerte.
- Bilan d'une liste (page « Bilan », ouverte à la fin des courses, accessible depuis la liste, l'historique et l'accueil) : budget de la période, estimé (liste), payé (lignes alimentaires des tickets), jauge ; écart article par article ; articles de la liste non retrouvés sur les tickets ; achats hors liste ; stock rangé après les courses.
- Prix qui monte : +10 % et +10 centimes au moins par rapport au dernier prix réel (ticket ou saisi) du même conditionnement dans le même magasin ; promotions ignorées. Estimations recalées : même seuil par rapport à l'estimation de départ, dans les deux sens.
- Plat trop cher : à partir de 80 % du budget de la semaine, le planning propose pour les 3 plats les plus chers encore à cuisiner (aujourd'hui ou plus tard, rendement en personnes, hors fournées) jusqu'à 3 recettes de la même catégorie, publiées, pas déjà au planning de la semaine, au coût complet connu et plus bas d'au moins 50 centimes et 10 %, pour les mêmes convives. « Remplacer » conserve le jour, le créneau, les convives et les restes.
- Reset (commande ./ft reset) : efface planning, listes de courses et stock, jamais les comptes, foyers et réglages, recettes, référentiel, tickets et prix. Aperçu des quantités, confirmation à taper (EFFACER), sauvegarde de la base dans backups/ avant. Option --foyer=N pour un seul foyer. Jamais lancé par un script de mise à jour.

## Modèle de données

Comptes et foyers
- users : authentik_sub (identifiant unique), username, email (non unique), nom affiché, rôle applicatif (admin/membre), dernière connexion, household_id, household_role (admin/membre)
- households : nom, budget hebdo en centimes (10000 par défaut), magasin principal, magasin des fruits et légumes, créateur
- household_members : nom, date de naissance (birth_date, v0.10.0), catégorie (adulte/enfant/tout-petit, déduite de l'âge), coefficient de portion (décimal 0 à 2 ; enregistré = valeur du jour de la dernière sauvegarde), coefficient réglé à la main (coefficient_manual), compte lié optionnel (un compte = une fiche au plus), position
- equipment : liste commune des appareils (nom, slug unique, par défaut ou ajouté, créé par)
- household_equipment : appareils dont dispose le foyer
- household_invitations : foyer, empreinte SHA-256 du jeton (jamais le jeton en clair), créée par, expiration, utilisée le / par

Référentiel
- Unités (définies dans le code, App\Support\Units, pas en base) : g, kg, pincée (0,5 g), ml, cl, dl, L, c. à café (5 ml), c. à soupe (15 ml), verre (200 ml), pièce ; trois dimensions (masse → g, volume → ml, pièce) ; conversions entre dimensions via la densité et le poids d'une pièce de l'ingrédient
- ingredients : nom, slug (stable, sert d'URL), rayon, unité de base (g, ml ou pièce, verrouillée dès qu'un conditionnement existe), poids d'une pièce (g), densité (g/ml), mois de saison (null = toute l'année), produit frais, produit de base (listé « à vérifier »), créé par
- ingredient_packs : conditionnements achetables (libellé, quantité dans l'unité de base, vrac oui/non)
- aisles : 14 rayons avec ordre par défaut ; stores : 6 magasins (Leclerc Drive, Morin, Lidl, Carrefour, Hyper U, Grand Frais) ; households.main_store_id et produce_store_id ; ordre des rayons propre à chaque magasin : prévu avec la liste de courses (v0.8.0)
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
- meal_plan_entries (v0.7.0) : foyer, date, créneau (petit-dejeuner, dejeuner, gouter, diner, preparation), position, type (recette, restes, hors_maison, note), recette, plat d'origine des restes (source_entry_id, suppression en cascade), convives (null = semaine type), invités adultes/enfants, nombre de repas, parts en réglage libre, quantité de fournée, au congélateur, note, créé par. Pas de table « semaine » : une semaine = les dates du lundi au dimanche.
- households.meal_slots (créneaux affichés, null = déjeuner, dîner, à préparer) et households.usual_absences (semaine type : membres absents par créneau et jour 1-7)
- pantry_items (v0.9.0) : stock du foyer - ingrédient, quantité (unité de base, 3 décimales), date limite optionnelle (expires_on), lieu (placard | frigo | congelateur), remarque, origine (manuel | courses), liste d'origine (shopping_list_id), créé par. Les lots de même ingrédient, lieu et date limite sont fusionnés.

Courses
- shopping_lists (v0.8.0) : foyer, période (date_from, date_to), repas écartés (excluded_entry_ids), empreinte du planning (signature, détecte un planning modifié depuis), révision (incrémentée quand la liste change de forme, pour prévenir les autres téléphones), classée le (archived_at), stock mis à jour le (stock_applied_at), créée par. Une seule liste en cours par foyer.
- shopping_list_items : liste, ingrédient (null = ligne libre), libellé, rayon, origine (recette | manuel), section (achat | verifier | stock) + section_locked, stock utilisé (stock_base) et « ne pas utiliser le stock » (stock_ignored), magasin + store_locked, besoin cumulé (unité de base), conditionnements retenus (JSON : pack, nombre, prix), prix en caisse, part réellement utilisée (prorata), magasin le moins cher et son prix, recettes à l'origine (JSON), remarque, coché par / quand. Un recalcul garde cases cochées, magasin et section choisis à la main, et les ajouts manuels.
- receipts (v0.5.0) : ticket de caisse du foyer (magasin, date, total, texte lu, statut) ; shopping_list_id (v0.9.1) : liste de courses soldée par ce ticket (null = aucune ; suppression de la liste = détaché)


Fiches de recettes Paperless (v0.12.0)
- recipe_imports : household_id, paperless_document_id (unique par foyer), paperless_modified_at, title, raw_text (texte Paperless), parsed (JSON : recette lue + lignes d'ingrédients rapprochées), issues (JSON : réserves), status (a_relire / cree / ignoree), recipe_id (recette créée), auto_published
- recipe_aliases : normalized_label (unique), ingredient_id, hits (rapprochements appris à la relecture)
- households.paperless_recipe_tag : étiquette Paperless des fiches de recettes (défaut « recettes »)

## Algorithme de la liste de courses (v0.8.0, stock en v0.9.0)

1. Plats cuisinés de la période (type « recette », hors congélateur, hors repas écartés) ; les restes ne sont jamais recomptés.
2. Facteur par plat = RecipeServing (parts du repas × nombre de repas / rendement de la recette, ou quantité de fournée) ; ingrédients facultatifs ignorés.
3. Conversion dans l'unité de base de l'ingrédient (densité / poids de pièce si nécessaire) puis somme par ingrédient sur tous les plats. Unité non convertible : signalée (« Quantité à estimer »), jamais devinée.
4. Magasin : principal, ou magasin des fruits et légumes pour ce rayon ; à défaut de prix, le moins cher qui en a un.
5. Conditionnements (App\Support\PackPlanner) : combinaison la moins chère couvrant le besoin, à 2 % près (15 g/ml au plus, jamais sur des pièces) ; à égalité de prix, le moins de reste ; vrac au poids arrondi à 50 g (ou à la pièce).
6. Produits de base (is_staple) : section « À vérifier chez vous », hors budget ; l'eau et les produits de base sans conditionnement ne figurent pas.
7. Prix en caisse (paquets entiers), part réellement utilisée, surplus d'emballages, économie possible dans un autre magasin.
8. Stock (v0.9.0) : besoin net = besoin - stock disponible à la date de début (lots non périmés) ; besoin couvert → section « Déjà en stock », sans achat ni prix ; articles dont la section a été fixée à la main ou marqués « ne pas utiliser le stock » : stock ignoré.
9. Classement de la liste (App\Support\Pantry::applyList, une seule fois) : le stock utilisé sort du stock (plus proche de la date limite d'abord) ; pour chaque article d'achat coché, le surplus (acheté - besoin net) entre au stock s'il vaut la peine d'être gardé (pièce : 0,5 au moins ; sinon au moins 15 g/ml ou 2 % du besoin) ; article manuel relié à un ingrédient : toute la quantité entre.
10. Suggestions anti-gaspi (App\Support\AntiWaste) : produits de base ignorés ; quantités proratisées pour le foyer ; ligne couverte si le stock atteint 98 % du besoin ; recette retenue si au moins 25 % des lignes sont couvertes ou partiellement, ou si elle emploie un produit à consommer vite ; tri : produits à consommer vite utilisés, part couverte, coût à compléter.
Rapprochement avec le ticket (v0.9.1) : voir « Économies et bilan ».

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
6. Tickets de caisse : connexion Paperless, lecture, rapprochement, prix réels (v0.5.0, lecteur Lidl v0.5.1, textes Paperless réels Lidl et Leclerc Drive v0.5.2) - VALIDÉ le 2026-09-29
7. Affichage d'une recette proratisée (v0.6.0) - VALIDÉ le 2026-09-29
8. Planning de la semaine et repas cumulables (v0.7.0) - VALIDÉ le 2026-10-03
9. Liste de courses agrégée, par magasin et par rayon, cochable en temps réel (v0.8.0) - VALIDÉ le 2026-10-05
10. Économies : stock et anti-gaspi (v0.9.0) - VALIDÉ le 2026-10-05 ; rapprochement ticket ↔ liste, bilan, plat trop cher à remplacer, alerte de prix, reset (v0.9.1) - VALIDÉ le 2026-10-05
11. Âge des membres : date de naissance et coefficients automatiques (v0.10.0) - VALIDÉ le 2026-10-05
12. Refonte de l'interface : plus simple et ergonomique pour un nouvel utilisateur (v0.11.0) - VALIDÉ le 2026-10-05
13. Recettes scannées dans Paperless récupérées automatiquement (v0.12.0, cartes HelloFresh v0.12.1, fiches imprimées v0.12.2) - livrées le 2026-10-05, à valider ; le lecteur sera affiné avec les autres fiches de Louis
14. Menu de la semaine proposé automatiquement dans le budget (v0.13.0) - livré le 2026-10-05, à valider ; l'affinage du lecteur de fiches est mis de côté à la demande de Louis
15. v0.14.0 : tour de tests et corrections (lot 1 livré le 2026-10-05 : fiches à colonnes mélangées ; lot 2 = v0.15.0, lecture fiable des fiches par le service foodtruck-ocr, et accès aux ingrédients) - Louis teste tout (menu automatique, fiches Paperless, courses, stock, accueil) et signale ce qui ne va pas ; on corrige avant toute nouvelle fonctionnalité (décision du 2026-10-05, 16 h 45)
16. Bonus : import de recette par URL, sauvegardes automatiques (dump quotidien vers archive-nas), supervision (état de la synchro Paperless dans Prometheus/Talk), IA locale pour les libellés inconnus, équilibre nutritionnel hebdomadaire

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
- Format Lidl Plus (v0.5.1, validé sur 5 vrais tickets) : colonnes « P.U. Qté Total », codes « A T » / « B » après le total, pesées sur la ligne suivante, remises « Réduction Lidl Plus », « Prix en baisse », « Rabais 25 % », « Rem LPM » rattachées à l'article, « A payer », tableau de TVA (A 5,5 %, B 20 %), date « 24.09.26 » (les codes « 522183/04/11/02 » sont écartés).
- TVA 20 % (v0.5.1) : ligne ignorée d'office (entretien, hygiène, alcool), sauf libellé déjà associé.
- Textes Paperless réels (v0.5.2) : les tickets Lidl Plus sont des images, Paperless les lit par OCR. Erreurs corrigées : « 1,/9 » et « 1,7/9 » → 1,79, « @,71 » → 0,71, codes collés « 5,99BT », quantité « 7 », « 71 », « 171 » ou « | » pour 1, prix unitaire ou total faux d'un chiffre (corrigé par la quantité), remise impossible (« -60,36 » sur un article à 1,56 → -0,36), « EUR/Kkg ». Une ligne corrigée porte la remarque « lecture corrigée » sauf si la somme des lignes retombe exactement sur « A payer » (correction confirmée). Une seule ligne illisible sur un ticket qui annonce son nombre de lignes : son montant est déduit du total (« montant déduit »). Plusieurs lignes illisibles : listées sur la page du ticket. Pesée illisible : montant conservé, aucun prix enregistré. Validé sur les tickets Lidl du 02/10/2025 et du 24/09/2026 : somme exacte.
- Leclerc Drive (v0.5.2) : bon de commande PDF (texte propre) lu par un lecteur dédié (LeclercDriveParser) : « désignation quantité prix unitaire total », rubriques (non alimentaire : hygiène, entretien, alcool… ignoré d'office), total = total de la commande moins les économies (l'avoir est un moyen de paiement), économies de lot réparties sur les produits nommés, anti-gaspi (prix déjà remisé : prix d'origine reconstitué, marqué promo), date de la commande. Validé sur la commande du 28/09/2026 (34 lignes, 89,77 €).
- Statut (v0.5.2) : un ticket reste « à valider » tant qu'une ligne reste à associer, y compris quand le nom tapé n'existe pas. Bouton « Relire » disponible aussi sur un ticket traité ; les prix d'un même jour ne sont jamais dupliqués.
- Lecture (App\Support\Receipts\ReceiptParser) : règles génériques des tickets français (prix en fin de ligne + code TVA, « 2 x 1,05 », « 0,856 kg x 2,49 €/kg », remises rattachées à l'article précédent, TOTAL / NET A PAYER, date, erreurs O/0). Contrôle : somme des lignes lues comparée au total du ticket. Règles par enseigne à ajuster avec de vrais tickets (v0.5.x).
- Rapprochement (ReceiptMatcher) : libellé mémorisé pour ce magasin → reconnu ; mémorisé ailleurs ou ressemblance avec un ingrédient (abréviations des tickets) → proposé ; sinon à associer. Choix du conditionnement d'après le libellé (« 1L », « 6X1L », « X12 », vrac pour une pesée).
- Application (ReceiptProcessor) : prix du conditionnement déduit (pesée → prix au kilo ramené au conditionnement ; « 6X1L » associé à la bouteille → prix d'une bouteille ; remise → promo), origine « ticket », daté du jour d'achat, pour le magasin du ticket. Libellés mémorisés (receipt_aliases, par magasin), y compris « toujours ignorer » (sac, non alimentaire). Un ticket dont toutes les lignes sont connues est traité automatiquement. Un ingrédient choisi sans conditionnement : conditionnement déduit du ticket, créé si la quantité lue n'existe pas (« 50 cl (ticket) »).
- Saisie manuelle : coller le texte d'un ticket (hors Paperless).
- Produits hors recettes (café, yaourts du commerce, camembert…) : « Toujours ignorer » une fois, ou ajout de l'ingrédient au référentiel s'il doit servir aux recettes.
- Créer l'ingrédient depuis une ligne (v0.5.2) : action « Créer l'ingrédient (nom saisi) » ; unité déduite du libellé (500g → g, 3x20cl → ml, sinon pièce), rayon choisi (proposé d'après la rubrique Leclerc Drive), conditionnement créé d'après le ticket, prix enregistré, libellé mémorisé. La fiche se complète ensuite dans Ingrédients (saison, poids d'une pièce…).
- Rapprochement (v0.5.1) : noms à variantes (« Prune, quetsche », « Pâtes (spaghetti, penne…) »), priorité au plus grand nombre de mots reconnus, pâté ≠ pâtes, apostrophes ; quand la quantité du ticket n'existe pas dans le référentiel (« Oeufs x30 »), l'ingrédient est proposé et le conditionnement créé à la validation.
- Rapprochement (v0.5.2) : produits transformés jamais pris pour leur ingrédient (compote, jus, biscuit, croûtons, sauce, dessert, menu, assaisonné, pâte brisée…) ; « poire » ≠ « poireau » ; article à l'unité sans quantité écrite (« Poivron doux rouge - 1p », « Brocoli ») jamais associé au vrac au kilo : conditionnement « Pièce (ticket) » créé (poids d'une pièce de l'ingrédient).
- Jeton Paperless (v0.5.2) : champ texte masqué (plus un champ mot de passe, que les gestionnaires de mots de passe remplissaient), « Token » et espaces retirés, forme contrôlée, fin du jeton enregistré affichée. Messages d'erreur précis : jeton refusé (401), droit manquant sur les étiquettes, documents ou correspondants (403), redirection vers une page de connexion.
- Pas d'IA locale (VM docker trop juste en RAM, CPU du ML150 sur-souscrit, Quadro P620 trop petite) ; à réévaluer plus tard pour suggérer les libellés inconnus.
- Plus tard : rapprochement avec la liste de courses (v0.8.0).

- v0.4.0 validée par Louis le 2026-09-29.
- v0.5.0 : tickets de caisse livrés - réglages Paperless (Mon foyer), page Tickets (synchronisation, filtres, saisie manuelle), écran de rapprochement, prix « ticket », libellés mémorisés, traitement automatique, conteneur foodtruck-scheduler, 63 tests. Lecteur générique, en attente de vrais tickets pour l'ajuster.

- v0.5.1 : lecteur ajusté sur 5 vrais tickets Lidl Plus (les sommes des lignes retombent exactement sur « A payer »), TVA 20 % ignorée d'office, rapprochement amélioré, commande foodtruck:reparse, 69 tests.
- v0.5.0 et v0.5.1 installées et poussées sur GitHub le 2026-09-29. Paperless relié (compte foodtruck, workflow, étiquette « courses alimentaires » n° 32) : 7 tickets synchronisés (5 Lidl, 1 E.Leclerc). Constat : texte Paperless des tickets Lidl = OCR bruité (ticket du 02/10/2025 : 6 articles lus sur 32).
- v0.5.2 : lecteur tolérant à l'OCR Lidl et lecteur Leclerc Drive, validés sur les textes Paperless réels (tests/Fixtures/paperless) ; création d'ingrédient depuis un ticket ; ticket à valider tant qu'une ligne reste à associer ; relecture des tickets traités sans doublon de prix ; champ jeton corrigé et erreurs Paperless précises ; 79 tests.
- v0.5.2 installée et validée par Louis le 2026-09-29 : étape tickets de caisse validée.
- v0.6.0 : recette proratisée - bloc « Pour combien ? » sur la fiche (qui mange, invités, nombre de repas, réglage libre ; fournée pour pots/pièces/grammes), quantités et équivalences recalculées avec arrondi pratique, coût du repas et par part, économie « fait maison » proratisée, rappel que les quantités des étapes sont celles d'origine, conseil de cuisson au-delà de ×2, lien partageable (réglages dans l'adresse) ; calcul réutilisable par le planning (App\Support\RecipeServing) ; 87 tests.
- v0.6.0 validée par Louis le 2026-09-29 (feu vert pour la v0.7.0).
- v0.7.0 : planning livré - grille de la semaine (déjeuner, dîner, à préparer ; petit-déjeuner et goûter en option), semaine type dans Mon foyer, ajout d'un plat / hors maison / note, convives pré-cochés d'après la semaine type, restes automatiques qui évitent les repas où personne n'est à la maison, congélateur, coût de la semaine et jauge de budget, ajout depuis la fiche recette, repas du jour sur l'accueil, entrée « Planning » ; 98 tests.
- v0.7.0 installée et validée par Louis le 2026-10-03 (« c'est parfait on continue »).
- v0.8.0 : liste de courses livrée - création pour une période choisie, quantités cumulées de tous les plats, conditionnements entiers au meilleur prix (60 cl de lait → 1 bouteille de 1 L, il en restera 40 cl), rangement par magasin (principal + fruits et légumes) puis par rayon, prix en caisse et jauge de budget au prorata de la période, surplus d'emballages et économie possible chiffrés, cases partagées en direct, produits de base « à vérifier chez vous », ajouts libres, repas écartables, détection d'un planning modifié, historique des listes, entrée « Courses » et carte sur l'accueil ; 118 tests.
- v0.8.0 installée et validée par Louis le 2026-10-05 (« tout est ok on peux passer a la v0.9.0 »).
- v0.9.0 : stock et anti-gaspi livrés - page Stock (ajout, modification, suppression, lieux, dates limites, « À consommer vite »), déduction du stock dans la liste de courses (« Déjà en stock », « ne pas utiliser le stock »), stock mis à jour en fin de courses (surplus d'emballages entrés, stock utilisé sorti), « Que cuisiner ? » (recettes selon le stock), bandeau « À consommer vite » dans le Planning, entrée « Stock » et carte sur l'accueil ; 130 tests.
- v0.9.0 installée et validée par Louis le 2026-10-05 (« Ca a l'air ok, on fera un point complet a la fin »). Louis a demandé un reset complet des données d'essai : ajouté en v0.9.1.
- v0.9.1 : rapprochement ticket ↔ liste (lien automatique, cases cochées, comparaison payé / estimé), page Bilan (ouverte à « Courses terminées »), alertes de prix qui montent et estimations recalées, plats trop chers à remplacer dans le planning, commande ./ft reset, carte « Économies » sur l'accueil ; 150 tests.
- v0.10.0 : âge des membres livré - date de naissance dans Mon foyer et dans l'assistant, coefficient automatique selon la grille (calculé à la date de chaque repas pour le planning, les listes de courses et les coûts), réglage manuel, âge et note affichés ; 158 tests.
- v0.9.1 et v0.10.0 validées par Louis le 2026-10-05 (« v0.9.1 et v0.10.0 sont ok, on continue ! »).
- v0.11.0 : refonte de l'interface livrée - nouvelle identité visuelle, 5 onglets et barre du bas sur mobile, accueil guidé en 4 étapes, pages Plus et Aide, formulaires simplifiés (ajout d'un repas, recettes, courses, foyer), états vides explicatifs ; aucune migration ; 162 tests.
- v0.11.0 validée par Louis le 2026-10-05 (« tout a l'air ok pour la v0.11.0, on peut attaquer la v0.12.0 »). v0.12.0 en attente de 2 à 3 fiches scannées (texte Paperless) pour régler le lecteur.
- v0.12.0 livrée : lecteur de fiches Paperless (premier exemple : Gratin de courge butternut, Julie Andrieu), création automatique des recettes, relecture, apprentissage des rapprochements ; migration 2026_10_05_960001 ; 183 tests. En attente de validation et de nouvelles fiches (formats différents).
- v0.12.2 livrée : fiches imprimées Leclerc (puces mal lues, quantité démesurée signalée, étape finale isolée, source depuis le pied de page) ; aucune migration ; 203 tests ; en attente de validation.
- v0.12.1 livrée : lecteur dédié aux cartes de kits repas HelloFresh (exemple : Curry thaï léger aux crevettes & coco), fractions perdues réparées et signalées, correctif .gitignore (src/database/data/ non versionné jusque-là) ; aucune migration ; 193 tests. En attente de validation (application sur la VM, push GitHub avec le tag v0.12.1, essai sur le vrai document) et de nouvelles fiches.
- Demandes de Louis du 2026-10-05 : âges (livré en v0.10.0), refonte de l'interface (livrée en v0.11.0), recettes scannées via Paperless (v0.12.0). La v0.13.0 (menu automatique) est livrée.

## Questions ouvertes

- Autres enseignes (Morin, Carrefour, Hyper U, Grand Frais) : lecteur à ajuster dès réception de tickets réels (texte Paperless).
- Ordre des rayons propre à chaque magasin (parcours en magasin) : à placer quand Louis le demandera.
- Point complet à faire avec Louis quand toutes les étapes seront terminées (demande du 2026-10-05).
- Stock : à l'usage, dire si la sortie du stock à la fin des courses suffit ou si « cuisiné » doit aussi décompter, et si des durées de conservation par défaut seraient utiles.
- Cartes HelloFresh : sachets et paquets comptés en pièces ; à décider avec Louis : (1) donner un poids à chaque sachet ou paquet par ingrédient (lu sur les emballages), ou (2) garder la quantité telle quelle, marquée « à estimer ». (3) Lecture avec coordonnées (Tesseract dans l'image PHP, PDF téléchargé depuis Paperless) : option mise de côté, à rouvrir seulement si les cartes à colonnes deviennent trop nombreuses.
- Liste de courses : à l'usage, dire si la synchronisation toutes les 6 secondes suffit, si le partage doit aussi passer par un message (copier la liste) et quels magasins sont réellement fréquentés chaque semaine.

## Pour reprendre dans une nouvelle conversation (état au 2026-10-06, après la livraison de la v0.15.4)

État
- v0.12.0 (recettes scannées dans Paperless) : livrée et poussée sur GitHub (tag v0.12.0).
- v0.12.1 (cartes de kits repas HelloFresh) : appliquée sur la VM par Louis le 2026-10-05 ; le commit Git (git add -A, tag v0.12.1, push) est à sa charge (le dépôt GitHub est en lecture seule pour Claude).
- v0.12.2 (fiches imprimées Leclerc) : livrée, script foodtruck-update-v0.12.2.sh fourni, EN ATTENTE de validation de Louis (application sur la VM, commit, tag v0.12.2, push, essai avec le vrai document Paperless n° 484). 203 tests automatisés.
- v0.11.0 et antérieures : validées.
- v0.13.2 (supprimer une fiche Paperless l'efface complètement, elle est retraitée à la recherche suivante) : livrée, script foodtruck-update-v0.13.2.sh fourni (exige la v0.13.1 en place, une migration de nettoyage), en attente de validation de Louis. 226 tests automatisés.
- v0.13.1 (boutons Supprimer / Tout supprimer, fiches mises de côté) : appliquée par Louis le 2026-10-05, remplacée par la v0.13.2.
- v0.13.0 (menu automatique) : livrée, script foodtruck-update-v0.13.0.sh fourni, EN ATTENTE de validation de Louis (exige la v0.12.2 en place ; une migration). 222 tests automatisés. Louis a demandé de mettre de côté les fiches de recettes pour avancer sur le reste du projet.
- v0.15.4 (lecture des scans d'après le fichier original de Paperless et non plus la version archivée, dont les images recompressées collaient les mots) : livrée le 2026-10-06, script foodtruck-update-v0.15.4.sh fourni (s'installe sur la v0.15.2 ou la v0.15.3, aucune migration, relit les fiches à relire), en attente de validation. 248 tests automatisés.
- v0.15.3 (bloc « L'essentiel » de la fiche recette en grille alignée de 4 colonnes, 2 sur téléphone) : livrée le 2026-10-06, script foodtruck-update-v0.15.3.sh fourni (exige la v0.15.2 en place, aucune migration), en attente de validation. 248 tests automatisés.
- v0.15.2 (écran de relecture compact ; couleurs et petites fautes dans le rapprochement ; lecture plus précise : modèle « best », canal le plus sombre, seconde lecture ; Ratatouille Leclerc) : appliquée sur la VM par Louis le 2026-10-06 (248 tests passés, foodtruck-ocr en bonne santé, 3 fiches relues d'après le scan), commit et tag v0.15.2 poussés sur GitHub ; reste la validation à l'usage (écran de relecture, fiches Ratatouille, Croziflette, Orzo, Curry). 248 tests automatisés.
- v0.15.1 (colonnes étroites des cartes HelloFresh : découpage tolérant, tableaux protégés ; curry thaï n° 481) : livrée, script foodtruck-update-v0.15.1.sh fourni (exige la v0.15.0 en place, reconstruit l'image foodtruck-ocr), en attente de validation. 244 tests automatisés.
- v0.15.0 (lecture fiable des fiches : conteneur foodtruck-ocr, lecture du scan avec la position des mots, LayoutComposer ; tuile Ingrédients) : livrée, script foodtruck-update-v0.15.0.sh fourni (exige la v0.14.0 en place ; construit l'image foodtruck-ocr ; une migration), en attente de validation. 242 tests automatisés. Fiches à vérifier après installation : Croziflette n° 487 et Orzo n° 483.
- v0.14.0 (lot 1 du tour de corrections : fiches Paperless à colonnes mélangées, Croziflette n° 487) : livrée, script foodtruck-update-v0.14.0.sh fourni (exige la v0.13.2 en place), en attente de validation. 232 tests automatisés.
- Prochaine étape : suite du tour de tests et corrections en v0.14.x (Louis teste et signale, Claude corrige) ; ensuite une étape bonus au choix de Louis. Idées en réserve : import de recette par URL, sauvegardes automatiques vers archive-nas, supervision, équilibre nutritionnel, ordre des rayons par magasin, lecteurs de tickets d'autres enseignes.
- Louis fera un point complet sur l'ensemble une fois tout terminé.

Ce que Louis doit encore fournir
- (Mis de côté le 2026-10-05, à reprendre quand Louis le demandera) D'autres fiches scannées, UNE PAR UNE, dans des formats différents (texte du document Paperless, plus le PDF et une capture si possible). Chacune devient un test automatique et affine le lecteur. Traitées : Gratin de courge butternut (Julie Andrieu, document n° 480, fixture julie-andrieu-gratin-courge.txt) ; Curry thaï léger aux crevettes & coco (HelloFresh, document n° 481, fixture hellofresh-curry-thai-crevettes.txt) ; Pâtes carbonara (Leclerc, document n° 484, fixture leclerc-pates-carbonara.txt) ; Croziflette (Leclerc, document n° 487, colonnes mélangées, fixture leclerc-croziflette.txt) ; Orzo aux crevettes (HelloFresh, document n° 483, fixture de mise en page hellofresh-orzo.json) ; Curry thaï (HelloFresh, document n° 481, scan relu en v0.15.1, fixture hellofresh-curry-thai.json) ; Ratatouille (Leclerc, scan relu en v0.15.2, fixture leclerc-ratatouille.json). Pour un nouveau format, le plus utile est désormais le PDF (il est relu par le service) en plus du texte de Paperless.
- Réponse sur les sachets et paquets des cartes HelloFresh (voir Questions ouvertes).

Où est le code (dépôt git@github.com:tobilianok/foodtruck.git, branche main, public depuis le 2026-10-05)
- Lecteur : src/app/Support/RecipeScan/ : TextCleaner (nettoyage), IngredientLineParser (lignes d'ingrédients), StepExtractor (5 mises en page d'étapes), MealKitSheetParser (cartes HelloFresh, v0.12.1 ; les règles de v0.12.2 sont dans les autres classes), RecipeTextParser (assemblage, aiguille vers MealKitSheetParser, remet en ordre les colonnes mélangées avec ColumnSplitter) ; depuis v0.15.0 : OcrClient (service foodtruck-ocr), RecipeLayoutRunner (lecture en arrière-plan), LayoutComposer (blocs du scan → texte ordonné) ; service Python dans docker/ocr (server.py, layout.py) ; fixtures des réponses du service dans tests/Fixtures/layout/, IngredientMatcher (rapprochement, table SYNONYMS à enrichir), ScanImporter (analyse, création, apprentissage ; la clé « check » d'une ligne devient « problem »), RecipeScanSync (synchronisation Paperless).
- Écrans et contrôleur : RecipeImportController, vues resources/views/recipes/imports.blade.php et _import-row.blade.php, relecture dans recipes/form.blade.php (variable $import). Commandes dans routes/console.php (foodtruck:recettes, foodtruck:relire-recettes).
- Menu automatique (v0.13.0) : src/app/Support/MenuScorer.php (notes, constantes de poids en tête de classe), MenuGenerator.php (semaine, restes, garder, autre idée, valider, effacer), app/Http/Controllers/MenuController.php, bloc dans resources/views/planning/index.blade.php et _entry.blade.php ; tests : tests/Unit/MenuScorerTest.php (sans base de données) et tests/Feature/MenuAutomatiqueTest.php.
- Tests : tests/Feature/RecipeScanParserTest.php, RecipeScanFlowTest.php, tests/Unit/MealKitSheetParserTest.php et PrintedSheetParserTest.php (sans base de données, exécutable avec PHP 8.3 : vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Unit/MealKitSheetParserTest.php). Pour un nouveau format : ajouter la fixture .txt, puis un test de lecture (titre, ingrédients, étapes) et corriger le lecteur sans casser les tests existants.
- Dans le bac à sable de Claude (PHP 8.3), Laravel 13 ne démarre pas (Symfony 8 exige PHP 8.4) : seuls les tests unitaires du lecteur s'y exécutent ; les tests de flux tournent sur la VM via le script de mise à jour (retour arrière en cas d'échec).

Façon de travailler (à respecter)
- Une étape à la fois, chacune validée par Louis avant la suivante ; réponses en français.
- Chaque version : version incrémentée (src/VERSION), docs du projet mises à jour (CADRAGE, DEPLOIEMENT, CHANGELOG, aussi dans le Projet Claude), script de mise à jour ~/foodtruck-update-vX.Y.Z.sh à coller sur la VM, puis commandes Git (commit, tag, push).
- Contrainte impérative : tout reste dans /opt/stacks/foodtruck, vérifications en lecture seule avant tout changement, aucun port publié sur l'hôte, noms préfixés foodtruck, arrêt du script au moindre conflit.
- Le script de mise à jour de la v0.12.1 applique un correctif git vérifié (git apply) au lieu d'embarquer tous les fichiers ; il vérifie la version en place, sauvegarde la base, lance les tests (retour arrière par git apply -R si échec). Dans une nouvelle conversation, rattacher le dépôt tobilianok/foodtruck à la session (add_repo) pour disposer du code (il est public en lecture anonyme ; il n'est pas possible d'y pousser depuis la session de Claude : Louis pousse depuis la VM).
