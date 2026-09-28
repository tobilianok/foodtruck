<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client OpenID Connect minimal pour Authentik
 * (flux "authorization code" avec PKCE, client confidentiel).
 */
class OidcClient
{
    /** Configuration publiée par Authentik (.well-known), mise en cache 1 h. */
    public function discovery(): array
    {
        $issuer = (string) config('oidc.issuer');

        if ($issuer === '' || $issuer === '/' || ! config('oidc.client_id') || ! config('oidc.client_secret')) {
            throw new RuntimeException('Configuration OIDC incomplète : OIDC_ISSUER, OIDC_CLIENT_ID et OIDC_CLIENT_SECRET doivent être renseignés dans src/.env.');
        }

        return Cache::remember('oidc.discovery', now()->addHour(), function () use ($issuer) {
            $response = Http::timeout(10)->acceptJson()->get($issuer.'.well-known/openid-configuration');
            $response->throw();

            $config = $response->json();

            foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $key) {
                if (empty($config[$key])) {
                    throw new RuntimeException("Découverte OIDC incomplète : clé {$key} absente.");
                }
            }

            return $config;
        });
    }

    public function authorizationUrl(string $state, string $nonce, string $codeChallenge): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('oidc.client_id'),
            'redirect_uri' => config('oidc.redirect'),
            'scope' => config('oidc.scopes'),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->discovery()['authorization_endpoint'].'?'.$query;
    }

    /** Échange le code d'autorisation contre les jetons. */
    public function exchangeCode(string $code, string $verifier): array
    {
        $response = Http::timeout(10)->asForm()->acceptJson()->post($this->discovery()['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('oidc.redirect'),
            'client_id' => config('oidc.client_id'),
            'client_secret' => config('oidc.client_secret'),
            'code_verifier' => $verifier,
        ]);
        $response->throw();

        $tokens = $response->json();

        if (empty($tokens['access_token']) || empty($tokens['id_token'])) {
            throw new RuntimeException('Réponse du serveur de jetons incomplète.');
        }

        return $tokens;
    }

    /**
     * Contrôle des revendications de l'id_token. Le jeton est reçu directement
     * d'Authentik en HTTPS avec authentification du client : on vérifie
     * l'émetteur, l'audience, le nonce et l'expiration.
     */
    public function validateIdToken(string $idToken, string $nonce): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new RuntimeException('id_token mal formé.');
        }

        $claims = json_decode($this->base64UrlDecode($parts[1]), true);
        if (! is_array($claims) || empty($claims['sub'])) {
            throw new RuntimeException('id_token illisible.');
        }

        if (($claims['iss'] ?? null) !== $this->discovery()['issuer']) {
            throw new RuntimeException('Émetteur de l\'id_token inattendu.');
        }

        if (! in_array(config('oidc.client_id'), (array) ($claims['aud'] ?? []), true)) {
            throw new RuntimeException('Audience de l\'id_token inattendue.');
        }

        if (! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new RuntimeException('Nonce de l\'id_token invalide.');
        }

        if ((int) ($claims['exp'] ?? 0) < time() - 60) {
            throw new RuntimeException('id_token expiré.');
        }

        return $claims;
    }

    public function userInfo(string $accessToken): array
    {
        $response = Http::timeout(10)->acceptJson()->withToken($accessToken)->get($this->discovery()['userinfo_endpoint']);
        $response->throw();

        return $response->json();
    }

    public function logoutUrl(): ?string
    {
        return $this->discovery()['end_session_endpoint'] ?? null;
    }

    private function base64UrlDecode(string $value): string
    {
        $value = strtr($value, '-_', '+/');

        return (string) base64_decode($value.str_repeat('=', (4 - strlen($value) % 4) % 4));
    }
}
