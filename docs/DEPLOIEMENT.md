# Déploiement - Foodtruck

Dernière mise à jour : 2026-09-28 (v0.1.0)

## Infrastructure constatée (reconnaissance du 2026-09-28)

- VM "docker" : Ubuntu 26.04.1 LTS, noyau 7.0, IP 192.168.1.14, utilisateur tobilianok (groupes sudo et docker), fuseau hôte UTC (les conteneurs Foodtruck sont en Europe/Paris), 7,2 Gio de RAM (swap déjà utilisée à 50 %), 106 Go libres sur /.
- Docker 29.8.1, Compose v5.5.1, 18 conteneurs existants.
- Stacks existants dans /opt/stacks (NE PAS TOUCHER) : collabora, forge (sonarr, radarr, prowlarr, deluge, seerr, flaresolverr), musique (picard, airsonic), npm, paperless, paperless-watch, vaultwarden, wud.
- Nginx Proxy Manager : conteneur nginx-proxy-manager sur le réseau npm_default ; ports hôte 18080 (HTTP), 18443 (HTTPS), 81 (admin).
- Authentik : hors de cette VM, https://auth.louisrousseaux.fr (IP publique 82.67.131.76), joignable depuis la VM.
- Réseau résiduel tobilianok_default présent (sans conteneur) : ignoré, non modifié.
- Git 2.53 ; clé SSH ~/.ssh/id_ed25519 présente.

## Règles de cohabitation

- Tout ce qui concerne Foodtruck vit dans /opt/stacks/foodtruck.
- Aucun port publié sur l'hôte. Seul foodtruck-web rejoint npm_default ; la base reste sur foodtruck_internal.
- Noms de conteneurs utilisés comme noms d'hôte (foodtruck-app, foodtruck-db, foodtruck-web) pour éviter toute ambiguïté DNS avec les services "app"/"db" d'autres stacks.
- Chaque script vérifie l'état avant d'agir et s'arrête au moindre conflit.
- Configuration Git limitée au dépôt (pas de git config --global).

## Architecture

- foodtruck-app : PHP 8.4 FPM Alpine (image locale foodtruck-app:local), workers avec l'UID/GID de tobilianok, code monté depuis ./src.
- foodtruck-web : nginx:stable-alpine, sert ./src/public, relaie le PHP vers foodtruck-app:9000.
- foodtruck-db : mariadb:11.4, données dans ./data/mariadb (hors Git), buffer InnoDB limité à 128 Mo.

## Arborescence

    /opt/stacks/foodtruck
    ├── compose.yaml
    ├── .env               secrets de la stack (600, hors Git)
    ├── .env.example
    ├── ft                 raccourci : ./ft php artisan ..., ./ft composer ...
    ├── docker/php         Dockerfile + php.ini
    ├── docker/nginx       default.conf
    ├── data/mariadb       données (hors Git)
    ├── docs               CADRAGE, DEPLOIEMENT, CHANGELOG
    └── src                application Laravel (src/.env hors Git)

## Paramétrage externe

DNS : enregistrement foodtruck.louisrousseaux.fr → même cible que auth.louisrousseaux.fr (A 82.67.131.76).

Authentik (application + fournisseur OAuth2/OpenID) :
- Application : nom Foodtruck, slug foodtruck, URL de lancement https://foodtruck.louisrousseaux.fr
- Fournisseur : type OAuth2/OpenID, client confidentiel, flux d'autorisation default-provider-authorization-implicit-consent, flux d'invalidation default-provider-invalidation-flow
- URI de redirection (stricte) : https://foodtruck.louisrousseaux.fr/auth/callback
- Clé de signature : authentik Self-signed Certificate ; scopes openid, email, profile
- Émetteur : https://auth.louisrousseaux.fr/application/o/foodtruck/
- Accès : liaison au groupe "foodtruck" (membres de la famille)

Nginx Proxy Manager (hôte proxy) :
- Domaine foodtruck.louisrousseaux.fr → http://foodtruck-web:80, Block Common Exploits
- SSL : certificat Let's Encrypt, Force SSL, HTTP/2

## Commandes utiles

    cd /opt/stacks/foodtruck
    docker compose ps                       état des conteneurs
    docker compose logs -f --tail=100       journaux
    ./ft php artisan foodtruck:check        contrôle du socle
    ./ft php artisan migrate --force        migrations
    ./ft php artisan config:clear           après modification de src/.env
    docker compose restart                  redémarrage

Journal Laravel : src/storage/logs/laravel-AAAA-MM-JJ.log

## Livraisons

Chaque version est livrée sous forme de script ~/foodtruck-install-vX.Y.Z.sh (ou ~/foodtruck-update-vX.Y.Z.sh), à coller puis exécuter sur la VM, suivi des commandes Git (commit, tag vX.Y.Z, push vers git@github.com:tobilianok/foodtruck.git).

## Sauvegardes

À mettre en place (étape bonus) : dump MariaDB quotidien + photos des recettes.
