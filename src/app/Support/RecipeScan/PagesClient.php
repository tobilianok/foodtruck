<?php

namespace App\Support\RecipeScan;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v0.18.0 : client du service interne foodtruck-pages (Poppler + Pillow) qui transforme le fichier d'une fiche
 * (PDF ou photo) en images pour le modèle de vision. Aucune reconnaissance de texte : c'est le modèle qui lit.
 */
class PagesClient
{
    public function __construct(private readonly ?string $baseUrl = null)
    {
    }

    public static function make(): self
    {
        return new self(config('foodtruck.pages_url'));
    }

    public static function enabled(): bool
    {
        return filled(config('foodtruck.pages_url'));
    }

    /**
     * Pages du document en images couleur (PNG en base64), 4 au plus, redressées d'après leurs données EXIF.
     * v0.19.0 : $ticket = true, un ticket long et étroit est découpé en morceaux successifs lisibles (16 au plus).
     *
     * @return array<int, string>
     */
    public function pages(string $body, string $mime, int $dpi = 200, bool $ticket = false): array
    {
        try {
            $response = Http::timeout(300)->connectTimeout(5)->withBody($body, $mime ?: 'application/octet-stream')
                ->withHeaders(['X-Dpi' => (string) $dpi] + ($ticket ? ['X-Mode' => 'ticket'] : []))->post($this->url('/pages'));
        } catch (\Throwable $e) {
            throw new RuntimeException('Service foodtruck-pages injoignable : '.Str::limit($e->getMessage(), 160));
        }

        $pages = $response->json('pages');
        if (! $response->successful() || ! is_array($pages) || $pages === []) {
            throw new RuntimeException('Préparation des pages impossible : '.Str::limit((string) ($response->json('erreur') ?? 'HTTP '.$response->status()), 200));
        }

        return array_values(array_filter($pages, 'is_string'));
    }

    /** @return array{ok: bool, poppler?: string} */
    public function health(): array
    {
        try {
            $json = Http::timeout(20)->connectTimeout(5)->get($this->url('/sante'))->json();
        } catch (\Throwable $e) {
            throw new RuntimeException('Service foodtruck-pages injoignable : '.Str::limit($e->getMessage(), 160));
        }

        return is_array($json) ? $json : ['ok' => false];
    }

    private function url(string $path): string
    {
        if (blank($this->baseUrl)) {
            throw new RuntimeException('Préparation des pages désactivée (FOODTRUCK_PAGES_URL vide).');
        }

        return rtrim((string) $this->baseUrl, '/').$path;
    }
}
