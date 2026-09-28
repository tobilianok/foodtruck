# Foodtruck

Application familiale auto-hébergée : recettes, menus de la semaine et liste de courses mutualisée, avec un objectif fort d'économies et de fait maison.

- Accès : https://foodtruck.louisrousseaux.fr (connexion via Authentik)
- Stack : Laravel 13, PHP 8.4, MariaDB 11.4, Nginx, Docker Compose
- Version : voir src/VERSION et docs/CHANGELOG.md

## Documentation

- docs/CADRAGE.md : décisions, modèle de données, feuille de route
- docs/DEPLOIEMENT.md : infrastructure, installation, commandes utiles
- docs/CHANGELOG.md : journal des versions

## Au quotidien

    cd /opt/stacks/foodtruck
    docker compose ps
    ./ft php artisan foodtruck:check
