<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OidcClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class OidcController extends Controller
{
    /** Envoie l'utilisateur vers la page de connexion Authentik. */
    public function redirect(Request $request, OidcClient $oidc)
    {
        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $request->session()->put('oidc', compact('state', 'nonce', 'verifier'));

        try {
            $url = $oidc->authorizationUrl($state, $nonce, $challenge);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Le service de connexion est injoignable pour le moment.', 503);
        }

        return redirect()->away($url);
    }

    /** Retour d'Authentik : échange du code, création ou mise à jour du compte. */
    public function callback(Request $request, OidcClient $oidc)
    {
        $saved = $request->session()->pull('oidc');

        if ($request->filled('error')) {
            return $this->error('Connexion refusée par Authentik : '.$request->query('error_description', $request->query('error')), 403);
        }

        if (! is_array($saved) || ! hash_equals($saved['state'], (string) $request->query('state')) || ! $request->filled('code')) {
            return $this->error('La demande de connexion a expiré ou est invalide. Réessaie.', 400);
        }

        try {
            $tokens = $oidc->exchangeCode((string) $request->query('code'), $saved['verifier']);
            $claims = $oidc->validateIdToken($tokens['id_token'], $saved['nonce']);
            $info = $oidc->userInfo($tokens['access_token']);

            if (($info['sub'] ?? null) !== $claims['sub']) {
                return $this->error('Identité incohérente renvoyée par Authentik.', 403);
            }

            $user = DB::transaction(function () use ($info) {
                $user = User::firstOrNew(['authentik_sub' => $info['sub']]);
                $user->name = $info['name'] ?? $info['preferred_username'] ?? 'Utilisateur';
                $user->username = $info['preferred_username'] ?? null;
                $user->email = $info['email'] ?? $info['sub'].'@authentik.invalid';

                if (! $user->exists) {
                    // Le tout premier compte créé devient administrateur.
                    $user->role = User::query()->exists() ? User::ROLE_MEMBER : User::ROLE_ADMIN;
                }

                $user->last_login_at = now();
                $user->save();

                return $user;
            });
        } catch (Throwable $e) {
            report($e);

            return $this->error('La connexion a échoué. Les détails sont dans le journal de l\'application.', 502);
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    /** Déconnexion locale puis fin de session côté Authentik. */
    public function logout(Request $request, OidcClient $oidc)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        try {
            $url = $oidc->logoutUrl();
        } catch (Throwable) {
            $url = null;
        }

        return $url ? redirect()->away($url) : redirect()->route('logged-out');
    }

    private function error(string $message, int $status)
    {
        return response()->view('auth.error', ['message' => $message], $status);
    }
}
