# Journal des versions - Foodtruck

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
