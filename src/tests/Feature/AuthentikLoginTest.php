<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthentikLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_est_redirige_vers_la_connexion(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_premier_compte_admin_puis_assistant_de_foyer(): void
    {
        $this->loginThroughAuthentik(['sub' => 'sub-1', 'name' => 'Louis Test', 'preferred_username' => 'louis', 'email' => 'l@example.test'])
            ->assertRedirect(route('home'));

        $user = User::firstWhere('authentik_sub', 'sub-1');
        $this->assertSame(User::ROLE_ADMIN, $user->role);
        $this->assertAuthenticatedAs($user);

        // Sans foyer : envoi vers l'assistant
        $this->get('/')->assertRedirect(route('onboarding'));
    }

    public function test_deux_comptes_peuvent_partager_le_meme_email(): void
    {
        $this->loginThroughAuthentik(['sub' => 'sub-1', 'name' => 'Admin', 'preferred_username' => 'akadmin', 'email' => 'meme@example.test']);
        $this->post('/logout');

        $this->loginThroughAuthentik(['sub' => 'sub-2', 'name' => 'Louis', 'preferred_username' => 'louis', 'email' => 'meme@example.test'])
            ->assertRedirect(route('home'));

        $this->assertSame(2, User::where('email', 'meme@example.test')->count());
        $this->assertSame(User::ROLE_MEMBER, User::firstWhere('authentik_sub', 'sub-2')->role);
    }

    public function test_etat_invalide_refuse(): void
    {
        $this->get('/auth/callback?code=abc&state=faux')->assertStatus(400);
        $this->assertGuest();
    }
}
