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

    /**
     * @param  array<int, string>  $pages  images PNG en base64
     * @param  callable(array{elapsed: float, sent: float, received: int, receiving: bool}): void|null  $progress
     * @return array{recette: array, modele: string, secondes: int, attente: int, octets: int, jetons_lus: ?int, jetons_ecrits: ?int}
     */
    public function read(array $pages, ?callable $progress = null): array
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
                'messages' => [['role' => 'user', 'content' => self::PROMPT, 'images' => array_values($pages)]],
                'format' => self::schema(),
                'stream' => true,
                // Recopie pure : pas de « réflexion » préalable (activée par défaut sur qwen3-vl, elle durait des heures sur le processeur)
                'think' => false,
                'options' => ['temperature' => 0.2, 'top_p' => 0.8, 'top_k' => 20, 'repeat_penalty' => 1.05, 'num_ctx' => 16384, 'num_predict' => 4096],
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

        $recipe = json_decode($content, true);
        if (! is_array($recipe) || ! isset($recipe['ingredients'], $recipe['etapes']) || ! is_array($recipe['ingredients'])) {
            throw new RuntimeException('Réponse du modèle incomplète ou illisible ('.Str::limit($content, 80).').');
        }
        if ($recipe['ingredients'] === [] && $recipe['etapes'] === []) {
            throw new RuntimeException('Le modèle n\'a trouvé ni ingrédient ni étape sur cette fiche.');
        }

        return [
            'recette' => $recipe,
            'modele' => $this->model,
            'secondes' => (int) round(microtime(true) - $started),
            'attente' => (int) round(($firstByte ?? microtime(true)) - $started),
            'octets' => strlen($response->body()),
            'jetons_lus' => $last['prompt_eval_count'] ?? null,
            'jetons_ecrits' => $last['eval_count'] ?? null,
        ];
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
