<?php

namespace App\Support\RecipeScan;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v0.18.0 : lecture d'une fiche de recette par un modèle de vision (Ollama sur le PC de Louis, Radeon RX 6800,
 * qwen3-vl:8b-instruct-q8_0 : environ une minute par fiche). Le modèle reçoit les images des pages et rend la
 * recette dans un JSON imposé (schema()) ; VisionComposer la remet en texte pour le lecteur habituel.
 *
 * Réglages validés sur la fiche Salade façon piémontaise (2026-10-06) : version « instruct » sans réflexion (la
 * version « thinking » réfléchissait des heures), réglages recommandés par Qwen (température basse mais pas nulle :
 * le décodage glouton fait boucler le modèle), réponse plafonnée à 4096 jetons, 200 dpi. L'avancement est remonté
 * par $progress (envoi des pages, lecture des images, écriture).
 */
class VisionClient
{
    public const PROMPT = <<<'TXT'
Voici une fiche de recette scannée (une image par page). Recopie la recette exactement telle qu'elle est imprimée, sans rien inventer, sans rien reformuler, sans rien corriger.

- titre : le titre principal de la recette seulement (la grande ligne de titre), sans la ligne de sous-titre.
- personnes : le nombre de personnes du tableau d'ingrédients (null s'il n'y en a pas).
- temps_minutes : le temps total ou de préparation imprimé, en minutes (la valeur haute d'un intervalle « 30 - 40 min ») ; null s'il n'y en a pas.
- ingredients : chaque ligne du tableau d'ingrédients, dans l'ordre, sans en oublier aucune.
  - nom : le nom tel qu'imprimé, sans l'astérisque.
  - quantite : un nombre décimal (½ = 0.5, ¼ = 0.25, ⅓ = 0.333, ⅔ = 0.667, ¾ = 0.75, 1½ = 1.5) ; null si « selon votre goût » ou sans quantité.
  - unite : l'unité telle qu'imprimée, au singulier (g, kg, ml, cl, cc, cs, sachet, pièce, tranche, paquet, botte, gousse, pot, boîte…) ; null s'il n'y en a pas.
  - precision : le reste de la ligne s'il y en a (« ou de cidre », « selon votre goût ») ; sinon null.
  - groupe : l'intertitre au-dessus de la ligne s'il y en a un (« À ajouter vous-même ») ; sinon null.
  - doute : true si un caractère de la ligne est difficile à lire (fraction, chiffre), sinon false.
- etapes : chaque étape dans l'ordre de leur numéro (1, 2, 3…), même si elles sont disposées en grille ; titre de l'étape s'il y en a un, et texte complet de l'étape (toutes ses puces). Les encadrés « L'astuce du chef » ne font pas partie des étapes.
- conseil : le texte des encadrés « L'astuce du chef » ou des conseils, sinon null.
- site : l'adresse du site imprimée sur la fiche (« www.hellofresh.fr »), sinon null.
- photo : la grande photo du plat terminé (pas les petites photos des étapes ni les pictogrammes) : numéro de la page (1, 2…) et rectangle qui l'entoure exactement, en coordonnées relatives de 0 à 1000 (x1, y1 = coin en haut à gauche ; x2, y2 = coin en bas à droite) ; null s'il n'y a pas de photo du plat.

Ignore les valeurs nutritionnelles, les allergènes, les ustensiles, les légendes des photos, les pictogrammes et le pied de page.
TXT;

    /**
     * v0.19.0 : consigne des tickets de caisse, validée par l'essai du 7 octobre 2026 sur les 6 tickets de Louis
     * (Leclerc Drive et Lidl Plus : les 6 totaux justes au centime avec les règles du code). Le modèle recopie ; c'est le code
     * (Receipts\VisionReceiptParser) qui interprète et contrôle : pesées, poids, remises, total.
     */
    public const RECEIPT_PROMPT = <<<'TXT'
Voici un ticket de caisse (ou un bon de commande de drive). Un ticket long est envoyé en plusieurs images : ce sont des morceaux successifs du même ticket, de haut en bas, coupés entre deux lignes (aucune ligne n'est répétée d'une image à l'autre). Recopie-le exactement tel qu'il est imprimé, sans rien inventer, sans rien calculer, sans rien corriger.

- magasin : l'enseigne (et la ville si elle est imprimée).
- date_imprimee : la date d'achat recopiée exactement telle qu'imprimée (« 24.09.26 », « 02/10/2025 »), sans la convertir ; sinon null.
- lignes : chaque article et chaque remise, dans l'ordre, sans en oublier aucun.
  - libelle : le libellé complet tel qu'imprimé (s'il continue sur la ligne suivante, recolle-le).
  - quantite : le nombre d'articles (colonne « Qté » s'il y en a une), ou le poids pour un produit pesé ; 1 s'il n'y a rien d'imprimé.
  - unite : « kg » (ou « g », « l ») seulement si le ticket imprime un poids pesé, du type « 1,420 kg x 1,99 EUR/kg » ; sinon « pièce », même si le libellé contient un poids ou un volume (« Carottes sachet 1 kg », « Escalopes 600 g » : 1 pièce).
  - prix_unitaire : le prix à l'unité ou au kilo s'il est imprimé (colonne « P.U. », ou « x 1,99 EUR/kg »), recopié en texte avec la virgule (« 1,99 ») ; sinon null.
  - prix : le montant de la ligne recopié en texte exactement comme imprimé, avec la virgule et les deux chiffres après la virgule (« 2,83 »), précédé de « - » pour une remise (« -0,71 »), sans la lettre de TVA (A, B, T).
  - remise : true pour une remise, une réduction, un « Prix en baisse » ou un bon d'achat ; sinon false.
  - detail : la ligne de détail imprimée juste sous l'article, recopiée telle quelle (« 1,420 kg x 1,99 EUR/kg », « 3 x 0,89 ») ; sinon null.
  - doute : true si un chiffre de la ligne est difficile à lire, sinon false.
  Une ligne de détail fait partie de l'article du dessus : recopie-la dans son champ « detail », jamais comme une ligne à part.
- total : le montant total à payer (« A payer », « Total », « Montant TTC »), recopié en texte (« 109,09 »).

Ignore tout le reste : le nombre de lignes, les sous-totaux, les moyens de paiement, le rendu de monnaie, le récapitulatif de TVA, le « Total Promotion », les économies réalisées, les points et offres de fidélité, le code-barres, les messages publicitaires et les mentions légales.
TXT;

    /** Réglages des tickets : jusqu'à ~12 000 jetons d'image (3 pages A4) et des réponses longues (drive de 60 articles). */
    private const RECEIPT_OPTIONS = ['num_ctx' => 24576, 'num_predict' => 8192];

    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly string $model = 'qwen3-vl:8b-instruct-q8_0',
    ) {
    }

    public static function make(): self
    {
        return new self(config('foodtruck.vision_url'), (string) config('foodtruck.vision_model'));
    }

    public static function enabled(): bool
    {
        return filled(config('foodtruck.vision_url'));
    }

    /** Lecture des fiches par le modèle possible : modèle et préparation des pages configurés. */
    public static function ready(): bool
    {
        return self::enabled() && PagesClient::enabled();
    }

    public function model(): string
    {
        return $this->model;
    }

    /** JSON imposé au modèle (sortie structurée d'Ollama). */
    public static function schema(): array
    {
        $nullable = fn (string $type) => ['anyOf' => [['type' => $type], ['type' => 'null']]];

        return [
            'type' => 'object',
            'properties' => [
                'titre' => ['type' => 'string'],
                'personnes' => $nullable('integer'),
                'temps_minutes' => $nullable('integer'),
                'ingredients' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'nom' => ['type' => 'string'], 'quantite' => $nullable('number'), 'unite' => $nullable('string'),
                        'precision' => $nullable('string'), 'groupe' => $nullable('string'), 'doute' => ['type' => 'boolean'],
                    ],
                    'required' => ['nom', 'quantite', 'unite', 'precision', 'groupe', 'doute'],
                ]],
                'etapes' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['titre' => $nullable('string'), 'texte' => ['type' => 'string']],
                    'required' => ['titre', 'texte'],
                ]],
                'conseil' => $nullable('string'),
                'site' => $nullable('string'),
                'photo' => ['anyOf' => [['type' => 'null'], ['type' => 'object', 'properties' => [
                    'page' => ['type' => 'integer'], 'x1' => ['type' => 'integer'], 'y1' => ['type' => 'integer'], 'x2' => ['type' => 'integer'], 'y2' => ['type' => 'integer'],
                ], 'required' => ['page', 'x1', 'y1', 'x2', 'y2']]]],
            ],
            'required' => ['titre', 'personnes', 'temps_minutes', 'ingredients', 'etapes', 'conseil', 'site', 'photo'],
        ];
    }

    /** v0.19.0 : JSON imposé pour un ticket de caisse (les montants sont du texte, convertis par le code). */
    public static function receiptSchema(): array
    {
        $nullable = fn (string $type) => ['anyOf' => [['type' => $type], ['type' => 'null']]];

        return [
            'type' => 'object',
            'properties' => [
                'magasin' => ['type' => 'string'],
                'date_imprimee' => $nullable('string'),
                'lignes' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'libelle' => ['type' => 'string'], 'quantite' => ['type' => 'number'], 'unite' => ['type' => 'string'],
                        'prix_unitaire' => $nullable('string'), 'prix' => ['type' => 'string'], 'remise' => ['type' => 'boolean'],
                        'detail' => $nullable('string'), 'doute' => ['type' => 'boolean'],
                    ],
                    'required' => ['libelle', 'quantite', 'unite', 'prix_unitaire', 'prix', 'remise', 'detail', 'doute'],
                ]],
                'total' => $nullable('string'),
            ],
            'required' => ['magasin', 'date_imprimee', 'lignes', 'total'],
        ];
    }

    /**
     * @param  array<int, string>  $pages  images PNG en base64
     * @param  callable(array{elapsed: float, sent: float, received: int, receiving: bool}): void|null  $progress
     * @return array{recette: array, modele: string, secondes: int, attente: int, octets: int, jetons_lus: ?int, jetons_ecrits: ?int}
     */
    public function read(array $pages, ?callable $progress = null): array
    {
        $answer = $this->chat(self::PROMPT, self::schema(), $pages, $progress);
        $recipe = $answer['json'];
        if (! is_array($recipe) || ! isset($recipe['ingredients'], $recipe['etapes']) || ! is_array($recipe['ingredients'])) {
            throw new RuntimeException('Réponse du modèle incomplète ou illisible ('.Str::limit($answer['content'], 80).').');
        }
        if ($recipe['ingredients'] === [] && $recipe['etapes'] === []) {
            throw new RuntimeException('Le modèle n\'a trouvé ni ingrédient ni étape sur cette fiche.');
        }

        return ['recette' => $recipe] + $answer['meta'];
    }

    /**
     * v0.19.0 : lecture d'un ticket de caisse (images de foodtruck-pages en mode ticket).
     *
     * @param  array<int, string>  $images  images PNG en base64 (pages ou morceaux successifs du ticket)
     * @return array{ticket: array, modele: string, secondes: int, attente: int, octets: int, jetons_lus: ?int, jetons_ecrits: ?int}
     */
    public function readReceipt(array $images, ?callable $progress = null): array
    {
        $answer = $this->chat(self::RECEIPT_PROMPT, self::receiptSchema(), $images, $progress, self::RECEIPT_OPTIONS);
        $ticket = $answer['json'];
        if (! is_array($ticket) || ! isset($ticket['lignes']) || ! is_array($ticket['lignes'])) {
            throw new RuntimeException('Réponse du modèle incomplète ou illisible ('.Str::limit($answer['content'], 80).').');
        }
        if ($ticket['lignes'] === []) {
            throw new RuntimeException('Le modèle n\'a trouvé aucun article sur ce ticket.');
        }

        return ['ticket' => $ticket] + $answer['meta'];
    }

    /**
     * Échange avec Ollama (/api/chat en flux, sortie structurée) : texte recollé, JSON décodé et compteurs.
     *
     * @return array{content: string, json: mixed, meta: array{modele: string, secondes: int, attente: int, octets: int, jetons_lus: ?int, jetons_ecrits: ?int}}
     */
    private function chat(string $prompt, array $schema, array $pages, ?callable $progress = null, array $modelOptions = []): array
    {
        if ($pages === []) {
            throw new RuntimeException('Aucune page à lire.');
        }

        $started = microtime(true);
        $firstByte = null;
        $options = ['progress' => function ($downloadTotal, $downloaded, $uploadTotal, $uploaded) use ($progress, $started, &$firstByte) {
            if ($downloaded > 0 && $firstByte === null) {
                $firstByte = microtime(true);
            }
            if ($progress) {
                $progress([
                    'elapsed' => microtime(true) - $started,
                    'sent' => $uploadTotal > 0 ? min(1, $uploaded / $uploadTotal) : 0.0,
                    'received' => (int) $downloaded,
                    'receiving' => $firstByte !== null,
                    'waited' => ($firstByte ?? microtime(true)) - $started,
                ]);
            }
        }];

        try {
            $response = Http::timeout(1800)->connectTimeout(5)->withOptions($options)->post($this->url('/api/chat'), [
                'model' => $this->model,
                'messages' => [['role' => 'user', 'content' => $prompt, 'images' => array_values($pages)]],
                'format' => $schema,
                'stream' => true,
                // Recopie pure : pas de « réflexion » préalable (activée par défaut sur qwen3-vl, elle durait des heures sur le processeur)
                'think' => false,
                'options' => $modelOptions + ['temperature' => 0.2, 'top_p' => 0.8, 'top_k' => 20, 'repeat_penalty' => 1.05, 'num_ctx' => 16384, 'num_predict' => 4096],
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Délai de connexion dépassé ou refus : PC éteint ou Ollama arrêté
            if (str_contains($e->getMessage(), 'Connection refused') || str_contains($e->getMessage(), 'Failed to connect')
                || str_contains($e->getMessage(), 'No route to host') || str_contains($e->getMessage(), 'Connection timed out after')
                || str_contains($e->getMessage(), 'Could not resolve host')) {
                throw new VisionUnavailable('Ollama injoignable ('.$this->baseUrl.') : PC éteint ou Ollama arrêté');
            }
            throw new RuntimeException('Lecture par le modèle interrompue : '.Str::limit($e->getMessage(), 160));
        } catch (\Throwable $e) {
            throw new RuntimeException('Lecture par le modèle interrompue : '.Str::limit($e->getMessage(), 160));
        }

        if (! $response->successful()) {
            $error = json_decode(strtok($response->body(), "\n") ?: '', true);
            throw new RuntimeException('Lecture par le modèle impossible : '.Str::limit((string) ($error['error'] ?? 'HTTP '.$response->status()), 200));
        }

        // Réponse en flux (une ligne JSON par morceau) : texte recollé, compteurs dans la dernière ligne
        $content = '';
        $last = [];
        foreach (preg_split('/\r?\n/', $response->body()) as $line) {
            $chunk = json_decode(trim($line), true);
            if (! is_array($chunk)) {
                continue;
            }
            if (isset($chunk['error'])) {
                throw new RuntimeException('Lecture par le modèle impossible : '.Str::limit((string) $chunk['error'], 200));
            }
            $content .= $chunk['message']['content'] ?? '';
            if (! empty($chunk['done'])) {
                $last = $chunk;
            }
        }

        if (($last['done_reason'] ?? null) === 'length') {
            throw new RuntimeException('Réponse du modèle coupée (trop longue) : document trop chargé pour une seule lecture.');
        }

        return ['content' => $content, 'json' => json_decode($content, true), 'meta' => [
            'modele' => $this->model,
            'secondes' => (int) round(microtime(true) - $started),
            'attente' => (int) round(($firstByte ?? microtime(true)) - $started),
            'octets' => strlen($response->body()),
            'jetons_lus' => $last['prompt_eval_count'] ?? null,
            'jetons_ecrits' => $last['eval_count'] ?? null,
        ]];
    }

    /** @return array{ok: bool, version?: string, modeles?: array<int, string>} */
    public function health(): array
    {
        try {
            $version = Http::timeout(10)->connectTimeout(5)->get($this->url('/api/version'))->json('version');
            $models = collect(Http::timeout(10)->connectTimeout(5)->get($this->url('/api/tags'))->json('models') ?? [])->pluck('name')->all();
        } catch (\Throwable $e) {
            throw new VisionUnavailable('Ollama injoignable ('.$this->baseUrl.') : PC éteint ou Ollama arrêté');
        }

        return ['ok' => in_array($this->model, $models, true), 'version' => (string) $version, 'modeles' => $models];
    }

    private function url(string $path): string
    {
        if (blank($this->baseUrl)) {
            throw new RuntimeException('Lecture par le modèle de vision désactivée (FOODTRUCK_VISION_URL vide).');
        }

        return rtrim((string) $this->baseUrl, '/').$path;
    }
}
