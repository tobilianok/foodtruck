<?php

namespace App\Support\RecipeScan;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Client du service interne foodtruck-ocr (Tesseract + découpage de la page par position).
 * Adresse dans config('foodtruck.ocr_url') ; sans adresse, la lecture par position est désactivée.
 */
class OcrClient
{
    public function __construct(private readonly ?string $baseUrl = null)
    {
    }

    public static function make(): self
    {
        return new self(config('foodtruck.ocr_url'));
    }

    public static function enabled(): bool
    {
        return filled(config('foodtruck.ocr_url'));
    }

    /** @return array{version: int, pages: array<int, array{width: int, height: int, rotation: int, blocks: array}>} */
    public function read(string $body, string $mime): array
    {
        try {
            $response = Http::timeout(600)->connectTimeout(5)->withBody($body, $mime ?: 'application/octet-stream')
                ->post($this->url('/lire'));
        } catch (\Throwable $e) {
            throw new RuntimeException('Service de lecture injoignable : '.Str::limit($e->getMessage(), 160));
        }

        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ! isset($json['pages'])) {
            throw new RuntimeException('Lecture du scan impossible : '.Str::limit((string) ($json['erreur'] ?? 'HTTP '.$response->status()), 200));
        }

        return $json;
    }

    /** @return array{ok: bool, tesseract?: string, langues?: array<int, string>} */
    public function health(): array
    {
        try {
            $json = Http::timeout(20)->connectTimeout(5)->get($this->url('/sante'))->json();
        } catch (\Throwable $e) {
            throw new RuntimeException('Service de lecture injoignable : '.Str::limit($e->getMessage(), 160));
        }

        return is_array($json) ? $json : ['ok' => false];
    }

    private function url(string $path): string
    {
        if (blank($this->baseUrl)) {
            throw new RuntimeException('Lecture des scans désactivée (FOODTRUCK_OCR_URL vide).');
        }

        return rtrim((string) $this->baseUrl, '/').$path;
    }
}
