# Journal des versions - Foodtruck

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
