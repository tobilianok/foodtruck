<?php

namespace Tests;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected const ISSUER = 'https://auth.test/application/o/foodtruck/';

    protected function setUp(): void
    {
        parent::setUp();
        // Jamais d'appel au vrai service de lecture des scans (la variable du conteneur ne compte pas)
        config(['foodtruck.pages_url' => null, 'foodtruck.vision_url' => null]);

        config([
            'oidc.issuer' => self::ISSUER,
            'oidc.client_id' => 'client-test',
            'oidc.client_secret' => 'secret-test',
            'oidc.redirect' => 'https://foodtruck.test/auth/callback',
        ]);
    }

    /** Simule un aller-retour complet par Authentik et renvoie la réponse du callback. */
    protected function loginThroughAuthentik(array $userinfo)
    {
        // Repart d'un client HTTP neuf : les réponses simulées d'une connexion précédente ne doivent pas resservir.
        Http::swap(new Factory);

        Http::fake([
            self::ISSUER.'.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => 'https://auth.test/application/o/authorize/',
                'token_endpoint' => 'https://auth.test/application/o/token/',
                'userinfo_endpoint' => 'https://auth.test/application/o/userinfo/',
                'end_session_endpoint' => self::ISSUER.'end-session/',
            ]),
        ]);

        $this->get('/login')->assertRedirectContains('https://auth.test/application/o/authorize/');
        $oidc = session('oidc');

        $claims = ['iss' => self::ISSUER, 'aud' => 'client-test', 'sub' => $userinfo['sub'], 'nonce' => $oidc['nonce'], 'exp' => time() + 300];
        $idToken = 'eyJhbGciOiJub25lIn0.'.rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=').'.signature';

        Http::fake([
            'https://auth.test/application/o/token/' => Http::response(['access_token' => 'at', 'id_token' => $idToken, 'token_type' => 'Bearer']),
            'https://auth.test/application/o/userinfo/' => Http::response($userinfo),
        ]);

        return $this->get('/auth/callback?code=abc&state='.$oidc['state']);
    }

    /** Compte déjà rattaché à un foyer. */
    protected function householdUser(string $role = User::HOUSEHOLD_ADMIN, ?Household $household = null): User
    {
        $household ??= Household::create(['name' => 'Famille Test', 'weekly_budget_cents' => 10000]);

        $user = User::factory()->create(['authentik_sub' => fake()->uuid(), 'username' => fake()->userName()]);
        $user->forceFill(['household_id' => $household->id, 'household_role' => $role])->save();

        return $user->fresh();
    }
}
