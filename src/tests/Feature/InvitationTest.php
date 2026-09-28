<?php

namespace Tests\Feature;

use App\Models\HouseholdInvitation;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private function createLink(User $admin): string
    {
        $this->actingAs($admin)->post('/foyer/invitations')->assertRedirect();
        $link = session('invitation_link');
        $this->assertNotEmpty($link);

        return parse_url($link, PHP_URL_PATH);
    }

    public function test_parcours_complet_d_une_invitation(): void
    {
        $admin = $this->householdUser();
        $camille = HouseholdMember::create(['household_id' => $admin->household_id, 'name' => 'Camille', 'category' => 'adulte', 'portion_coefficient' => 1]);
        $path = $this->createLink($admin);
        $this->post('/logout');
        $this->flushSession();

        // Ouverture du lien sans être connecté : jeton gardé, puis connexion Authentik
        $this->get($path)->assertRedirect(route('login'));
        $this->loginThroughAuthentik(['sub' => 'sub-camille', 'name' => 'Camille Test', 'preferred_username' => 'camille', 'email' => 'c@example.test'])
            ->assertRedirect($path);

        $this->get($path)->assertRedirect(route('invitation.show'));
        $this->get('/invitation')->assertOk()->assertSee('Rejoindre « Famille Test »')->assertSee('Camille (Adulte)');

        $this->post('/invitation', ['member' => (string) $camille->id])->assertRedirect(route('home'));

        $user = User::firstWhere('authentik_sub', 'sub-camille');
        $this->assertSame($admin->household_id, $user->household_id);
        $this->assertSame(User::HOUSEHOLD_MEMBER, $user->household_role);
        $this->assertSame($user->id, $camille->fresh()->user_id);
        $this->assertNotNull(HouseholdInvitation::first()->used_at);
    }

    public function test_une_invitation_ne_sert_qu_une_fois(): void
    {
        $admin = $this->householdUser();
        $path = $this->createLink($admin);

        $first = User::factory()->create(['name' => 'Premier']);
        $this->actingAs($first)->get($path);
        $this->post('/invitation', ['member' => 'new'])->assertRedirect(route('home'));
        $this->assertSame('Premier', $first->fresh()->member->name);

        $second = User::factory()->create();
        $this->actingAs($second)->get($path);
        $this->get('/invitation')->assertStatus(410)->assertSee('plus valable');
        $this->assertNull($second->fresh()->household_id);
    }

    public function test_invitation_expiree_ou_revoquee(): void
    {
        $admin = $this->householdUser();
        $path = $this->createLink($admin);
        HouseholdInvitation::query()->update(['expires_at' => now()->subMinute()]);

        $guest = User::factory()->create();
        $this->actingAs($guest)->get($path);
        $this->get('/invitation')->assertStatus(410);

        $path = $this->createLink($admin);
        $invitation = HouseholdInvitation::latest('id')->first();
        $this->actingAs($admin)->delete("/foyer/invitations/{$invitation->id}")->assertRedirect();
        $this->assertModelMissing($invitation);

        $this->actingAs($guest)->get($path);
        $this->get('/invitation')->assertStatus(410);
    }

    public function test_un_compte_deja_dans_un_foyer_ne_peut_pas_rejoindre(): void
    {
        $admin = $this->householdUser();
        $path = $this->createLink($admin);
        $other = $this->householdUser();

        $this->actingAs($other)->get($path);
        $this->get('/invitation')->assertStatus(410)->assertSee('appartient déjà');
    }

    public function test_ignorer_l_invitation_pour_creer_son_foyer(): void
    {
        $admin = $this->householdUser();
        $path = $this->createLink($admin);
        $guest = User::factory()->create();

        $this->actingAs($guest)->get($path);
        $this->get('/')->assertRedirect(route('invitation.show'));
        $this->post('/invitation/ignorer')->assertRedirect(route('onboarding'));
        $this->get('/')->assertRedirect(route('onboarding'));
    }

    public function test_le_jeton_n_est_pas_stocke_en_clair(): void
    {
        $admin = $this->householdUser();
        $path = $this->createLink($admin);
        $token = basename($path);

        $this->assertSame(hash('sha256', $token), HouseholdInvitation::first()->token_hash);
        $this->assertDatabaseMissing('household_invitations', ['token_hash' => $token]);
    }
}
