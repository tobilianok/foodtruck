# Déploiement - Foodtruck

Dernière mise à jour : 2026-10-05 (v0.11.0)

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
- foodtruck-scheduler (v0.5.0) : même image que foodtruck-app, lance « php artisan schedule:work » (synchronisation Paperless toutes les heures).
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
    ├── backups            sauvegardes SQL avant migration (hors Git)
    ├── docs               CADRAGE, DEPLOIEMENT, CHANGELOG
    └── src                application Laravel (src/.env hors Git)

## Paramétrage externe

DNS : enregistrement foodtruck.louisrousseaux.fr → même cible que auth.louisrousseaux.fr (A 82.67.131.76).

Authentik (application + fournisseur OAuth2/OpenID) :
- Application : nom Foodtruck, slug foodtruck, URL de lancement https://foodtruck.louisrousseaux.fr
- Fournisseur : type OAuth2/OpenID, client confidentiel, flux d'autorisation default-provider-authorization-implicit-consent, flux d'invalidation default-provider-invalidation-flow
- URI de redirection (stricte) : https://foodtruck.louisrousseaux.fr/auth/callback
- Clé de signature : authentik Self-signed Certificate ; scopes openid, email, profile
- Grant types : Authorization Code et Refresh token uniquement
- Émetteur : https://auth.louisrousseaux.fr/application/o/foodtruck/
- Accès : groupe "foodtruck" lié à l'application (seuls ses membres peuvent se connecter). Ajouter chaque nouveau compte familial à ce groupe.
- Comptes : akadmin réservé à l'administration d'Authentik ; compte personnel de Louis : Tobilianok (admin Foodtruck depuis v0.1.1)

Nginx Proxy Manager (hôte proxy) :
- Domaine foodtruck.louisrousseaux.fr → http://foodtruck-web:80, Block Common Exploits, Websockets activé
- Attention : saisir le nom foodtruck-web, pas l'IP 192.168.1.14 (aucun port n'est publié sur l'hôte, sinon 502)
- SSL : certificat Let's Encrypt, Force SSL, HTTP/2

## Commandes utiles

    cd /opt/stacks/foodtruck
    docker compose ps                       état des conteneurs
    docker compose logs -f --tail=100       journaux
    ./ft php artisan foodtruck:check        contrôle du socle
    ./ft php artisan foodtruck:users        liste des comptes
    ./ft php artisan foodtruck:role louis admin      change un rôle (admin|membre)
    ./ft php artisan foodtruck:remove-user akadmin   supprime un compte Foodtruck
    ./ft php artisan test                   tests automatisés (base SQLite en mémoire, sans toucher aux données)
    ./ft php artisan foodtruck:reference    importe les ingrédients de départ manquants (n'écrase rien)
    ./ft php artisan foodtruck:recipes      importe les recettes de départ manquantes (n'écrase rien)
    ./ft php artisan foodtruck:tickets      synchronise les tickets Paperless maintenant
    ./ft php artisan foodtruck:reparse      relit les tickets à valider avec les règles de lecture à jour
    ./ft php artisan foodtruck:reparse --tout   relit aussi les tickets traités (sans doubler les prix)
    ./ft php artisan foodtruck:recettes     récupère maintenant les fiches de recettes de Paperless
    ./ft php artisan foodtruck:relire-recettes   relit les fiches en attente avec les règles à jour
    docker compose logs -f scheduler        journal des tâches planifiées
    ./ft php artisan migrate --force        migrations
    ./ft php artisan config:clear           après modification de src/.env
    docker compose restart                  redémarrage

Journal Laravel : src/storage/logs/laravel-AAAA-MM-JJ.log

## Livraisons

Les comptes Foodtruck sont créés à la première connexion ; ils sont identifiés par leur identifiant Authentik (sub), l'e-mail n'est pas unique. Le compte akadmin d'Authentik n'est pas destiné à un usage quotidien.

Chaque script de mise à jour : vérifie la version en place, les conteneurs et un dépôt Git propre ; sauvegarde la base dans backups/ ; écrit les fichiers ; lance les tests (en cas d'échec, restaure les fichiers via Git et s'arrête sans migrer) ; puis migre et contrôle.

Chaque version est livrée sous forme de script ~/foodtruck-install-vX.Y.Z.sh (ou ~/foodtruck-update-vX.Y.Z.sh), à coller puis exécuter sur la VM, suivi des commandes Git (commit, tag vX.Y.Z, push vers git@github.com:tobilianok/foodtruck.git).

## Paperless (tickets de caisse)

- Paperless-ngx tourne sur la même VM (stack paperless, port 8010, LAN/Tailscale uniquement). Foodtruck l'appelle en http://192.168.1.14:8010 depuis ses conteneurs ; rien n'est modifié côté Paperless hormis le compte dédié.
- Compte Paperless « foodtruck » : non administrateur, permissions « Afficher » sur Documents, Étiquettes et Correspondants ; jeton d'API créé pour lui. Un workflow Paperless (déclencheur : document ajouté ou mis à jour avec l'étiquette « courses alimentaires ») lui donne la permission de lecture sur ces documents.
- Réglage dans Foodtruck : Mon foyer → « Tickets de caisse : Paperless » (adresse, jeton, étiquette). Le jeton est chiffré en base avec APP_KEY.
- L'étiquette « courses alimentaires » appartient au compte tobilianok : foodtruck a reçu le droit « Afficher » dessus (sinon Foodtruck répond « étiquette introuvable »). Connexion vérifiée le 2026-09-29 (HTTP 302 sans jeton, API OK avec le jeton).
- Le workflow ne s'applique qu'aux documents ajoutés ou modifiés après sa création. Pour des tickets déjà présents (ou si une modification en masse ne déclenche pas le workflow), relancer ce script, sans risque de doublon : il donne uniquement le droit « Afficher » à foodtruck sur l'étiquette, ses documents et leurs correspondants.

      cd /opt/stacks/paperless && docker compose exec -T webserver python3 manage.py shell -c "
      NOM = 'courses alimentaires'
      from django.contrib.auth.models import User
      from documents.models import Tag, Document, Correspondent
      from guardian.shortcuts import assign_perm
      u = User.objects.get(username='foodtruck')
      t = Tag.objects.get(name__iexact=NOM)
      assign_perm('view_tag', u, t)
      docs = Document.objects.filter(tags=t)
      for d in docs:
          assign_perm('view_document', u, d)
      cors = Correspondent.objects.filter(documents__in=docs).distinct()
      for c in cors:
          assign_perm('view_correspondent', u, c)
      print('Etiquette :', t.name, '| tickets :', docs.count(), '| magasins :', ', '.join(c.name for c in cors))
      "

- Le magasin d'un ticket est déduit du correspondant Paperless, sinon du texte du ticket (LIDL, LECLERC…).
- Connexion Paperless : compte foodtruck avec les permissions générales « Afficher » (Documents, Étiquettes, Correspondants) ET le droit sur chaque objet (workflow ou script ci-dessus). Diagnostic depuis le conteneur :

      cd /opt/stacks/foodtruck && for p in documents tags correspondents; do printf '%-15s ' $p; ./ft curl -s -o /dev/null -w '%{http_code}\n' -H "Authorization: Token LE_JETON" "http://192.168.1.14:8010/api/$p/?page_size=1"; done

- Texte d'un document Paperless (pour ajuster le lecteur à une nouvelle enseigne) :

      cd /opt/stacks/paperless && docker compose exec -T webserver python3 manage.py shell -c "from documents.models import Document; print(Document.objects.get(pk=ID).content)" 2>/dev/null > ~/paperless-ticket-ID.txt
- Point d'attention (rapport d'infra) : le mot de passe admin de Paperless est à renforcer.

## Liste de courses (v0.8.0)

- Aucun service ni port en plus : les téléphones interrogent l'application (route /courses/liste/{id}/etat, limitée à 120 requêtes par minute) toutes les 6 secondes tant que la page est visible, et pas du tout en arrière-plan.
- La migration ajoute shopping_lists et shopping_list_items ; la sauvegarde SQL de backups/ est faite par le script avant de migrer.
- Les prix utilisés sont ceux du référentiel (estimations puis tickets) : plus il y a de tickets traités, plus les totaux sont justes.

## Stock et anti-gaspi (v0.9.0)

- Aucun service, port ni tâche planifiée en plus : le stock vit dans la base, les suggestions sont calculées à l'ouverture de la page.
- La migration 2026_10_05_800001 ajoute pantry_items et trois colonnes (shopping_list_items.stock_base et stock_ignored, shopping_lists.stock_applied_at) ; la sauvegarde SQL de backups/ est faite par le script avant de migrer.
- Les listes déjà classées avant la v0.9.0 n'alimentent pas le stock (stock_applied_at vide) : rien n'est appliqué rétroactivement, sauf si on rouvre puis reclasse une liste.
- Les dates limites sont comparées en fuseau Europe/Paris.

## Bilan, rapprochement et reset (v0.9.1)

- Aucun service, port ni tâche planifiée en plus. La migration 2026_10_05_900001 ajoute receipts.shopping_list_id ; la sauvegarde SQL de backups/ est faite par le script avant de migrer.
- Les tickets déjà traités ne sont pas rattachés rétroactivement : ouvrir le ticket (Tickets) et choisir sa liste, ou le rattacher depuis le Bilan de la liste.
- Remise à zéro des données d'essai (à lancer à la main, sur la VM) :
  cd /opt/stacks/foodtruck && ./ft reset
  Efface planning, listes de courses et stock de tous les foyers ; garde comptes, foyers, recettes, référentiel, tickets, prix. Un seul foyer : ./ft reset --foyer=1. Sauvegarde automatique avant : backups/foodtruck-avant-reset-AAAAMMJJ-HHMMSS.sql.gz.
- Restaurer une sauvegarde (si besoin) : gzip -dc backups/FICHIER.sql.gz | docker compose exec -T db sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" foodtruck'
- ft est livré par le script de mise à jour (nouvelle commande reset).

## Âge des membres (v0.10.0)

- Aucun service ni port en plus. La migration 2026_10_05_950001 ajoute birth_date et coefficient_manual à household_members ; la sauvegarde SQL de backups/ est faite par le script avant de migrer.
- Les membres existants gardent leur coefficient actuel. Pour activer le calcul automatique : Mon foyer, saisir la date de naissance (laisser « Régler à la main » décoché), Enregistrer.
- Le script de mise à jour exige la v0.9.1 en place (sinon il s'arrête sans rien modifier).

## Interface (v0.11.0)

- Aucun service, port ni migration en plus. Le script de mise à jour exige la v0.10.0 en place (sinon il s'arrête sans rien modifier).
- Nouveaux fichiers statiques : src/public/fonts (polices Bricolage Grotesque et Figtree, licence SIL OFL, voir LICENCES.txt) servies par le conteneur web, sans appel externe. Le style et les scripts sont versionnés par l'adresse (?v=), un rechargement du navigateur suffit après la mise à jour.
- Nouvelles adresses : /plus et /aide. Rien à changer dans Nginx Proxy Manager ni dans Authentik.

## Recettes scannées dans Paperless (v0.12.0)

- Aucun service ni port en plus. La migration 2026_10_05_960001 ajoute households.paperless_recipe_tag et les tables recipe_imports (une ligne par document Paperless lu) et recipe_aliases (rapprochements appris) ; la sauvegarde SQL de backups/ est faite par le script avant de migrer. Le script exige la v0.11.0 en place et importe les nouveaux ingrédients (./ft php artisan foodtruck:reference).
- Dans Paperless : créer l'étiquette « recettes » (ou un autre nom, à indiquer dans Mon foyer → Avancé) et la donner au compte Paperless dédié en lecture (le même que pour les tickets : il doit voir ces documents). Déposer ou scanner une fiche, lui donner l'étiquette : elle est lue à l'heure suivante (tâche planifiée du conteneur scheduler, 20 minutes après les tickets) ou tout de suite avec Recettes → Fiches Paperless → « Chercher dans Paperless ».
- Le texte lu est celui de la reconnaissance de Paperless : un document dont le texte est vide est réessayé plus tard.
- Écrans : /recettes/importees (liste), /recettes/importees/{n} (relecture). Rien à changer dans Nginx Proxy Manager ni dans Authentik.
- Sauvegarde : les fiches lues (texte et rapprochements) sont dans la base, déjà couverte par backups/.

## Données de référence

- Ingrédients de départ : src/database/data/ingredients.php (une ligne par ingrédient, prix estimés en centimes par magasin). Ajouter une ligne puis relancer foodtruck:reference pour l'importer.
- Recettes de départ : src/database/data/recipes.php (ingrédients désignés par leur identifiant, ex. « potimarron »).
- Photos des recettes : src/storage/app/public/recettes (hors Git), servies via le lien src/public/storage (créé par storage:link). À inclure dans les sauvegardes.
- Rayons et magasins : créés par la migration 2026_09_29_100001 ; les foyers existants ont reçu Leclerc Drive (principal) et Morin (fruits et légumes).

## État du dépôt

- git@github.com:tobilianok/foodtruck.git (privé), branche main, tags v0.1.0 à v0.11.0 ; v0.9.1, v0.10.0 et v0.11.0 validées le 2026-10-05 ; v0.12.0 livrée le 2026-10-05, en attente de validation.
- Accès depuis la VM par clé de déploiement "vm-docker" (écriture) ; identité Git réglée dans le dépôt uniquement.

## Sauvegardes

À mettre en place (étape bonus) : dump MariaDB quotidien + photos des recettes (src/storage/app/public). En attendant, chaque script de mise à jour sauvegarde la base dans backups/.
