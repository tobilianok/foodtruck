<?php

namespace App\Support\Receipts;

use App\Models\Household;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Client minimal de l'API REST de Paperless-ngx (lecture seule, jeton d'API).
 */
class PaperlessClient
{
    public const MAX_DOCUMENTS = 500;

    public function __construct(private readonly string $baseUrl, private readonly string $token)
    {
    }

    /** Jeton tel qu'il a pu être collé : « Token abc… », espaces, retour à la ligne. */
    public static function cleanToken(?string $token): ?string
    {
        $token = preg_replace('/^\s*(?:Authorization\s*:\s*)?Token\s+/i', '', (string) $token);
        $token = preg_replace('/\s+/u', '', $token);

        return $token === '' ? null : $token;
    }

    public static function for(Household $household): self
    {
        if (! $household->hasPaperless()) {
            throw new RuntimeException('Paperless n\'est pas configuré pour ce foyer.');
        }

        return new self((string) $household->paperless_url, (string) $household->paperless_token);
    }

    /** Vérifie l'accès et l'étiquette. @return array{tag_id: int, documents: int} */
    public function check(string $tag): array
    {
        $tagId = $this->tagId($tag);
        $count = (int) $this->get('/api/documents/', ['tags__id__all' => $tagId, 'page_size' => 1])['count'];

        return ['tag_id' => $tagId, 'documents' => $count];
    }

    public function tagId(string $tag): int
    {
        $results = $this->get('/api/tags/', ['name__iexact' => $tag, 'page_size' => 5])['results'] ?? [];

        if ($results === []) {
            throw new RuntimeException("Étiquette « {$tag} » introuvable dans Paperless (ou invisible pour ce compte).");
        }

        return (int) $results[0]['id'];
    }

    /** Documents portant l'étiquette, du plus récemment modifié au plus ancien. */
    public function documents(int $tagId): array
    {
        $documents = [];
        $params = ['tags__id__all' => $tagId, 'page_size' => 100, 'ordering' => '-modified', 'page' => 1];

        do {
            $page = $this->get('/api/documents/', $params);
            array_push($documents, ...($page['results'] ?? []));
            $params['page']++;
        } while (! empty($page['next']) && count($documents) < self::MAX_DOCUMENTS);

        return array_slice($documents, 0, self::MAX_DOCUMENTS);
    }

    /** @return array<int, string> identifiant => nom (vide si le compte n'y a pas accès) */
    public function correspondents(): array
    {
        try {
            $results = $this->get('/api/correspondents/', ['page_size' => 1000])['results'] ?? [];
        } catch (RuntimeException) {
            return [];
        }

        return collect($results)->mapWithKeys(fn ($c) => [(int) $c['id'] => (string) $c['name']])->all();
    }

    /**
     * Fichier original d'un document (le scan tel qu'envoyé par la photocopieuse).
     *
     * v0.15.3 : plus la version archivée de Paperless : sa conversion en PDF/A recompresse les images (JPEG plus
     * dégradé), et la lecture des petits caractères en souffre (mots collés « surfeumoyenavecunpetitfilet »,
     * titres d'étape perdus). L'original est toujours disponible dans Paperless.
     *
     * @return array{body: string, mime: string}
     */
    public function download(int $documentId): array
    {
        try {
            $response = Http::timeout(60)->connectTimeout(5)->withoutRedirecting()
                ->withHeaders(['Authorization' => 'Token '.$this->token, 'Accept' => '*/*'])
                ->get(rtrim($this->baseUrl, '/')."/api/documents/{$documentId}/download/", ['original' => 'true']);
        } catch (\Throwable $e) {
            throw new RuntimeException('Paperless injoignable : '.Str::limit($e->getMessage(), 160));
        }

        if (in_array($response->status(), [401, 403, 404], true)) {
            throw new RuntimeException("Le document n° {$documentId} n'a pas pu être téléchargé depuis Paperless (HTTP {$response->status()}).");
        }
        if (! $response->successful() || $response->body() === '') {
            throw new RuntimeException('Téléchargement impossible depuis Paperless (HTTP '.$response->status().').');
        }

        return ['body' => $response->body(), 'mime' => strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0])) ?: 'application/octet-stream'];
    }

    private function get(string $path, array $query = []): array
    {
        try {
            $response = $this->http()->get(rtrim($this->baseUrl, '/').$path, $query);
        } catch (\Throwable $e) {
            throw new RuntimeException('Paperless injoignable : '.Str::limit($e->getMessage(), 160));
        }

        if ($response->status() === 401) {
            throw new RuntimeException('Paperless refuse le jeton : il est invalide ou a été supprimé (recrée-le avec drf_create_token).');
        }

        if ($response->status() === 403) {
            $what = match (true) {
                str_starts_with($path, '/api/tags') => 'les étiquettes',
                str_starts_with($path, '/api/correspondents') => 'les correspondants',
                default => 'les documents',
            };

            throw new RuntimeException("Le compte Paperless n'a pas le droit d'afficher {$what} : donne-lui la permission « Afficher » correspondante.");
        }

        if ($response->status() === 302 || $response->redirect()) {
            throw new RuntimeException('Paperless redirige vers sa page de connexion : vérifie l\'adresse (http://IP:port, sans passer par le portail Authentik).');
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('Réponse inattendue de Paperless (HTTP '.$response->status().').');
        }

        return $response->json();
    }

    private function http(): PendingRequest
    {
        return Http::timeout(20)->connectTimeout(5)->acceptJson()->withoutRedirecting()
            ->withHeaders(['Authorization' => 'Token '.$this->token]);
    }
}
