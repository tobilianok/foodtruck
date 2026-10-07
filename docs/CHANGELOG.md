# Journal des versions - Foodtruck

## v0.20.1 - 2026-10-07 - « Choisir mes repas » mène au planning ; ml d'un produit compté à la pièce sans aller-retour

- Demandes de Louis :
  - à l'accueil, « Choisir mes repas » ouvre le planning de la semaine (et non plus directement « Ajouter un repas ») ;
  - recette « P'tit Dej' Romy » : « 15 ml d'Oasis Tropical » refusé (« Densité inconnue… impossible de passer du poids au volume ») alors que la fiche de l'ingrédient semblait complète. Cause : l'Oasis se compte à la pièce (bouteille) ; pour convertir des ml en pièces, il manquait ce que contient une pièce, et le message parlait d'autre chose.
- Recette, sans quitter la page :
  - dès qu'on choisit une unité qui ne peut pas être convertie (ml d'un produit à la pièce, pièces d'un produit au poids…), la ligne passe en rouge avec « Indiquer l'équivalence » ;
  - à l'enregistrement, la fenêtre s'ouvre d'elle-même sur la première équivalence manquante ; une fois indiquée, la recette s'enregistre toute seule (plus de refus du serveur suivi d'un aller-retour dans la fiche de l'ingrédient) ;
  - nouvelle question pour un produit à la pièce : « Que contient 1 pièce (bouteille, brique…), en ml ? » ; la densité de l'eau est supposée si elle n'est pas connue (signalé, corrigeable dans la fiche).
- Fiche d'un ingrédient : nouveau champ « Ou contenance d'une pièce (ml) » (« Actuellement : 1 pièce ≈ 2 000 ml »).
- Conditionnement de plus de 500 pièces pour un ingrédient compté à la pièce refusé, avec l'explication (« Bouteille 2L = 2 000 pièces » : une bouteille vaut 1).
- Messages de conversion plus clairs (« Contenance d'une pièce inconnue… (1 pièce = combien de ml ?) », « … convertir entre volume et poids (1 ml = combien de g ?) »).
- Aucune migration. 287 tests automatisés (nouveau PieceVolumeTest).

## v0.20.0 - 2026-10-07 - Accueil cohérent : étapes établies par les faits, plus de liste de courses vide

- Signalé par Louis : camion sur « Courses » alors que le planning et la liste étaient vides. Cause : l'accueil regardait seulement s'il existait une liste ouverte ; il restait deux listes vides du 5 au 11 octobre (une ouverte, une classée), calculées avant la suppression des recettes. Supprimées à la main le 2026-10-07 (sauvegarde faite), et la migration de cette version fait le même ménage.
- Choix de Louis : étapes établies par les faits avec une ligne d'état ; bandeau « planning changé » avec mise à jour en un clic ; listes vides supprimées automatiquement.
- Accueil : chaque étape est cliquable et dit où on en est vraiment : « 2 plats cette semaine » / « Aucun plat cette semaine », « 8 articles à acheter » / « À mettre à jour » / « Pas encore de liste », « 3 / 8 cochés » / « Terminées le 05/10 », « 1 ticket à traiter » / « Ticket rattaché » / « En attente du ticket ».
- Planning modifié après la création de la liste (et rien encore coché) : le camion revient sur « Liste », « Ton planning a changé », bouton « Mettre à jour la liste » (articles ajoutés à la main, magasins choisis et cases cochées gardés). Courses déjà commencées : on reste sur « Courses », avec un rappel ; le bandeau de la liste fait la mise à jour.
- Plus jamais de liste vide : une liste sans article, sans ticket rattaché et sans produit rangé en stock est supprimée (à l'accueil, sur la page Courses, après une mise à jour qui la vide, et au lieu de « Courses terminées »). Créer une liste sur une période sans aucun repas ne crée rien : retour au planning avec un message, et la liste en cours avant la demande le reste.
- Une migration (suppression des listes vides existantes). 284 tests automatisés (HomeFlowTest : liste vide ignorée et supprimée, planning modifié après la liste).

## v0.19.0 - 2026-10-07 - Tickets de caisse lus par l'IA, validés un par un avec corrections en fenêtre ; photos des recettes alignées

- Demande de Louis : « que cette IA locale puisse traiter aussi tout ce qui est tickets de caisse », avec le même contrôle que pour les fiches, et « valider les tickets systématiquement avec les corrections en popup sans quitter la correction du ticket ». Modèle inchangé (qwen3-vl:8b-instruct-q8_0 sur le PC, choix de Louis : « on ne garde que le 8B »).
- Essais du 2026-10-07 sur les 6 tickets de Louis dans Paperless (472 Leclerc Drive ; 473, 474, 476, 477, 478 Lidl Plus) :
  - premier essai : Leclerc juste au centime, mais tickets Lidl aux prix arrondis à l'euro ; cause : un ticket Lidl Plus est une image de 1 290 × 8 648 pixels, réduite d'un bloc par le modèle (environ 400 pixels de large, centimes illisibles) ;
  - deuxième essai : tickets longs découpés en morceaux lisibles (4 à 5 images de 1 000 pixels de large, coupées dans les blancs entre deux lignes, aucune ligne coupée ni répétée), prix recopiés en texte : centimes lus partout, 4 tickets sur 6 justes ;
  - troisième essai (consigne finale : date recopiée telle qu'imprimée, ligne de pesée recopiée à part) : 5 tickets sur 6 justes par l'IA seule ; avec les règles du code, les 6 retombent au centime sur leur total (91,35 €, 9,88 €, 41,83 €, 31,65 €, 73,96 €, 109,09 €). 22 secondes à 1 min 20 par ticket.
- L'IA recopie, le code interprète et contrôle (nouveau VisionReceiptParser) :
  - lignes qui ne sont pas des articles écartées (« Nombre de lignes: 4 » recopié avec le total) ;
  - ligne de pesée recopiée à part rattachée à son article ; poids et prix au kilo lus dans la ligne « 1,420 kg x 1,99 EUR/kg » ; poids absent : déduit du prix ÷ prix au kilo, signalé ;
  - article emballé à la pièce même si son libellé contient un poids (« Carottes sachet 1 kg ») ; remises négatives rattachées à l'article du dessus ;
  - contrôle quantité × prix unitaire = prix (ligne « à vérifier » sinon), chiffre douteux signalé, un seul prix illisible déduit du total (signalé) ;
  - date « 24.09.26 » convertie par le code (l'IA confondait jour et année).
- Envoi comme pour les fiches : les tickets trouvés dans Paperless arrivent « à envoyer à l'IA » ; bouton « Envoyer à l'IA pour analyse » → page de contrôle (destination, modèle, images exactes envoyées, morceaux compris ; consigne ; format de réponse) ; un seul document à la fois chez Ollama, fiche de recette ou ticket ; annulation tant que l'analyse n'a pas commencé ; barre de progression dans Tickets de caisse ; aucun nouvel essai automatique (erreur affichée, « Renvoyer à l'IA »). Le texte OCR de Paperless n'est plus utilisé pour les tickets.
- Validation systématique : plus aucun ticket n'est traité tout seul, même entièrement reconnu ; « Valider le ticket et enregistrer les prix ».
- Corrections en fenêtre, sans quitter le ticket (comme les recettes) : chaque ligne à vérifier a son bouton (« Vérifier la lecture », « Choisir ou créer l'ingrédient », « Oui, c'est … » / « Autre choix ») et « Corriger » sur toutes les lignes. La fenêtre regroupe : quantité ou poids, vendu à la pièce ou pesé, prix unitaire et prix payé avec le calcul vérifié en direct ; ingrédient existant ou nouvel ingrédient créé sur place (rayon et unité proposés d'après la ligne) ; ignorer cette fois ou toujours. La ligne passe au vert ; compteur « N lignes à vérifier » et contrôle « somme des lignes = total » recalculés à chaque correction ; total du ticket corrigeable ; « Confirmer les N propositions » d'un clic.
- « Relire le ticket » relit la réponse déjà reçue avec les règles à jour, sans rien renvoyer à l'IA ; un ticket déjà validé et toujours entièrement reconnu reste traité.
- ./ft vider-tickets (demande de Louis : « repartir sur une base plus saine ») : aperçu, saisie de EFFACER, sauvegarde de la base ; supprime tous les tickets et leurs lignes, les prix relevés sur les tickets et les libellés de tickets mémorisés ; garde ingrédients, conditionnements, prix saisis à la main, magasins, stock, listes et recettes ; les tickets de Paperless reviennent aussitôt « à envoyer à l'IA ».
- Recettes : toutes les photos de la liste ont la même taille (4:3, recadrées), les cartes sont alignées quelles que soient les proportions des photos.
- foodtruck-pages : mode « ticket » (découpe des tickets longs), image à reconstruire. Une migration (état de la lecture par l'IA sur receipts). 282 tests automatisés (nouveaux : ReceiptVisionTest, VisionReceiptParserTest avec les réponses réelles du modèle pour les 6 tickets).

## v0.18.1 - 2026-10-06 - Envoi à l'IA fiche par fiche, page de contrôle, photo du plat

- Demande de Louis : « je veux pouvoir contrôler à l'extrême ce qui est envoyé sur Ollama sur mon PC et envoyer une fiche par une fiche ». Plus rien ne part tout seul vers le PC.
- Les fiches trouvées dans Paperless arrivent « à envoyer à l'IA ». Bouton « Envoyer à l'IA pour analyse » sur chaque fiche → page de contrôle : destination (192.168.1.29:11434) et modèle, images exactes des pages avec une case à cocher par page (décocher la photo ou les pictogrammes accélère l'analyse ; aperçu 100 dpi, envoi 200 dpi), consigne et format de réponse imposé ; « Envoyer à l'IA » ou « Ne rien envoyer ». Rien d'autre n'est envoyé (ni texte de Paperless, ni recettes, ni compte).
- Une seule fiche à la fois chez Ollama : tant qu'une analyse est en file ou en cours, les autres boutons sont désactivés. « Annuler l'envoi » tant que l'analyse n'a pas commencé.
- Plus aucun nouvel essai automatique : en cas d'échec (PC éteint, Ollama arrêté, réponse illisible), l'erreur est affichée sur la fiche et « Renvoyer à l'IA » repasse par la page de contrôle.
- Photo du plat : le modèle indique où se trouve la grande photo du plat (rectangle sur la page) ; Foodtruck la découpe en bonne résolution (cadre élargi puis resserré sur les bords de la photo) et la propose à la relecture, case « Utiliser la photo de la fiche (découpée par l'IA) » cochée par défaut ; à la validation, elle devient la photo de la recette. Essai sur la piémontaise : cadrage juste.
- « Relire la fiche » reprend la réponse déjà reçue avec les règles à jour, sans rien envoyer ; « Renvoyer à l'IA pour analyse » sur l'écran de relecture.
- Le titre retenu est le titre principal de la fiche (sans la ligne de sous-titre).
- ./ft vider-recettes : les fiches reviennent « à envoyer à l'IA » (rien n'est envoyé), photos proposées comprises dans le ménage.
- Une migration (pages choisies ; colonnes des nouvelles tentatives de la v0.18.0 retirées ; fiches en attente d'envoi automatique remises « à envoyer »). 259 tests automatisés.

## v0.18.0 - 2026-10-06 - Fiches lues par un modèle de vision (Ollama), barre de progression, suppression de toutes les recettes

- Constat de Louis sur la fiche « Salade façon piémontaise au jambon » (HelloFresh, Paperless n° 490) : la lecture par Tesseract n'était pas fiable (« Mayonnaise 1 sachet » lu « EPENNENES CEÉIEREN », « Moutarde 1 cc » perdue, étapes dans le désordre), et chaque correction cassait une autre carte. Décision : les fiches sont lues par un modèle de vision local, et plus par aucun autre outil de reconnaissance de texte.
- Essai du 2026-10-06 : sur srv-nas (i5-6500, sans carte graphique utilisable) le modèle était bien trop lent (plus de 2 heures par fiche, version « thinking » qui réfléchissait sans fin). Sur le PC de Louis (Radeon RX 6800, Ollama installé tel quel, accessible depuis le réseau local), qwen3-vl:8b-instruct-q8_0 lit la piémontaise en 1 minute, entièrement juste : 12 ingrédients (mayonnaise et moutarde comprises, ½ sachet de ciboulette), 6 étapes titrées dans l'ordre des numéros, les deux astuces du chef.
- Lecture : Paperless (fichier original) → foodtruck-pages (nouveau petit service : PDF ou photo en images, Poppler + Pillow, aucune reconnaissance de texte) → Ollama sur le PC (192.168.1.29) → recette en JSON imposé → analyse habituelle (rapprochement des ingrédients, unités propres, lignes en rouge, fenêtres de correction de la v0.17.0). Réglages validés : version « instruct » sans réflexion, réglages recommandés par Qwen (température 0,2), réponse plafonnée, 200 dpi. Une ligne que le modèle juge difficile à lire passe en rouge.
- Barre de progression dans Recettes → Fiches Paperless, mise à jour toutes les 3 secondes : téléchargement, préparation des pages, envoi à Ollama, lecture des images, écriture de la recette, vérification ; temps écoulé ; la page se recharge quand la fiche est prête. Une fiche à la fois.
- PC éteint ou Ollama arrêté (pendant une partie de jeu, par exemple) : la fiche attend, « En attente : Ollama injoignable… nouvel essai vers HH:MM », nouvel essai toutes les 15 minutes sans limite. Lecture ratée (réponse illisible) : nouvel essai 5, 10, 20, 40 puis 60 min plus tard ; après 6 échecs, l'erreur est affichée et « Relire la fiche » relance. Le texte OCR de Paperless n'est plus jamais utilisé (sauf sans modèle configuré, pour le développement et les tests).
- Supprimés : le conteneur foodtruck-ocr (Tesseract, dictionnaire de mots collés, découpage en colonnes), LayoutComposer et ses règles de réparation, la commande d'import du lot de recettes de départ (foodtruck:recipes).
- Nouveau : « ./ft vider-recettes » (demande de Louis) : supprime toutes les recettes (y compris celles générées au départ), leurs photos, les repas du planning qui les utilisent et toutes les fiches déjà lues, après un aperçu, la saisie de EFFACER et une sauvegarde de la base ; garde ingrédients, unités, rapprochements appris, prix, stock et listes ; relance aussitôt la lecture de toutes les fiches Paperless.
- « Relire la fiche » sur une fiche lue avant la v0.18.0 la fait relire par le modèle. foodtruck:check : lignes « Préparation des pages » et « Lecture par le modèle de vision ».
- Une migration (avancement et nouvelles tentatives sur recipe_imports). 255 tests automatisés (RecipeScanVisionTest, RecipeWipeTest, VisionComposerTest avec la réponse réelle du modèle pour la piémontaise).

## v0.17.0 - 2026-10-06 - Corrections en fenêtre pendant la relecture ou la saisie d'une recette : la ligne passe au vert en direct

- Demande de Louis : quand une recette importée (ou en cours de saisie) a des ingrédients à créer ou à corriger, ne plus basculer sur la page de création d'un ingrédient (qui fermait la recette en cours) ; toutes les corrections se font dans une fenêtre, et la ligne passe au vert dès qu'elle est corrigée.
- Chaque ligne « À vérifier » a maintenant un bouton qui ouvre la bonne fenêtre, sans quitter la recette ni perdre ce qui a été saisi :
  - « Choisir ou créer l'ingrédient » : propositions cliquables, recherche dans les ingrédients, ou création sur place (nom, rayon, unité de base, poids d'une pièce ; unités courantes pré-remplies comme d'habitude) ;
  - « Confirmer l'ingrédient » (rapprochement approximatif, « lu « ciboulette » ») : « Oui, c'est … » en un clic, ou un autre ingrédient ;
  - « Corriger » (fraction illisible « % », quantité démesurée, unité inconnue…) : texte lu sur la fiche rappelé, fractions en un clic (¼ ⅓ ½ ⅔ ¾ 1 1½ 2), quantité et unité ;
  - « Indiquer l'équivalence » (« 1 botte de Radis = ? g », poids d'une pièce, densité) : retenue tout de suite pour toutes les recettes ;
  - « corriger » à côté d'un équivalent typique (« ≈ 5 g ») : la valeur typique devient celle de Louis.
- Enchaînement automatique : après la création d'un ingrédient, s'il manque encore une équivalence pour convertir la ligne (« 1 botte = ? g »), la question suit dans la même fenêtre ; sinon la ligne passe au vert. Le compteur « N ingrédients à vérifier » se met à jour en direct.
- Saisie d'une nouvelle recette : un nom d'ingrédient inconnu est signalé aussitôt, avec le même bouton de création en fenêtre.
- Sous le capot : trois adresses qui répondent en JSON (POST /ingredients/rapide, /ingredients/{ingrédient}/unites/rapide, /ingredients/{ingrédient}/mesures/rapide), réservées aux membres connectés et protégées par le jeton CSRF ; un ingrédient déjà existant (même nom à l'accent ou à la majuscule près) est repris, jamais dupliqué. Si la session a expiré, la fenêtre le dit (« recharge la page ») au lieu d'échouer en silence.
- Aucune migration, aucune image à reconstruire. 261 tests automatisés (nouveau IngredientQuickTest, RecipeScanFlowTest adapté).

## v0.16.4 - 2026-10-06 - Cartes HelloFresh : quantités du tableau d'ingrédients retrouvées (Salade de grenailles)

- Signalé par Louis sur une fiche toute neuve (« Salade de grenailles, poulet doré & radis », HelloFresh, document n° 491) : toutes les unités en « selon goût », une ligne « À ajouter » prise pour un ingrédient, et des étapes parasites (quantités, morceaux du paragraphe des allergènes, bas de carte).
- Cause : sur cette carte, la colonne des noms du tableau d'ingrédients a été lue dans le même bloc que le paragraphe « C'est parti ! » au-dessus, et la colonne des quantités dans un bloc à part : les noms arrivaient sans quantité, et les quantités finissaient dans les étapes. Ni l'unité propre des ingrédients (v0.16.0) ni leur conversion ne pouvaient agir sans quantité.
- Correction : le service de lecture transmet maintenant la hauteur de chaque ligne ; sur une carte de kit, une colonne faite seulement de quantités (« 400 g », « 1 paquet(s) », « ⅔ sachet(s) », « 2cc », « selon votre goût ») est recollée, ligne par ligne, au nom situé à la même hauteur à sa gauche. « 400 g » (une seule lettre) n'est plus écarté. « À ajouter » seul devient l'intertitre du groupe ; « bottes » est reconnu comme unité ; « 7% » est lu comme « ⅔ » (signalé) ; fragments du paragraphe des allergènes, « vous-même » orphelin et « Semaine 33… » écartés ; « Blanc de poulet » rapproché de « Filet de poulet ».
- Résultat sur la carte : 14 ingrédients avec leurs quantités (fractions illisibles « % » signalées en rouge), groupe « À ajouter vous-même », 4 étapes titrées dans l'ordre (Top départ, Le poulet entre en scène, La touche vinaigrée, Service !). Les 5 autres fiches de test : lecture inchangée.
- Le script reconstruit l'image foodtruck-ocr, vérifie le site de bout en bout, puis relit d'après le scan toutes les fiches encore à relire (la salade comprise).
- Aucune migration. 256 tests automatisés (nouvelle fixture hellofresh-salade-grenailles.json).

## v0.16.3 - 2026-10-06 - Correctif : site en « 502 Bad Gateway » après un redémarrage de l'application

- Signalé par Louis à 14:02 : foodtruck.louisrousseaux.fr répondait « 502 Bad Gateway ». Diagnostic : tous les conteneurs tournaient, mais foodtruck-web (Nginx) essayait encore de joindre PHP à l'ancienne adresse de foodtruck-app (« connect() failed (111: Connection refused) … fastcgi://172.28.0.6:9000 »).
- Cause : Nginx ne cherche l'adresse de foodtruck-app qu'une fois, à son démarrage. Le script de la v0.16.2 a redémarré foodtruck-app pendant que foodtruck-ocr redémarrait en boucle ; Docker lui a donné une autre adresse IP, et Nginx (démarré la veille) a gardé l'ancienne. Le site était indisponible depuis 13:05.
- Remise en route immédiate : redémarrage de foodtruck-web. Correction durable : Nginx redemande l'adresse de foodtruck-app au DNS de Docker (au plus toutes les 10 secondes), un redémarrage de l'application ne casse plus le site.
- Script de mise à jour : configuration Nginx vérifiée (nginx -t) avant d'être appliquée ; après le redémarrage, le site est testé de bout en bout (Nginx → PHP → Laravel, adresse /up) ; retour arrière automatique en cas d'échec. Ce contrôle de bout en bout sera repris dans tous les scripts suivants.
- Aucune migration. 255 tests automatisés.

## v0.16.2 - 2026-10-06 - Correctif : service de lecture arrêté depuis la v0.16.1 (« Permission denied »)

- Signalé par Louis : alertes répétées de la supervision (« conteneur foodtruck-ocr en mauvaise santé »). Diagnostic : le service redémarrait en boucle (68 redémarrages), « python3: can't open file '/app/server.py': [Errno 13] Permission denied ».
- Cause : dans l'image de la v0.16.1, le dictionnaire était ajouté avec des droits 644 avant la création du dossier /app ; Docker a alors créé /app avec ces mêmes droits, sans le droit de traverser le dossier : l'utilisateur du service ne pouvait plus lire ses propres fichiers. Les tests de l'application ne le voyaient pas (ils simulent le service).
- Correction : /app est créé d'abord (755), droits remis explicitement, et l'image vérifie maintenant à sa construction, avec l'utilisateur du service, que le programme se charge et que le dictionnaire est lisible : une image qui ne démarrerait pas n'est plus jamais construite.
- Script de mise à jour plus sûr : après le redémarrage du service de lecture, il attend son bon état de santé ; s'il ne l'obtient pas en 3 minutes, il affiche les derniers messages du service et revient automatiquement à la version précédente.
- Aucune migration. 255 tests automatisés.

## v0.16.1 - 2026-10-06 - Validation systématique des fiches Paperless, mots collés recoupés

- Validation systématique (demande de Louis) : plus aucune recette n'est créée ni publiée toute seule depuis Paperless, même entièrement reconnue. Une fiche sans rien à corriger apparaît « à valider » (« tout est reconnu, il ne reste qu'à valider ») ; le bouton de l'écran de relecture devient « Valider la recette ». « Chercher dans Paperless » annonce « N fiches prêtes à valider, M à compléter ».
- Mots collés (signalé par Louis : « Curry thaïléger ») : foodtruck-ocr recoupe les mots que la lecture a collés, d'après un dictionnaire français de 336 000 formes (liste « an-array-of-french-words », licence MIT, téléchargée à la construction de l'image et vérifiée par somme de contrôle) complété du vocabulaire de cuisine : « thaïléger » → « thaï léger », « surfeumoyenavecunpetitfilet » → « sur feu moyen avec un petit filet », « entemps », « etle », « dufeu », « Ajoutez-yla », « jusqu'àce », « casserole,ou ». Règles prudentes : mots connus, noms propres, adresses et mots en majuscules jamais touchés ; un mot court n'est recoupé qu'autour d'un petit mot de liaison (« carbonara » reste entier). Le titre du document Paperless, d'où vient le titre de la recette, est corrigé de la même façon. Vérifié sur le curry lu d'après la version archivée de Paperless (la plus dégradée) : plus aucun mot collé, 6 étapes dans l'ordre.
- Le script reconstruit l'image foodtruck-ocr (dictionnaire téléchargé depuis raw.githubusercontent.com), puis relit d'après le scan toutes les fiches encore à relire (le titre « Curry thaïléger » se corrige).
- Aucune migration. 255 tests automatisés (1 nouveau dans RecipeScanLayoutTest, RecipeScanFlowTest adapté à la validation systématique).

## v0.16.0 - 2026-10-06 - Plusieurs unités par ingrédient (sachet, gousse, boîte…) : import des fiches presque sans retouche

- Demande de Louis : les unités d'une fiche importée doivent se convertir toutes seules dans l'unité de l'ingrédient enregistré dans Foodtruck, pour n'avoir plus qu'un contrôle et une validation finale. Choix validés : la recette garde l'unité de la fiche (« ½ sachet ») avec l'équivalent en petit à côté (« ≈ 5 g ») ; les unités courantes sont pré-remplies avec des valeurs typiques, corrigées ensuite si besoin.
- Nouveau : chaque ingrédient peut avoir ses propres unités (table ingredient_units) : « 1 sachet = 10 g », « 1 gousse = 5 g », « 1 boîte = 400 ml », « 1 cm = 5 g »… L'unité de base (g, ml, pièce) reste la référence pour la liste de courses, les prix et le stock ; les recettes peuvent utiliser n'importe quelle unité de l'ingrédient, toujours convertie.
- Page d'un ingrédient : section « Unités de mesure » (ajouter, corriger, supprimer ; une valeur typique est marquée « valeur typique » jusqu'à ce qu'elle soit validée ; une unité utilisée par une recette ne peut pas être supprimée).
- Valeurs typiques pré-remplies (commande foodtruck:unites, lancée par le script, et à la création d'un ingrédient) : ail (gousse, tête), gingembre (cm, morceau), herbes fraîches (sachet, botte, bouquet, brin, feuille, poignée), thym et romarin (brin, branche), laurier, sauge, citronnelle, levure, sucre vanillé, gélatine, épices en sachet, jambon, bacon, pain, fromage en tranches, conserves (boîte), lait de coco, crème (brique, pot), yaourt, beurre (noix, noisette), salade et fruits secs (poignée), chocolat (carré, tablette), pâtes à tarte (rouleau).
- Import des fiches : « 4 gousses », « ½ sachet », « 1 boîte », « 2 cm », « 3 brins » deviennent l'unité de l'ingrédient. Si elle n'existe pas encore, Foodtruck l'estime d'après un conditionnement du même nom (« Boîte 40 cl »), une valeur typique, ou le seul conditionnement de l'ingrédient, et la rappelle en petit sous la ligne avec un lien « corriger ». Les cuillères d'un ingrédient pesé sans densité (« 2 c. à soupe de beurre ») sont estimées de la même façon.
- Quand rien ne permet d'estimer (« 1 paquet de crevettes » sans conditionnement), la ligne pose UNE question : « 1 paquet = [ ] g ». La réponse est retenue sur l'ingrédient : les fiches suivantes passent toutes seules. Même chose pour le poids d'une pièce ou la densité manquants.
- La relecture et le formulaire de recette proposent, pour chaque ligne, les unités de l'ingrédient en plus des unités générales.
- Migration : table ingredient_units ; recipe_ingredients.unit passe à 40 caractères (une unité propre est notée « u:sachet »). 254 tests automatisés (6 nouveaux dans IngredientUnitsTest). Appliquée sur la VM et poussée le 2026-10-06.

## v0.15.4 - 2026-10-06 - Lecture des scans d'après l'original Paperless (mots collés, étapes en désordre)

- Signalé par Louis sur le curry thaï (fiche n° 28 sur la VM) : mots collés (« surfeumoyenavecunpetitfilet », « entemps », « personne2min »), première étape « msg 0e Chop, chop, chop » séparée de son texte, titre « Dernier coup de poêle » coupé en morceaux et mélangé à l'étape « Tout baigne ».
- Cause trouvée : Foodtruck téléchargeait la version archivée de Paperless. Sa conversion en PDF/A recompresse les images du scan (JPEG plus dégradé que celui de la photocopieuse) ; les espaces entre les mots et les petits pictogrammes en souffrent. Reproduit à l'identique en recompressant le scan original du curry comme Paperless : mêmes mots collés. Avec l'original, la lecture est propre (6 étapes titrées dans l'ordre, aucun mot collé).
- Correction : Foodtruck lit maintenant toujours le fichier original du document (API Paperless, paramètre original=true). Rien à changer dans Paperless.
- « ¼ » lu « Y4 », « Ya » ou « Y » devant une unité (« avec Ya cc de curry ») remis en « ¼ ».
- Le script s'installe sur la v0.15.2 (il contient aussi la v0.15.3) ou sur la v0.15.3, puis relit d'après le scan toutes les fiches encore à relire.
- Aucune migration. 248 tests automatisés.

## v0.15.3 - 2026-10-06 - Fiche recette : bloc « L'essentiel » aligné et ordonné

- Signalé par Louis : dans le formulaire d'une recette (et la relecture d'une fiche Paperless), les champs du bloc « L'essentiel » n'étaient ni alignés ni ordonnés (hauteurs différentes, libellés sur deux lignes, champs étirés).
- Nouvelle grille à 4 colonnes, rangée par sujet : titre ; présentation ; catégorie, difficulté, protéine principale, recette prévue pour ; préparation, cuisson, repos, prix industriel ; source. Tous les champs ont la même hauteur, chaque libellé tient sur une ligne et les aides sont sous les champs. Sur téléphone (moins de 900 px de large) : 2 colonnes, protéine et « prévue pour » sur toute la largeur.
- Libellés raccourcis : « Prix industriel » (aide : « Facultatif : le plat tout prêt, pour chiffrer l'économie »).
- Correction générale : un champ de formulaire n'est plus étiré en hauteur quand son voisin est plus grand (tous les formulaires de l'application).
- Correction d'une accolade en trop à la fin de la feuille de style de la v0.15.2 (sans effet visible jusqu'ici, mais elle aurait annulé la règle suivante).
- Aucune migration. 248 tests automatisés.

## v0.15.2 - 2026-10-06 - Écran de relecture compact et lecture des scans plus précise (Ratatouille Leclerc)

- Signalé par Louis sur la Ratatouille Leclerc : écran de relecture « fouillis » et lecture approximative (« tt », « courgeties »).
- Écran de relecture des ingrédients refait en liste compacte : une ligne par ingrédient (ingrédient, quantité, unité, précision, groupe, facultatif, retirer), en-têtes de colonnes une seule fois. Les lignes à vérifier ont une bordure rouge et, juste dessous, ce qui a été lu, le problème et les ingrédients proposés en boutons (un clic remplit la ligne et la marque comme réglée). « Créer cet ingrédient » n'apparaît plus que pour un ingrédient inconnu. Bandeau « N lignes à vérifier sur M » avec la case « Afficher seulement les lignes à vérifier ». Sur téléphone, chaque ingrédient tient sur trois lignes courtes au lieu de sept champs empilés.
- Rapprochement des ingrédients : une couleur que l'ingrédient ne précise pas est ignorée (« poivron vert » et « poivron rouge » → Poivron, « oignon blanc » → Oignon), mais une couleur qui le contredit ne l'est jamais (« vin blanc » ne devient pas Vin rouge, « curry rouge » pas Curry vert) ; une couleur commune ne suffit plus à proposer un ingrédient. Petite faute de lecture (« courgeties », « Carote ») : rapprochée de l'ingrédient le plus proche mais signalée en rouge, à confirmer d'un coup d'œil. Un libellé trop court ou illisible (« tt ») reste à compléter.
- Lecture des scans (foodtruck-ocr) : modèle français « best » de Tesseract (plus précis, téléchargé depuis GitHub pendant la construction de l'image, vérifié par somme de contrôle) ; image passée en gris par le canal le plus sombre avant la lecture (les titres en couleur ne disparaissent plus, contraste et netteté renforcés) ; seconde lecture « texte épars » pour les zones que la première prend pour des images (bandeau « À table dans : 35 - 45 Min » du curry). Mesuré sur les cinq fiches de test : tous les repères contrôlés sont bien lus (39 sur 39). Compter environ 10 % de temps de lecture en plus.
- Découpage et remise en forme : les bouts de lignes de deux rangées d'étapes qui débordent dans la même gouttière restent dans leur colonne (« … dans la casserole, ou jusqu'à ce qu'il soit tendre ») ; une grille d'étapes titrées (2 rangées de 3) est lue rangée par rangée ; un mot parasite au-dessus d'un titre d'étape (« due ») ne fait plus disparaître l'étape ; bandeau « © 4 pers  15 mn » (pictogramme lu « © ») reconnu ; pied de page « www,mesrecettes,leclerc » reconnu malgré les virgules (plus de réserve « texte après la dernière étape ») ; « A ajouter vous-meme » remis au propre ; « Y4 » lu pour « ¼ » ; « 4 sachet » signalé (la fraction ½ ou ¼ est souvent lue « 4 »).
- Résultats : Ratatouille (nouvelle fixture leclerc-ratatouille.json) : 15 ingrédients exacts, 4 personnes, 16 min, 4 étapes, aucune réserve ; Croziflette : 4 personnes et 15 min retrouvés ; Orzo : « 320 g » de crevettes lu sans faute, 4 étapes ; Curry : 45 min retrouvées, étapes complètes. Fiches lues depuis le texte de Paperless : inchangées (comparaison sur les 7 fixtures).
- Le script reconstruit l'image foodtruck-ocr (accès Internet de la VM vers github.com nécessaire pour le modèle « best », environ 4 Mo), la redémarre et relit d'après le scan toutes les fiches encore à relire.
- Aucune migration. 248 tests automatisés attendus (nouveaux : IngredientMatcherTest, 3 ; test de la Ratatouille dans LayoutComposerTest ; fixtures de mise en page relues avec le nouveau service).

## v0.15.1 - 2026-10-05 - Lecture des scans : colonnes étroites (carte HelloFresh du curry thaï)

- Signalé par Louis : sur la carte HelloFresh « Curry thaï léger aux crevettes & coco » (document n° 481), toutes les étapes sortaient en une seule grande étape mélangée.
- Cause : les gouttières entre les colonnes d'étapes de cette carte ne font que 30 à 40 pixels, et quelques mots (titres, paragraphe des allergènes) mordent dessus ; le découpage exigeait un blanc parfait de 42 pixels et ne coupait donc rien.
- Nouveau découpage des colonnes dans foodtruck-ocr : pour chaque position, on compte les lignes de texte qui la traversent ; une gouttière peut être étroite et traversée par quelques mots si la bande a beaucoup de lignes (un vrai blanc reste exigé pour un petit paragraphe ou une ligne seule). Deux garde-fous : un tableau « nom … quantité » (ingrédients, valeurs nutritionnelles) n'est jamais coupé, même quand des quantités ont perdu leur chiffre (« sachet(s) ») ; quelques bouts de lignes (« péremption », « casserole, ou ») restent avec la ligne commencée à leur gauche.
- Remise en forme (LayoutComposer) : la liste d'ingrédients ne déborde plus sur la colonne voisine et reprend plus bas dans sa colonne (« Huile de tournesol », « Poivre et sel ») ; encadrés « ZOOM NUTRITION » et « L'ASTUCE DU CHEF » rangés en conseil sans avaler les étapes suivantes ; titres d'étape plus gros que le texte reconnus même mal lus (« revettes au chaud ») ou terminés par « ? » ; puces lues « e » retirées ; blocs illisibles (confiance très faible : photos, pictogrammes) écartés ; « À table dans » reconnu derrière un pictogramme mal lu.
- Quantités de cartes : « 1% cs » lu pour 1½ cs, quantité absente (« Citron* pièce(s) ») = ½ supposé, « 1cm » de gingembre = 1 pièce avec précision « 1 cm », toujours signalées en rouge ; « paquet » compte des pièces (« 1 paquet de crevettes »).
- Résultat sur le curry : 6 étapes titrées dans l'ordre (Chop, chop, chop ; Tout baigne ; Crevettes au chaud ; La cuisson, la suite ; Dernier coup de poêle ; Comment est votre curry ?), 14 ingrédients, conseil, 2 personnes, 45 min. Croziflette, Orzo et la page générique : lecture inchangée.
- Le script reconstruit l'image foodtruck-ocr, la redémarre et relit d'après le scan les fiches encore à relire (dont le curry).
- Aucune migration. 244 tests automatisés attendus (2 nouveaux dans LayoutComposerTest, fixture hellofresh-curry-thai.json).

## v0.15.0 - 2026-10-05 - Lecture fiable des fiches : le scan est lu par Foodtruck, avec la position des mots

- Demande de Louis : une lecture des fiches fiable, quitte à ajouter des outils à la stack (tout reste local : ni cloud, ni PC de jeu). Constat : les fiches arrivent de la photocopieuse en image sans texte, et le texte « à plat » de Paperless mélange les colonnes (Croziflette, Orzo HelloFresh) ; le lecteur à règles ne pouvait pas rattraper toutes les mises en page.
- Nouveau conteneur foodtruck-ocr (docker/ocr) : Tesseract 5 en français + Poppler, petit service HTTP interne (bibliothèque standard Python, aucun port publié, réseau foodtruck_internal, 1 Go de mémoire au plus, une lecture à la fois). Il lit chaque page à 300 dpi, redresse les photos et scans tournés, et découpe la page par position (« XY-cut » : bandes puis colonnes) : les colonnes ne sont plus jamais mélangées, chaque paragraphe reste un bloc, les titres « Les ingrédients | La recette » restent au-dessus de leur colonne.
- Foodtruck télécharge le document dans Paperless (version archivée, sinon l'original), le fait lire par le service, puis remet la fiche en forme (App\Support\RecipeScan\LayoutComposer) : publicité, légendes des photos, valeurs nutritionnelles, allergènes, pied de carte et consignes générales écartés ; tableau d'ingrédients des cartes de kits (« nom puis quantité ») remis dans l'ordre ; étapes titrées (« Faire mijoter ») avec leurs paragraphes ; repères « Étape N » même mal lus ; étapes numérotées ; conseil ; temps et nombre de personnes.
- Résultats sur les vrais scans : Croziflette (n° 487) lue sans aucune réserve (« 20 cl » et « sel » bien lus, 5 étapes) ; Orzo aux crevettes (n° 483) : 16 vrais ingrédients (plus aucune phrase publicitaire), 4 étapes titrées dans l'ordre, conseil, source hellofresh.fr. Quantités mal lues réparées et signalées en rouge (« 3208 » → 320 g, « 14 sachet » → 1¼, « 22 cs » → 2½).
- En arrière-plan : « Chercher dans Paperless » enregistre les nouvelles fiches « en cours de lecture » (la liste se recharge toute seule) ; la lecture est faite chaque minute par le planificateur (commande foodtruck:lire-fiches), compter 10 à 40 secondes par page sur la VM.
- Toujours un filet de sécurité : si le service est arrêté ou le scan illisible, la fiche est lue avec le texte de Paperless comme avant, avec la raison affichée ; « Relire la fiche » relance la lecture du scan. Si la lecture du scan comprend moins de choses que le texte de Paperless, c'est ce dernier qui sert. La relecture humaine reste la règle pour toute ligne douteuse.
- foodtruck:check contrôle le service ; foodtruck:lire-fiches --toutes relit d'après le scan toutes les fiches encore à relire (lancé par le script de mise à jour).
- Navigation (corrections promises) : tuile « Ingrédients » dans la page Plus (la tuile des prix devient « Prix par magasin »), lien vers tous les ingrédients depuis la page des prix, bouton « Modifier le nom, le rayon, la saison… » en haut de la fiche d'un ingrédient.
- Migration : recipe_imports.layout, layout_status, layout_error. Nouvelle image Docker foodtruck-ocr:local (construite par le script, environ 150 Mo).
- 242 tests automatisés attendus (nouveaux : LayoutComposerTest, 6 ; RecipeScanLayoutTest, 4).

## v0.14.0 - 2026-10-05 - Tour de tests et corrections, lot 1 : fiches à colonnes mélangées

- Correction signalée par Louis : la fiche Leclerc « Croziflette » (document Paperless n° 487, scan de photocopieuse sans texte, lu par Paperless) remontait sans ingrédients ni étapes (« Liste d'ingrédients introuvable », « Étapes de préparation introuvables »).
- Cause : la reconnaissance de texte de Paperless a mélangé les deux colonnes ligne par ligne (« Les ingrédients La recette » sur une ligne, puis « e 1 reblochon Etape 1 », « e 200 g de lardons la crème fraîche. »…). La carbonara, même mise en page, était sortie colonnes séparées.
- Nouveau séparateur de colonnes (App\Support\RecipeScan\ColumnSplitter), générique : déclenché seulement quand les deux titres sont sur la même ligne. Chaque ligne à puce est coupée entre l'ingrédient et la suite de la recette (repère « Étape », fin d'une phrase commencée à droite, nouvelle phrase) ; les lignes sans puce vont à la recette ; le texte est remis dans l'ordre puis lu normalement. Repères d'étapes mal lus (« Etapat », mot illisible à la place de « Etape 2 ») renumérotés à leur place.
- Résultat sur la Croziflette : 4 personnes, 15 min, 7 ingrédients (reblochon, oignon, 20 cl de crème fraîche, 200 g de lardons, 300 g de crozets, sel, poivre) et les 5 étapes complètes, source mesrecettes.leclerc. Une fiche lue ainsi attend toujours la relecture (réserve « colonnes séparées automatiquement, à vérifier avec le PDF »).
- « 20 ci de crème » : le « ci » est lu comme « cl » (centilitres), ligne signalée en rouge. « sol » (pour « sel ») reste à choisir à la relecture : le choix est appris pour les fiches suivantes.
- Toutes les autres fiches de test (Julie Andrieu, HelloFresh, carbonara) et les tickets donnent exactement la même lecture qu'avant (comparaison avant/après).
- Le script de mise à jour relit les fiches en attente avec les nouvelles règles (foodtruck:relire-recettes) : la Croziflette se remplit toute seule.
- Aucune migration, aucun service ni port en plus.
- 232 tests automatisés attendus (nouveau : ColumnSplitterTest, 6 tests).

## v0.13.2 - 2026-10-05 - Supprimer une fiche Paperless l'efface complètement

- Demande de Louis : après « Supprimer », « Chercher dans Paperless » doit relire la fiche depuis zéro. La v0.13.1 mettait les fiches de côté (elles n'étaient jamais retraitées) : ce n'est plus le cas.
- « Supprimer » (et « Tout supprimer ») efface maintenant la fiche complètement : texte lu, analyse, relecture. Tant que le document porte l'étiquette « recettes » dans Paperless, « Chercher dans Paperless » (ou la synchronisation horaire) la relit depuis le début et elle revient dans « À relire ». Pour qu'un document ne revienne plus, lui retirer l'étiquette « recettes » dans Paperless.
- Les rapprochements d'ingrédients appris (choix faits à la main à la relecture) sont conservés : la fiche relue en profite.
- Toujours refusés avec la raison affichée : recette publiée (à supprimer depuis sa fiche) et brouillon déjà au planning. Un brouillon de recette issu de la fiche est supprimé avec elle.
- Plus de section « Fiches supprimées » ni de bouton « Reprendre » : la migration efface les fiches mises de côté avant cette version, pour qu'elles soient relues à la prochaine recherche.
- Migration : suppression des lignes recipe_imports au statut « ignorée » (aucune colonne modifiée). Aucun service ni port en plus.
- 226 tests automatisés attendus (la v0.13.1 en comptait 226 : un test remplacé, un test de migration ajouté).

## v0.13.1 - 2026-10-05 - Supprimer une fiche Paperless à relire

- Fiches Paperless → « À relire » : chaque fiche a maintenant un bouton « Supprimer », et un bouton « Tout supprimer » vide toute la liste d'un coup (confirmation demandée).
- Supprimer ne revient pas à effacer : la fiche est mise de côté, donc « Chercher dans Paperless » et la synchronisation horaire ne la recréent pas, même si le document reste dans Paperless (et même s'il y est modifié). Pour qu'un document ne soit plus jamais examiné, on peut aussi lui retirer l'étiquette « recettes » dans Paperless.
- Si la fiche avait déjà donné un brouillon de recette, ce brouillon est supprimé avec elle. Jamais supprimés par ce bouton : une recette publiée (à supprimer depuis sa propre fiche) et un brouillon déjà au planning (à retirer d'abord du planning) ; la raison est affichée et la fiche reste.
- La liste « Fiches mises de côté » devient « Fiches supprimées », avec « Reprendre » pour remettre une fiche dans « À relire ». Dans l'écran de relecture, le bouton « Ce n'est pas une recette : l'ignorer » devient « Supprimer cette fiche ».
- Aucune migration, aucun service ni port en plus.
- 226 tests automatisés attendus (4 nouveaux dans RecipeScanFlowTest).

## v0.13.0 - 2026-10-05 - Menu automatique : la semaine proposée dans le budget

- Nouveau bloc « Menu automatique » en haut du planning : « Proposer la semaine » remplit les déjeuners et les dîners libres de la semaine affichée. Les repas déjà prévus (plats, hors maison, notes) sont conservés et comptent dans le budget, les protéines et le quota végétarien. Les jours passés ne sont pas touchés ; les repas où personne n'est à la maison d'après la semaine type sont sautés.
- Restes comptés : un dîner est prévu pour deux repas quand le déjeuner du lendemain est libre (et dans la semaine), les restes sont placés par les règles habituelles du planning (déjeuner du lendemain). Résultat typique : lundi midi + un dîner par soir = 8 plats pour 14 repas.
- Règles de choix (note par recette et par repas, la meilleure l'emporte) : jamais la même protéine deux repas de suite et pas plus de deux fois dans la semaine ; au moins N repas végétariens (réglable, 2 par défaut, mémorisé dans le foyer) atteints avant la fin de la semaine ; plats prêts en 30 minutes ou moins du lundi au vendredi (plats de plus de 45 minutes écartés en semaine, plats difficiles pénalisés) ; recettes de saison favorisées ; pas de plat déjà cuisiné dans les 4 dernières semaines (plus fort sur la dernière semaine) ; favoris légèrement avantagés ; un petit aléa reproductible pour que deux propositions successives ne soient pas identiques.
- Priorité « Équilibre : budget, stock, variété » : chaque repas vise sa part du budget restant de la semaine (budget du foyer moins repas déjà prévus moins plats déjà proposés), les recettes qui utilisent des produits à consommer vite du stock passent en premier, puis celles que le stock couvre déjà. Dépasser le budget est fortement pénalisé ; une recette sans prix connu aussi. Le message de résultat indique le coût estimé et signale un dépassement.
- Semaine à valider, pas imposée : les plats proposés apparaissent en jaune avec la mention « Proposition » et la raison du choix (« 4,20 € pour ce repas · de saison · prêt en 25 min »). Pour chacun : « Garder » (il devient un vrai repas, avec ses restes) ou « Autre idée » (nouvelle recette, restes compris, sans doublon dans la semaine). Pour la semaine : « Valider le menu », « Tout reproposer » (remplace seulement les plats non gardés) et « Effacer ». Modifier un plat proposé avec « modifier » le garde.
- Les propositions n'entrent dans aucune liste de courses, ni dans l'accueil ni dans la suite « Que faire maintenant ? » tant qu'elles ne sont pas gardées ou validées. Elles comptent dans le coût estimé affiché sur le planning.
- Migration : meal_plan_entries.proposed_at et proposal_reason, households.menu_veggy_min (2 par défaut). Aucun service ni port en plus.
- Mises de côté sur demande de Louis : l'affinage du lecteur de fiches Paperless (la v0.12.2 reste à valider).
- 222 tests automatisés attendus (nouveaux : MenuScorerTest, 11 ; MenuAutomatiqueTest, 8).

## v0.12.2 - 2026-10-05 - Fiches imprimées (Leclerc « Pâtes carbonara ») : bruit de reconnaissance de texte

- Troisième format lu, réglé sur un texte Paperless réel : fiche imprimée Leclerc « Pâtes carbonara » (document n° 484, deux colonnes ingrédients / recette, « Etape 1 » à « Etape 6 »).
- Puces mal lues : « e 400 g de spaghetti », « ??Sel », « e ??Poivre » et les « e » ou « | » isolés ne polluent plus les ingrédients ; chaque puce commence un nouvel ingrédient, et une ligne qui commence par une quantité en commence toujours un aussi. Le préfixe « ?? » des lignes d'étapes est retiré.
- Quantité démesurée : « 227100 g de parmesan » (la puce « ?? » lue comme des chiffres) est ramenée à 100 g, et la ligne passe en rouge à la relecture (« le début est sans doute une puce mal lue »). Toute quantité hors normes (plus de 5000 g ou ml, 20 kg ou l, 100 pièces…) est signalée sans être modifiée. Une fiche avec une telle ligne n'est jamais publiée toute seule.
- Bandeau « 4 pers 20 mn » (pictogrammes mal lus autour) : nombre de personnes et temps lus (le temps est rangé en préparation). Le titre qui revient après la liste (page à deux colonnes) et les traits isolés sont ignorés.
- « Etape 6 » seule sur sa ligne, texte après une ligne vide : l'étape n'est plus perdue (c'était la dernière étape de la carte).
- Pied de page « Retrouvez toutes nos recettes sur www.mesrecettes.leclerc » (même en lien markdown) : retiré du texte, l'adresse devient la source de la recette (« mesrecettes.leclerc »).
- Rapprochement : « jaunes d'œufs » et « blancs d'œufs » donnent l'ingrédient « Oeufs » (si le référentiel l'a sous ce nom ; sinon à choisir à la relecture, le choix est appris).
- Les fiches Julie Andrieu et HelloFresh donnent exactement la même lecture qu'avant (comparaison avant/après sur les textes de test).
- Aucune migration, aucun service ni port en plus.
- 203 tests automatisés attendus (nouveaux : PrintedSheetParserTest, et le flux « fiche imprimée avec quantité démesurée » dans RecipeScanFlowTest).

## v0.12.1 - 2026-10-05 - Fiches de kits repas (HelloFresh) scannées dans Paperless

- Nouveau lecteur dédié aux cartes de kits repas HelloFresh (App\Support\RecipeScan\MealKitSheetParser), reconnues à « Ingrédients pour N personnes » avec « Mes ustensiles » et « C'est parti ! » (ou la mention HelloFresh). Réglé sur un exemple réel : Curry thaï léger aux crevettes & coco (document Paperless n° 481, semaine 33 de 2025). Le lecteur des autres formats (Julie Andrieu…) est inchangé : sa sortie est identique.
- Ce que le texte de Paperless a de particulier sur ces cartes : les colonnes de la page sont mélangées (tableau des ingrédients, valeurs nutritionnelles et étapes imprimés côte à côte), les numéros d'étapes sont sur les photos, les fractions (½, ¼) sont perdues. Le lecteur retire le tableau et les valeurs nutritionnelles des lignes d'étapes, sépare les colonnes entrelacées (repère de puce, fin de phrase, sinon équilibre des longueurs), remet les 6 étapes dans l'ordre de la carte avec leur titre (« Chop, chop, chop », « Tout baigne »…) et range « L'astuce du chef » et « Zoom nutrition » à part.
- Ingrédients « nom puis quantité » (Riz 150 g, Échalote 1 pièce(s), Gingembre frais 1 cm) ; sachets et paquets comptés en pièces avec la précision (« sachet », « paquet ») ; bloc « À ajouter vous-même » (huile, sel, poivre) rangé dans son groupe ; ingrédient annoncé dans la légende des photos mais absent du tableau (ligne absorbée par la mise en page : « Lait de coco ») retrouvé avec sa quantité d'après les étapes.
- Fractions perdues réparées et signalées : « % », « # », « Z » devant sachet ou pièce lus ½ ; « 12 cs » lu 1½ cs ; « 4 sachet » lu ¼ sachet ; « avec cc de curry » lu « avec ½ cc de curry » ; mots collés redécoupés (« avecunpetitfilet », « Servezlerizet ») avec un petit lexique. Toute lecture douteuse met la ligne en rouge à la relecture : une fiche de ce format n'est jamais publiée toute seule.
- Temps : « À table dans : 35 - 45 Min » est un temps total, rangé en préparation (45 min). Source : « HelloFresh (semaine 33, 2025) ». Description : sous-titre, temps, ustensiles, conseils.
- Reste à vérifier à la main sur le PDF : une fraction lue comme un chiffre (« 2 cc de curry par personne » au lieu de ½), signalée par une réserve.
- Correctif : le .gitignore excluait tout dossier nommé data/, donc src/database/data/ (138 ingrédients et 24 recettes de départ) n'était pas versionné. Seul /data/ (données MariaDB à la racine) est maintenant exclu ; src/database/data/ est à ajouter au dépôt (git add -A).
- Aucune migration, aucun service ni port en plus.
- 193 tests automatisés (nouveaux : MealKitSheetParserTest, et le flux « fiche de kit repas » dans RecipeScanFlowTest).

## v0.12.0 - 2026-10-05 - Recettes scannées dans Paperless

- Les fiches de recettes déposées dans Paperless avec l'étiquette « recettes » sont lues automatiquement (toutes les heures, ou avec « Chercher dans Paperless ») et transformées en recettes : titre, nombre de personnes, temps, ingrédients (quantité, unité, précision), étapes, conseil, source.
- Lecteur intégré, gratuit, sans IA ni service externe. Réglé sur un premier exemple réel (Julie Andrieu, impression de site à deux colonnes : numéros d'étapes au milieu du bloc, conseil imprimé à côté, ligatures perdues). Cinq mises en page d'étapes reconnues ; chaque nouveau format sera ajouté comme test.
- Rapprochement avec le référentiel : rapprochements appris, synonymes courants, noms identiques ou précisés (« comté 24 mois râpé » → Comté). Prudent : en cas de doute il ne choisit pas (« pâte à tartiner » n'est pas « Pâtes »). « 7 cl de bouillon » devient une fraction de cube.
- Tout reconnu et sans réserve : recette publiée toute seule. Tout reconnu avec une réserve : brouillon à relire. Un ingrédient inconnu : la fiche attend sa relecture.
- Nouvel écran « Fiches Paperless » (Recettes) et relecture dans le formulaire de recette prérempli : lignes à vérifier en rouge, ingrédients proches, lien « Créer cet ingrédient », texte d'origine consultable, « Relire la fiche », « L'ignorer ». Les corrections faites à la main sont retenues pour les fiches suivantes.
- Bandeau « N fiches attendent ta relecture » sur la page Recettes.
- Mon foyer → Avancé : nouveau champ « Étiquette des fiches de recettes ».
- Commandes : foodtruck:recettes (synchronisation, planifiée toutes les heures à :20) et foodtruck:relire-recettes.
- Référentiel : Lait fermenté (ribot), Sauge fraîche, Romarin frais, Thym frais, Menthe fraîche.
- Migration : households.paperless_recipe_tag, recipe_imports, recipe_aliases.
- 183 tests automatisés (nouveaux : RecipeScanParserTest, RecipeScanFlowTest).

## v0.11.0 - 2026-10-05 - Refonte de l'interface

- Nouvelle identité visuelle : fond papier, vert feuille, jaune beurre, rouge tomate ; polices Bricolage Grotesque et Figtree auto-hébergées (dossier public/fonts, aucun appel externe) ; mode sombre automatique.
- Navigation à 5 onglets : Accueil, Menus, Courses, Recettes, Plus. Sur mobile, barre d'onglets en bas. La page « Plus » range Frigo et placards, Tickets de caisse, Prix et ingrédients, Mon foyer, Comment ça marche et Se déconnecter.
- Accueil guidé : « la semaine en 4 étapes » (choisir les repas, préparer la liste, faire les courses, faire le bilan) avec un camion sur l'étape en cours et un gros bouton pour la suivante. Repas du jour, budget de la semaine, dernier bilan et produits à consommer vite en dessous.
- Nouvelle page « Comment ça marche » (/aide) : les 4 étapes et les questions fréquentes.
- Titre et phrase d'explication sur chaque page ; états vides qui expliquent quoi faire (semaine vide, aucune recette trouvée).
- Planning : « Ajouter un repas » demande d'abord quand et quoi ; « Pour qui et combien de repas ? » est replié (par défaut : la semaine type, un repas).
- Courses : « Courses terminées » bien visible, « Options » (recalculer, bilan), ajout d'un article oublié replié.
- Recettes : recherche et filtres rapides (de saison, rapide, favoris), le reste sous « Plus de filtres » ; fiche : « Ajouter au planning » en premier, « Changer les personnes » replié ; liste en lignes compactes sur mobile.
- Mon foyer : personnes en premier, menu de sections en haut, Paperless rangé sous « Avancé ».
- Aucune migration, aucune nouvelle dépendance. Les adresses et les noms de champs existants sont inchangés.
- 162 tests automatisés (nouveau : HomeFlowTest).

## v0.10.0 - 2026-10-05 - Âge des membres

- Chaque personne du foyer peut avoir une date de naissance (Mon foyer et assistant de première connexion) ; facultative pour un adulte.
- Le coefficient de portion suit l'âge, à la date de chaque repas : moins de 1 an 0 ; 1 à 3 ans 0,3 (plat simple à part) ; 3 à 5 ans 0,5 ; 5 à 12 ans 0,7 ; 12 à 15 ans 0,8 ; 15 ans et plus 1. Il change le jour de l'anniversaire, y compris pour les repas déjà planifiés plus tard : planning, quantités des recettes, listes de courses et coûts en tiennent compte.
- « Régler à la main » fixe le coefficient d'une personne (gros mangeur à 1,5). Sans date de naissance, le coefficient saisi reste fixe : rien ne change pour les membres existants tant qu'aucune date n'est renseignée (pense à cocher « Régler à la main » pour garder un coefficient particulier en saisissant une date).
- Âge affiché (« 14 mois », « 7 ans aujourd'hui »), avec la note de la grille (« plat simple à part », « mange comme les adultes »). Le champ « Catégorie » disparaît : elle se déduit de l'âge.
- Migration : colonnes birth_date et coefficient_manual sur household_members.
- 158 tests automatisés.

## v0.9.1 - 2026-10-05 - Économies : bilan, tickets rattachés aux listes, plats à remplacer, reset

- Commande `./ft reset` : remise à zéro du planning, des listes de courses et du stock après des essais. Montre ce qui sera effacé, demande de taper EFFACER, sauvegarde la base dans backups/ avant d'effacer. Comptes, foyers et réglages, recettes, référentiel, tickets et prix sont conservés. `--foyer=N` pour un seul foyer. Jamais lancée par les scripts de mise à jour.
- Un ticket traité est rattaché automatiquement à la liste de courses de sa période (modifiable depuis la fiche du ticket). Les articles retrouvés sur le ticket sont cochés dans la liste (sauf liste déjà classée).
- Nouvelle page « Bilan » d'une liste, ouverte par « Courses terminées » : budget, estimé et payé avec jauge, écart article par article, articles non retrouvés sur les tickets, achats hors liste, stock rangé. Accès aussi depuis la liste, l'historique des listes et la carte « Économies » de l'accueil.
- Prix : alerte « prix qui monte » (+10 % et +10 centimes au moins sur le dernier prix réel du même conditionnement et magasin) et « estimations recalées sur le prix réel ». Les prix des tickets recalaient déjà le référentiel depuis la v0.5.0.
- Planning : à partir de 80 % du budget de la semaine, « Économiser sur la semaine » propose, pour les plats les plus chers encore à cuisiner, des recettes de la même catégorie moins chères pour les mêmes convives, avec un bouton « Remplacer » (jour, créneau, convives et restes conservés).
- Migration : colonne receipts.shopping_list_id.
- 150 tests automatisés.

## v0.9.0 - 2026-10-05 - Stock et anti-gaspi

- Nouvelle page « Stock » : ce qu'il y a déjà à la maison, par lieu (placard, frigo, congélateur), avec quantité, date limite optionnelle et lieu proposé selon l'ingrédient. Ajout, modification et suppression à la main ; virgule décimale acceptée ; chaque foyer ne voit que son stock.
- « À consommer vite » : produits périmés ou à date limite dans 3 jours ou moins, sur la page Stock et en bandeau en haut du Planning.
- « Que cuisiner ? » : recettes classées selon le stock (d'abord celles qui emploient un produit à consommer vite, puis celles dont le plus d'ingrédients sont déjà là), avec ce qu'il reste à acheter et son coût, et un bouton « Ajouter au planning ».
- Liste de courses : le stock est déduit des besoins (« en stock : 300 g », seul le reste est acheté) ; un besoin entièrement couvert passe dans « Déjà en stock », sans achat ni prix ; « Ne pas utiliser le stock » annule la déduction pour un article (« Utiliser le stock » la rétablit). Modifier le stock invite à mettre la liste à jour. Les lots périmés ne comptent pas.
- « Courses terminées » met le stock à jour, une seule fois par liste : le stock utilisé sort (plus proche de la date limite d'abord), le surplus d'emballages des articles cochés entre (40 cl de lait d'une bouteille de 1 L) ; les articles ajoutés à la main et reliés à un ingrédient entrent en entier.
- Accueil : carte « Stock » ; menu : entrée « Stock ».
- Migration : table pantry_items, colonnes stock_base et stock_ignored sur les articles, stock_applied_at sur les listes.
- 130 tests automatisés.
- Reporté en v0.9.1 : rapprochement ticket ↔ liste, plat trop cher à remplacer, bilan de la semaine, alerte de prix qui monte.

## v0.8.0 - 2026-10-03 - Liste de courses

- Nouvelle page « Courses » : liste calculée d'après le planning pour la période choisie (par défaut aujourd'hui + 6 jours, 31 jours au plus). Une seule liste en cours ; les anciennes sont dans l'historique (rouvrir possible).
- Quantités additionnées sur tous les plats, traduites en paquets entiers au meilleur prix : 20 cl + 40 cl de lait → 1 bouteille de 1 L (« il en restera 40 cl »), 7 œufs → une boîte de 12 plutôt que deux de 6. Produits au poids (fruits et légumes) achetés à la quantité utile, arrondie à 50 g.
- Les restes ne sont jamais recomptés ; les repas peuvent être écartés de la liste (invités ailleurs, restaurant).
- Rangement par magasin (principal, puis fruits et légumes chez Morin, puis les autres) et par rayon ; sous-total par magasin, prix en caisse, jauge de budget au prorata des jours, surplus d'emballages et économie possible en achetant ailleurs. Un article se déplace d'un clic (le prix et les paquets sont recalculés pour le nouveau magasin).
- Liste partagée et cochable en direct : une case cochée sur un téléphone apparaît sur les autres en quelques secondes, avec le prénom de la personne ; cases de 44 px pour les courses, « masquer les articles cochés », compteur par magasin.
- Produits de base (sel, huile, farine, épices…) : « À vérifier chez vous », hors budget ; « Il m'en manque » les ajoute aux courses, « Déjà à la maison » fait l'inverse. L'eau n'apparaît pas.
- Ajouts libres (lessive, croquettes…) : un article connu de Foodtruck reprend son rayon et son prix.
- Un recalcul conserve les cases cochées, les magasins et sections choisis et les ajouts manuels ; la liste signale quand le planning a changé depuis son calcul.
- Fiche d'accueil « Liste de courses » et entrée « Courses » dans le menu.
- 118 tests automatisés (dont le calcul des conditionnements).

## v0.7.0 - 2026-09-29 - Planning de la semaine

- Nouvelle page « Planning » : semaine du lundi au dimanche, navigation semaine précédente / suivante, aujourd'hui en évidence ; sur mobile, un jour sous l'autre.
- Créneaux : déjeuner, dîner et « À préparer » (fournées maison : yaourts, cookies, granola…) ; petit-déjeuner et goûter à activer dans Mon foyer → Réglages.
- Semaine type (Mon foyer) : qui mange habituellement à la maison, par jour et par repas (ex. midi en semaine : Marina seule). Chaque repas en part ; convives pré-cochés et modifiables repas par repas ; présences rappelées dans la grille (« 👤 Marina », « personne à la maison »).
- Ajouter un repas : une recette (filtre de recherche, convives, invités, nombre de repas, réglage libre, ou quantité de fournée), un repas hors maison (cantine, chez mamie…) ou une note libre.
- Restes : un plat pour 2 à 4 repas place ses restes sur les déjeuners/dîners libres suivants où quelqu'un mange à la maison (y compris la semaine suivante) ; restes déplaçables, « au congélateur » (liste « Restes mis de côté », à replanifier plus tard) ou retirés ; recalculés si le nombre de repas change, supprimés avec le plat.
- Coût estimé de la semaine (restes comptés une seule fois) et jauge du budget : alerte à 80 %, rouge au-delà.
- Fiche recette : « Ajouter au planning » (jour, repas) avec les réglages en cours.
- Accueil : repas du jour.
- 98 tests automatisés.

## v0.6.0 - 2026-09-29 - Recette proratisée

- Fiche recette : bloc « Pour combien ? ». Par défaut, les parts du foyer (somme des coefficients : 2,5 parts pour Tobilianok 1,5 + Marina 1).
- Qui mange (membres à cocher), invités adultes (1 part) et enfants (0,6 part), nombre de repas (1 à 4 : ce soir + demain midi, une part à congeler), parts par repas en réglage libre.
- Recettes en pots, pièces, parts ou grammes : par fournée (×½, ×1, ×2, ×3 ou quantité saisie, jusqu'à 20 fournées), sans tenir compte du foyer.
- Quantités recalculées avec arrondi pratique (1,875 œuf → 2 pièces, 156 g → 155 g, 0,6 c. à soupe → ½ c. à soupe, 1 250 g → 1,25 kg) ; valeur exacte au survol ; équivalences (« ≈ 125 g ») sur la quantité arrondie.
- Coût estimé du repas et par part, économie « fait maison » ramenée à la quantité préparée.
- Rappel que les quantités écrites dans les étapes sont celles de la recette d'origine ; conseil de cuisson au-delà de ×2.
- Réglages dans l'adresse de la page : lien partageable, bouton « Revenir au foyer ».
- 87 tests automatisés.

## v0.5.2 - 2026-09-29 - Tickets réels de Paperless : Lidl (OCR) et Leclerc Drive

- Lidl lu par OCR dans Paperless : montants « 1,/9 », « 1,7/9 », « @,71 », codes collés « 5,99BT », quantité « 7 », « 71 » ou « | » pour 1, prix unitaire ou total faux d'un chiffre, remises impossibles (« -60,36 »), « EUR/Kkg ». Sur le ticket du 02/10/2025 : 32 articles lus sur 32 (6 en v0.5.1), somme exacte.
- Ligne illisible : montant déduit du total quand c'est la seule (« Pâté de campagne ») ; sinon lignes listées sur la page du ticket. Pesée illisible : aucun prix enregistré. « 31 articles lus sur 32 annoncés ».
- Bon de commande Leclerc Drive : lecteur dédié (quantité, prix unitaire, total), rubriques non alimentaires ignorées d'office, économies de lot réparties, anti-gaspi marqué promo, total payé hors avoir, date de commande.
- Rapprochement : produits transformés non confondus avec leur ingrédient (compote ≠ pomme, croûtons ≠ ail…), poire ≠ poireau, article à la pièce jamais au prix du vrac au kilo (conditionnement « Pièce (ticket) »).
- Nouveau : « Créer l'ingrédient (nom saisi) » sur une ligne de ticket, avec choix du rayon ; conditionnement et prix créés d'après le ticket.
- Correctif : un ticket ne passe plus en « traité » quand un nom tapé n'existe pas dans le référentiel.
- « Relire le ticket » aussi sur un ticket traité, sans doubler les prix ; foodtruck:reparse --tout.
- Paperless : champ jeton non rempli par les gestionnaires de mots de passe, « Token » et espaces retirés, fin du jeton affichée ; messages d'erreur précis (jeton refusé, droit manquant sur les étiquettes/documents/correspondants).
- 79 tests automatisés, dont les textes Paperless réels (tests/Fixtures/paperless).

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
