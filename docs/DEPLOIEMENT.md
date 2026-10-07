# Déploiement - Foodtruck

Dernière mise à jour : 2026-10-05 (v0.12.2)

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
- foodtruck-pages (v0.18.0, remplace foodtruck-ocr) : transforme le fichier d'une fiche (PDF ou photo) en images pour le modèle de vision (Poppler + Pillow, aucune reconnaissance de texte), réseau interne seulement, 768 Mo au plus.
- Ollama (v0.18.0, hors de la stack) : sur le PC de Louis (192.168.1.29:11434, Radeon RX 6800), modèle qwen3-vl:8b-instruct-q8_0 ; joint par foodtruck-app et foodtruck-scheduler (variables FOODTRUCK_VISION_URL et FOODTRUCK_VISION_MODEL de compose.yaml, modifiables dans .env).

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
- Compte Paperless « foodtruck » : non administrateur, permissions « Afficher » sur Documents, Étiquettes et Correspondants ; jeton d'API créé pour lui. Un workflow Paperless « Foodtruck – lecture des tickets » lui donne la permission de lecture sur ces documents : déclencheur 1 « Document ajouté » et (depuis le 2026-10-07) déclencheur 2 « Document mis à jour », chacun filtré sur l'étiquette « courses alimentaires » ; action « Affecter des autorisations de consultation » → foodtruck.
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
- Droit de lecture sur les fiches « recettes » : l'étiquette appartient au compte tobilianok, donc le compte foodtruck ne la voit pas sans droit explicite (message « Étiquette « recettes » introuvable dans Paperless (ou invisible pour ce compte) »). Réglé le 2026-10-05 par un workflow Paperless (Paramètres → Workflows) « Foodtruck - lecture des recettes » : déclencheur 1 « Document ajouté » et déclencheur 2 « Document mis à jour », chacun avec le filtre « A l'un de ces tags » = recettes ; action « Assignation » avec « Affecter des autorisations de consultation » → utilisateur foodtruck (aucun droit d'édition, ni propriétaire, ni étiquette). Le second déclencheur est indispensable quand l'étiquette est ajoutée après l'import du document.
- Documents déjà étiquetés avant la création du workflow (à lancer une fois, sans risque de doublon) :

      cd /opt/stacks/paperless && docker compose exec -T webserver python3 manage.py shell -c "
      NOM = 'recettes'
      from django.contrib.auth.models import User
      from documents.models import Tag, Document
      from guardian.shortcuts import assign_perm
      u = User.objects.get(username='foodtruck')
      t = Tag.objects.filter(name__iexact=NOM).first()
      assign_perm('view_tag', u, t)
      docs = Document.objects.filter(tags=t)
      for d in docs:
          assign_perm('view_document', u, d)
      print('OK :', t.name, '|', docs.count(), 'document(s) rendus visibles pour foodtruck')
      "

- Écrans : /recettes/importees (liste), /recettes/importees/{n} (relecture). Rien à changer dans Nginx Proxy Manager ni dans Authentik.
- Sauvegarde : les fiches lues (texte et rapprochements) sont dans la base, déjà couverte par backups/.

## Lecture des fiches par le modèle de vision (v0.18.0)

- Sur le PC (192.168.1.29) : installation standard d'Ollama (curl -fsSL https://ollama.com/install.sh | sh), puis sudo systemctl edit ollama avec [Service] Environment="OLLAMA_HOST=0.0.0.0:11434", sudo systemctl restart ollama, ollama pull qwen3-vl:8b-instruct-q8_0. Ollama n'a pas de mot de passe : si ufw est actif, sudo ufw allow from 192.168.1.14 to any port 11434 proto tcp. Avant une partie : sudo systemctl stop ollama (les fiches attendent) ; après : sudo systemctl start ollama.
- Depuis la VM Docker : curl -s http://192.168.1.29:11434/api/version ; ./ft php artisan foodtruck:check (lignes « Préparation des pages » et « Lecture par le modèle de vision »).
- Envoi (v0.18.1) : rien ne part sans le bouton « Envoyer à l'IA pour analyse » et la page de contrôle (pages cochées) ; le planificateur prend la fiche envoyée dans la minute, une fiche à la fois (verrou d'une heure) ; avancement dans Recettes → Fiches Paperless. Échec (PC éteint, Ollama arrêté) : erreur affichée, « Renvoyer à l'IA », aucun nouvel essai automatique. Photos du plat proposées : fichiers provisoires dans src/storage/app/public/imports/ (supprimés à la validation, à la suppression de la fiche et par ./ft vider-recettes).
- Changer de machine ou de modèle : dans .env, FOODTRUCK_VISION_URL=http://…:11434 et FOODTRUCK_VISION_MODEL=…, puis docker compose up -d app scheduler.
- ./ft vider-recettes : aperçu, saisie de EFFACER, sauvegarde SQL dans backups/ (les photos des recettes ne sont pas sauvegardées), suppression de toutes les recettes et des fiches lues, relecture de toutes les fiches Paperless.
- Le script (foodtruck-update-v0.18.0.sh) exige la v0.17.0, sauvegarde la base, applique le correctif, construit l'image foodtruck-pages, lance les tests, migre, recrée la stack (foodtruck-ocr supprimé, foodtruck-pages créé), vérifie le service des pages et le site de bout en bout (retour arrière automatique sinon), remet en lecture les fiches à relire, puis supprime l'ancienne image foodtruck-ocr:local.
- v0.18.1 : le script (foodtruck-update-v0.18.1.sh) exige la v0.18.0, sauvegarde la base, applique le correctif, lance les tests, migre, recharge foodtruck-app et foodtruck-scheduler, vérifie le site (retour arrière automatique sinon), puis remet « à envoyer » les fiches à relire pas encore lues par l'IA. Aucune image à reconstruire.
- srv-nas : Ollama désactivé (sudo systemctl disable --now ollama) ; désinstallation complète possible (voir srv-nas-ollama-install.sh).

## Repas composés et menus (v0.22.0)

- Le script (foodtruck-update-v0.22.0.sh) exige la v0.21.0 et un dépôt propre, refuse de tourner pendant une analyse par l'IA, sauvegarde la base, applique le correctif vérifié, lance les tests, migre (tables saved_menus, saved_menu_recipes, colonne meal_plan_entries.saved_menu_id), recharge foodtruck-app et foodtruck-scheduler et vérifie le site. Retour arrière automatique (migration annulée, correctif retiré) si une étape échoue. Aucune image à reconstruire.

## N'importe quel ticket (v0.21.0)

- Le script (foodtruck-update-v0.21.0.sh) exige la v0.20.1 et un dépôt propre, refuse de tourner pendant une analyse par l'IA, sauvegarde la base, applique le correctif vérifié, garde l'image foodtruck-pages en place sous le nom foodtruck-pages:avant-v0.21.0, reconstruit foodtruck-pages (recadrage des tickets scannés), lance les tests, recrée foodtruck-pages, vérifie le recadrage dans le conteneur, recharge foodtruck-app et foodtruck-scheduler et vérifie le site. Retour arrière automatique (correctif retiré, ancienne image remise) si une étape échoue. Aucune migration.
- Après coup, l'ancienne image peut être supprimée : docker image rm foodtruck-pages:avant-v0.21.0

### Un ticket n'arrive pas dans Foodtruck

- Foodtruck ne voit que les documents que le compte Paperless « foodtruck » a le droit d'afficher. Depuis la v0.21.0, « Synchroniser Paperless » dit combien de documents étiquetés il voit.
- Partage à la main d'un ticket : Paperless → le ticket → onglet « Permissions » → « Afficher » : utilisateur foodtruck → Enregistrer.
- Diagnostic (lecture seule) : quels tickets étiquetés foodtruck voit, et réglages des workflows.

      cd /opt/stacks/paperless && docker compose exec -T webserver python3 manage.py shell -c "
      NOM = 'courses alimentaires'
      from django.contrib.auth.models import User
      from documents.models import Tag, Document
      from guardian.shortcuts import get_perms
      u = User.objects.get(username='foodtruck')
      t = Tag.objects.get(name__iexact=NOM)
      vu = lambda o, p: o is not None and (o.owner_id is None or p in get_perms(u, o))
      docs = Document.objects.filter(tags=t).order_by('id')
      print('Etiquette', t.name, ': visible par foodtruck =', vu(t, 'view_tag'), '|', docs.count(), 'document(s)')
      for d in docs:
          c = d.correspondent
          print('  n.', d.id, '| visible :', 'oui' if vu(d, 'view_document') else 'NON', '| magasin', c.name if c else '-', ':', ('oui' if vu(c, 'view_correspondent') else 'NON') if c else '-', '| proprietaire :', d.owner.username if d.owner else 'aucun', '|', d.title)
      try:
          from documents.models import Workflow
          for w in Workflow.objects.all().order_by('order'):
              print('Workflow', repr(w.name), 'actif' if w.enabled else 'DESACTIVE')
              for tr in w.triggers.all():
                  print('   declencheur :', tr.get_type_display(), '| etiquettes :', ', '.join(x.name for x in tr.filter_has_tags.all()) or '-')
              for a in w.actions.all():
                  print('   action : afficher pour', ', '.join(x.username for x in a.assign_view_users.all()) or '-')
      except Exception as e:
          print('Workflows illisibles :', e)
      "

- Correction : le script assign_perm de la section Paperless (plus haut) donne le droit « Afficher » à foodtruck sur l'étiquette, tous ses documents et leurs magasins, sans doublon. Pour les tickets à venir, le workflow des tickets doit avoir deux déclencheurs, « Document ajouté » ET « Document mis à jour », chacun filtré sur l'étiquette « courses alimentaires », avec l'action « Affecter des autorisations de consultation » → foodtruck (comme celui des recettes).
- 2026-10-07 : ticket Carrefour n° 519 absent de Foodtruck (« Aucun nouveau ticket »). Diagnostic : n° 519 et le magasin Carrefour invisibles pour foodtruck (les 6 autres tickets visibles) ; le workflow des tickets n'avait que le déclencheur « Document ajouté », or l'étiquette avait été posée après le dépôt. Correction : script assign_perm, puis déclencheur « Document mis à jour » ajouté au workflow des tickets.

## Unités dans les recettes (v0.20.1)

- Le script (foodtruck-update-v0.20.1.sh) exige la v0.20.0 et un dépôt propre, sauvegarde la base, applique le correctif vérifié, lance les tests, recharge foodtruck-app et foodtruck-scheduler et vérifie le site (retour arrière automatique sinon). Aucune migration, aucune image.

## Accueil cohérent (v0.20.0)

- Le script (foodtruck-update-v0.20.0.sh) exige la v0.19.0 et un dépôt propre, sauvegarde la base, applique le correctif vérifié, lance les tests, migre (suppression des listes de courses vides : aucun article, aucun ticket, aucun produit de stock rattaché), recharge foodtruck-app et foodtruck-scheduler et vérifie le site (retour arrière automatique sinon). Aucune image à reconstruire.
- Retour arrière après coup : la migration n'a supprimé que des listes vides ; la sauvegarde backups/foodtruck-avant-v0.20.0-*.sql.gz les contient au besoin.

## Tickets de caisse lus par le modèle de vision (v0.19.0)

- Même Ollama et même modèle que les fiches (rien à changer sur le PC). Réglages propres aux tickets : num_ctx 24576 et num_predict 8192 (Ollama recharge le modèle quand on passe d'une fiche à un ticket : quelques secondes de plus). Environ 13 à 14 Go sur la carte graphique pendant un ticket.
- foodtruck-pages : nouveau mode « ticket » (découpe des tickets longs) ; l'image est reconstruite par le script (docker compose build pages), aucun accès Internet supplémentaire.
- Envoi : Plus → Tickets de caisse, bouton « Envoyer à l'IA pour analyse » sur chaque ticket → page de contrôle → « Envoyer à l'IA ». Le planificateur (foodtruck:lire-fiches, chaque minute) traite les fiches puis les tickets, un document à la fois. Échec (PC éteint, Ollama arrêté) : erreur affichée, « Renvoyer à l'IA ».
- ./ft vider-tickets : aperçu, saisie de EFFACER, sauvegarde SQL dans backups/foodtruck-avant-vider-tickets-*.sql.gz, suppression des tickets, de leurs lignes, des prix relevés sur les tickets et des libellés mémorisés, puis relecture de la liste Paperless (tickets « à envoyer à l'IA », rien n'est envoyé). Refusé pendant une analyse par l'IA. Retour arrière : restaurer la sauvegarde (gunzip -c … | docker compose exec -T db sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" foodtruck').
- Le script (foodtruck-update-v0.19.0.sh) exige la v0.18.1 et un dépôt propre, sauvegarde la base, applique le correctif vérifié, reconstruit foodtruck-pages, lance les tests, migre, recrée foodtruck-pages (attente de l'état « healthy » puis essai de la découpe dans le conteneur), recharge foodtruck-app et foodtruck-scheduler, vérifie le site de bout en bout (retour arrière automatique sinon). Il ne supprime aucun ticket : ./ft vider-tickets est à lancer ensuite, par Louis.
- Commandes utiles : ./ft php artisan foodtruck:lire-fiches (analyse tout de suite ce qui a été envoyé) ; ./ft php artisan foodtruck:reparse (relit les tickets avec les règles à jour, sans rien renvoyer à l'IA ; les tickets qui attendent l'IA sont laissés de côté).

## Lecture des scans : service foodtruck-ocr (v0.15.0, supprimé en v0.18.0)

- Nouveau conteneur foodtruck-ocr (image foodtruck-ocr:local construite depuis docker/ocr : Debian bookworm, Tesseract 5 + français + détection d'orientation, Poppler, Python 3 avec la seule bibliothèque standard et Pillow). Réseau foodtruck_internal uniquement, aucun port publié, 1 Go de mémoire au plus, /tmp en mémoire, aucun fichier conservé. foodtruck-app et foodtruck-scheduler le joignent par FOODTRUCK_OCR_URL=http://foodtruck-ocr:8080 (compose.yaml).
- Le script de mise à jour (foodtruck-update-v0.15.0.sh) exige la v0.14.0 en place et un dépôt Git propre, sauvegarde la base, applique le correctif, construit l'image (accès Internet de la VM nécessaire pour les paquets Debian), lance les tests (retour arrière si échec), migre, (re)crée foodtruck-ocr, foodtruck-app et foodtruck-scheduler (docker compose up -d), attend que le service soit prêt, contrôle, puis relit d'après le scan les fiches encore à relire.
- Commandes utiles : docker compose ps ocr ; docker compose logs --tail=50 ocr ; ./ft php artisan foodtruck:check (ligne « Lecture des scans ») ; ./ft php artisan foodtruck:lire-fiches (lecture des fiches en attente) et --toutes (relire toutes les fiches à relire).
- Désactiver la lecture des scans sans rien désinstaller : retirer FOODTRUCK_OCR_URL des services app et scheduler dans compose.yaml puis docker compose up -d app scheduler (le texte de Paperless sert, comme avant).
- v0.15.1 : le script (foodtruck-update-v0.15.1.sh) exige la v0.15.0, reconstruit l'image foodtruck-ocr (docker compose build ocr), lance les tests, recrée le conteneur (docker compose up -d ocr), attend qu'il soit prêt puis relit d'après le scan les fiches encore à relire. Aucune migration.
- v0.15.2 : le script (foodtruck-update-v0.15.2.sh) exige la v0.15.1, reconstruit l'image foodtruck-ocr (la construction télécharge le modèle français « best » depuis github.com, environ 4 Mo, vérifié par somme de contrôle : si la VM n'a pas accès à GitHub, la construction échoue et rien n'est changé), lance les tests, recrée le conteneur, attend qu'il soit prêt puis relit d'après le scan toutes les fiches encore à relire. Aucune migration.
- v0.15.4 : Foodtruck télécharge le fichier original des documents Paperless (et non plus la version archivée) ; le jeton Paperless existant suffit. Le script (foodtruck-update-v0.15.4.sh) s'installe sur la v0.15.2 ou la v0.15.3, lance les tests, recharge l'application puis relit d'après le scan toutes les fiches encore à relire. Aucune migration, aucune image à reconstruire.
- v0.16.0 (unités propres des ingrédients) : script foodtruck-update-v0.16.0.sh (exige la v0.15.4, migration ingredient_units, ./ft php artisan foodtruck:unites — relançable sans risque, n'écrase rien —, relecture des fiches). Appliquée le 2026-10-06.
- v0.17.0 (corrections en fenêtre dans les recettes) : le script (foodtruck-update-v0.17.0.sh) exige la v0.16.4 et un dépôt propre, sauvegarde la base, applique le correctif vérifié, lance les tests (retour arrière sinon), vide les caches, redémarre foodtruck-app et foodtruck-scheduler puis teste le site de bout en bout (retour arrière automatique en cas d'échec). Aucune migration, aucune image à reconstruire. Si une fenêtre affiche « Session expirée », recharger la page : les corrections déjà enregistrées sur les ingrédients sont gardées, seules les modifications du formulaire non encore enregistrées sont à refaire.
- v0.16.4 (quantités des cartes HelloFresh) : le script (foodtruck-update-v0.16.4.sh) exige la v0.16.3, reconstruit l'image foodtruck-ocr (qui transmet désormais la hauteur de chaque ligne), lance les tests, vérifie la santé du service et le site de bout en bout (retour arrière automatique sinon), puis relit d'après le scan toutes les fiches encore à relire. Aucune migration.
- v0.16.3 (Nginx et l'adresse de foodtruck-app) : docker/nginx/default.conf utilise le DNS de Docker (resolver 127.0.0.11, valid=10s) et une variable pour fastcgi_pass : l'adresse de foodtruck-app est redemandée à chaque changement, un redémarrage de l'application ne provoque plus de 502. Le script (foodtruck-update-v0.16.3.sh) vérifie la configuration avec nginx -t dans un conteneur jetable, redémarre foodtruck-web puis teste http://127.0.0.1/up depuis foodtruck-web (chaîne Nginx → PHP → Laravel) ; retour arrière automatique si le test échoue. En cas de 502 : docker compose logs --tail=30 web (chercher « connect() failed »), puis docker compose restart web.
- v0.16.2 (correctif de l'image foodtruck-ocr) : le script (foodtruck-update-v0.16.2.sh) exige la v0.16.1 commitée et un dépôt propre, reconstruit l'image (qui se vérifie elle-même à la construction), lance les tests, recrée foodtruck-ocr, attend jusqu'à 3 minutes son état « healthy » et, à défaut, affiche les derniers journaux du service puis revient à la v0.16.1 (fichiers et image). Diagnostic en cas d'alerte : docker inspect --format '{{.RestartCount}}' foodtruck-ocr ; docker compose logs --tail=50 ocr.
- v0.16.1 (validation systématique, mots collés) : le script (foodtruck-update-v0.16.1.sh) exige la v0.16.0, sauvegarde la base, applique le correctif, reconstruit l'image foodtruck-ocr (la construction télécharge le dictionnaire français depuis raw.githubusercontent.com, environ 5 Mo, vérifié par somme de contrôle), lance les tests, recrée foodtruck-ocr puis relit d'après le scan toutes les fiches encore à relire (foodtruck:lire-fiches --toutes). Aucune migration. foodtruck:check affiche le nombre de mots du dictionnaire chargés par le service.
- Performance mesurée sur 2 vCPU à 2,1 GHz : 9 s pour une page Leclerc, 21 s pour une carte HelloFresh de deux pages ; un fil de calcul par lecture (OMP_THREAD_LIMIT=1, plus rapide que plusieurs).

## Fiches à colonnes mélangées (v0.14.0)

- Aucun service, port ni migration en plus. Le script (foodtruck-update-v0.14.0.sh) exige la v0.13.2 en place et un dépôt Git propre, sauvegarde la base, applique un correctif git vérifié, lance les tests (retour arrière par git apply -R si échec), vide les caches, redémarre app et scheduler, puis relit les fiches en attente (foodtruck:relire-recettes).

## Suppression des fiches Paperless à relire (v0.13.1, v0.13.2)

- Aucun service ni port en plus. Le script de la v0.13.2 (foodtruck-update-v0.13.2.sh) exige la v0.13.1 en place, un dépôt Git propre, sauvegarde la base, applique un correctif git vérifié, lance les tests (retour arrière par git apply -R si échec), migre (suppression des fiches mises de côté par la v0.13.1), vide les caches et redémarre app et scheduler.
- « Supprimer » efface la fiche (table recipe_imports) ; elle revient à la recherche suivante tant que le document porte l'étiquette « recettes » dans Paperless (synchronisation horaire comprise).

## Menu automatique (v0.13.0)

- Aucun service ni port en plus. Une migration : meal_plan_entries.proposed_at et proposal_reason, households.menu_veggy_min. Le script de mise à jour (foodtruck-update-v0.13.0.sh) exige la v0.12.2 en place, un dépôt Git propre, sauvegarde la base, applique un correctif git vérifié, lance les tests (retour arrière par git apply -R si échec, avant toute migration), puis migre (./ft php artisan migrate --force), vide les caches et redémarre app et scheduler.
- Retour arrière après migration (si besoin) : restaurer la sauvegarde backups/foodtruck-avant-v0.13.0-*.sql.gz et revenir à la v0.12.2 (git checkout v0.12.2 si le commit a été fait, sinon git apply -R du correctif).
- Se tester : Planning → « Proposer la semaine ». Les propositions (en jaune) n'apparaissent pas dans les courses tant qu'on n'a pas fait « Garder » ou « Valider le menu ». Il faut au moins 8 recettes de plat publiées, différentes, pour remplir une semaine complète.

## Fiches imprimées, bruit de reconnaissance de texte (v0.12.2)

- Aucun service, port ni migration en plus. Le script de mise à jour (foodtruck-update-v0.12.2.sh) exige la v0.12.1 en place, sauvegarde la base, applique un correctif git vérifié (git apply), lance les tests et, en cas d'échec, annule le correctif (git apply -R) sans rien migrer.
- Le script exige un dépôt Git propre : si des fichiers de la VM ne sont pas validés (git status --short), faire d'abord le commit de la v0.12.1 et celui de src/database/data/.
- Pour voir la lecture d'une fiche déjà lue avec l'ancienne version : une fiche encore « à relire » est relue automatiquement quand son document est modifié dans Paperless ; sinon ./ft php artisan foodtruck:relire-recettes la relit tout de suite.

## Fiches de kits repas HelloFresh (v0.12.1)

- Aucun service, port ni migration en plus. Le script de mise à jour exige la v0.12.0 en place (sinon il s'arrête sans rien modifier), applique un correctif git (git apply, vérifié avant), lance les tests et, en cas d'échec, annule le correctif (git apply -R) sans migrer.
- Dans Paperless : donner l'étiquette « recettes » à la carte scannée ; elle est lue à l'heure suivante ou tout de suite avec Recettes → Fiches Paperless → « Chercher dans Paperless ». Une fiche de ce format attend toujours sa relecture (lignes douteuses en rouge, étapes à comparer au PDF).
- Correctif de dépôt : le .gitignore contenait « data/ » (tout dossier data), ce qui excluait src/database/data/ (ingredients.php et recipes.php). Il contient maintenant « /data/ » (racine seulement). Ces deux fichiers existent sur la VM : les ajouter au dépôt avec « git add -A » (la commande est dans les instructions de livraison) ; vérifier avec « git status --short » qu'ils apparaissent bien.
- Texte d'un document Paperless pour ajuster le lecteur : voir la commande de la section Paperless ci-dessus.

## Données de référence

- Ingrédients de départ : src/database/data/ingredients.php (une ligne par ingrédient, prix estimés en centimes par magasin). Ajouter une ligne puis relancer foodtruck:reference pour l'importer.
- Recettes de départ : src/database/data/recipes.php (ingrédients désignés par leur identifiant, ex. « potimarron »).
- Photos des recettes : src/storage/app/public/recettes (hors Git), servies via le lien src/public/storage (créé par storage:link). À inclure dans les sauvegardes.
- Rayons et magasins : créés par la migration 2026_09_29_100001 ; les foyers existants ont reçu Leclerc Drive (principal) et Morin (fruits et légumes).

## État du dépôt

- git@github.com:tobilianok/foodtruck.git, branche main, tags v0.1.0 à v0.12.0 (v0.12.1 appliquée sur la VM le 2026-10-05 ; v0.12.2, v0.13.0, v0.13.1, v0.13.2, v0.14.0, v0.15.0 et v0.15.1 livrées le 2026-10-05, v0.15.2 appliquée sur la VM et poussée le 2026-10-06 ; v0.15.3, v0.15.4, v0.16.0, v0.16.1 et v0.16.2 appliquées et poussées le 2026-10-06 ; v0.16.3 et v0.16.4 appliquées et poussées le 2026-10-06 ; v0.17.0 appliquée le 2026-10-06 ; v0.18.0 appliquée et poussée le 2026-10-06 ; v0.18.1 appliquée et poussée le 2026-10-06, commit 4c08063 ; v0.19.0 appliquée et poussée le 2026-10-07, commit fba8faa ; v0.20.0 appliquée et poussée le 2026-10-07, commit eee5ef6 ; v0.20.1 livrée le 2026-10-07) ; v0.9.1, v0.10.0 et v0.11.0 validées le 2026-10-05. Dépôt rendu public par Louis le 2026-10-05 pour que Claude puisse le lire (accès anonyme en lecture, sans droit d'écriture) ; pour le remettre en privé, autoriser l'application GitHub de Claude sur ce dépôt.
- Accès depuis la VM par clé de déploiement "vm-docker" (écriture) ; identité Git réglée dans le dépôt uniquement.

## Sauvegardes

À mettre en place (étape bonus) : dump MariaDB quotidien + photos des recettes (src/storage/app/public). En attendant, chaque script de mise à jour sauvegarde la base dans backups/.
