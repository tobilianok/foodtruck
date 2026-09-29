# Journal des versions - Foodtruck

## v0.5.1 - 2026-09-29 - Lecteur ajusté aux tickets Lidl

- Format Lidl Plus : colonnes prix unitaire / quantité / total, codes « A T » et « B », pesées, remises Lidl Plus, « Prix en baisse », « Rabais », « Rem LPM », tableau de TVA, date du ticket (codes numériques écartés). Validé sur 5 vrais tickets : la somme des lignes retombe exactement sur « A payer ».
- Lignes à TVA 20 % (entretien, hygiène, alcool) ignorées d'office, avec un badge « TVA 20 % ».
- Rapprochement : variantes des noms d'ingrédients, priorité au plus grand nombre de mots reconnus, pâté ≠ pâtes, apostrophes (« Huile d'olive »), nouvelles abréviations (butternut, viande hachée).
- Quantité absente du référentiel (« Oeufs x30 ») : ingrédient proposé, conditionnement « 30 pièces (ticket) » créé à la validation.
- Nouvelle commande foodtruck:reparse : relit les tickets à valider avec les règles à jour (lancée par le script de mise à jour).
- 69 tests automatisés.

## v0.5.0 - 2026-09-29 - Tickets de caisse et Paperless

- Connexion à Paperless-ngx par foyer (Mon foyer, admins) : adresse, jeton d'API chiffré, étiquette ; test de connexion à l'enregistrement.
- Synchronisation des documents étiquetés « courses alimentaires » : toutes les heures (nouveau conteneur foodtruck-scheduler) et à la demande.
- Lecture des tickets : articles, quantités, pesées au kilo, remises, total, date ; contrôle de l'écart avec le total du ticket.
- Rapprochement avec le référentiel : libellés mémorisés par magasin (reconnu), propositions par ressemblance (proposé), choix manuel avec autocomplétion ; « toujours ignorer » pour le non alimentaire.
- Prix réels : origine « ticket », datés du jour d'achat, promo si remise ; conditionnement créé depuis le ticket si besoin. Tickets entièrement reconnus traités automatiquement.
- Saisie manuelle d'un ticket (copier-coller), relecture, ignorer un ticket.
- Navigation : entrée « Tickets » ; accueil : prix réels relevés et tickets à valider.
- Feuille de route décalée : recette proratisée en v0.6.0.
- 63 tests automatisés.

## v0.4.0 - 2026-09-29 - Recettes

- Recettes publiques : liste en cartes avec filtres (recherche, catégorie, étiquette, de saison, 30 min max, faisable avec mes appareils, favoris, brouillons).
- Fiche recette : ingrédients groupés (« Pour la pâte »), équivalences (« 3 pièces ≈ 600 g »), étapes numérotées avec minuteur et appareil, coût estimé total et par personne/pot/pièce/100 g, comparaison avec l'équivalent industriel, appareils manquants au foyer, étiquettes calculées « de saison » et « économique ».
- Saisie : lignes d'ingrédients et d'étapes dynamiques, autocomplétion sur le référentiel, contrôle des unités convertibles, étiquettes, appareils, photo (redimensionnée 1600 px + vignette, WebP), brouillon.
- Droits : modification et suppression par l'auteur ou un admin ; les autres dupliquent (brouillon rattaché à l'original). Favoris par compte.
- Premier lot : 24 recettes (plats d'automne, soupes, curry, gratins, quiche, yaourts nature et vanille, pâte brisée, barres de céréales, cookies, gâteau au yaourt, compote, granola, crêpes) ; commande foodtruck:recipes, idempotente.
- Ingrédient « Eau » ajouté au référentiel (produit de base, gratuit).
- 50 tests automatisés.

## v0.3.0 - 2026-09-29 - Ingrédients et prix

- Référentiel : 14 rayons, 6 magasins, ingrédients avec unité de base (g, ml, pièce), poids d'une pièce, densité, mois de saison, produit frais, produit de base.
- Unités et conversions (g, kg, pincée, ml, cl, dl, L, c. à café, c. à soupe, verre, pièce), y compris entre poids, volume et pièces.
- Conditionnements (bouteille 1 L, vrac au kg, boîte de 6…) et prix par magasin avec historique, promo et origine (estimé, saisi, ticket).
- Écrans : liste des ingrédients (recherche, rayon, « de saison », meilleur prix au kg/L/pièce), fiche ingrédient, création, mise à jour rapide des prix par magasin.
- Magasins du foyer : principal (Leclerc Drive) et fruits et légumes (Morin), réglables dans Mon foyer et dans l'assistant.
- Jeu de départ : 138 ingrédients, 153 conditionnements, 185 prix estimés (commande foodtruck:reference, idempotente).
- Navigation : Accueil, Ingrédients, Prix, Mon foyer ; tri alphabétique sans tenir compte des accents ; affichage mobile amélioré.
- 41 tests automatisés.

## v0.2.0 - 2026-09-29 - Mon foyer

- Assistant de première connexion : nom du foyer, budget hebdomadaire, membres avec catégorie et coefficient de portion (adulte 1, enfant 0,6, tout-petit 0), « c'est moi », appareils de cuisine.
- Page « Mon foyer » : réglages, membres (ajout, modification, retrait, compte lié), appareils (14 par défaut + ajout libre), comptes (nommer/retirer admin, retirer du foyer, quitter), invitations.
- Invitations : lien à usage unique valable 7 jours, créé par un admin du foyer ; accessible avant connexion ; choix de sa fiche membre en rejoignant ; jeton stocké uniquement sous forme d'empreinte.
- Droits : seuls les admins du foyer modifient ; protection du dernier admin.
- Navigation Accueil / Mon foyer, messages de confirmation et d'erreur, messages de validation en français.
- 23 tests automatisés (connexion Authentik simulée, assistant, foyer, invitations), lancés par le script de mise à jour avant toute migration.
- Sauvegarde automatique de la base avant migration (dossier backups/, hors Git).

## v0.1.1 - 2026-09-28 - Correctif comptes

- Correctif : l'e-mail n'est plus unique dans la table users. Un compte est identifié par son identifiant Authentik ; deux comptes Authentik partageant une adresse (ex. akadmin et un compte personnel) peuvent se connecter.
- Nouvelles commandes : foodtruck:users (liste), foodtruck:role <identifiant> <admin|membre>, foodtruck:remove-user <identifiant>. Protection contre la suppression ou la rétrogradation du dernier admin.

## v0.1.0 - 2026-09-28 - Socle

- Stack Docker "foodtruck" dans /opt/stacks/foodtruck : foodtruck-app (PHP 8.4 FPM), foodtruck-web (Nginx), foodtruck-db (MariaDB 11.4).
- Aucun port publié : accès via Nginx Proxy Manager (réseau externe npm_default), hôte cible foodtruck-web:80.
- Laravel 13 installé dans src/, configuré en français, sessions et cache en base.
- Connexion via Authentik en OIDC (code + PKCE), comptes créés à la première connexion, premier compte admin.
- Page d'accueil avec les modules à venir, déconnexion Authentik.
- Commande de contrôle : ./ft php artisan foodtruck:check
- Raccourci ./ft pour lancer artisan et composer dans le conteneur.
- Documentation : docs/CADRAGE.md, docs/DEPLOIEMENT.md, docs/CHANGELOG.md.

## v0.0.2 - 2026-09-28 - Cadrage validé

- Réponses intégrées : fait maison, équipements, multi-foyers, tickets de caisse, Lidl.
- Script de reconnaissance de la VM (lecture seule).

## v0.0.1 - 2026-09-28 - Cadrage

- Première version du cadrage et du modèle de données.
