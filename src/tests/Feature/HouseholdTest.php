<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HouseholdTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_page_foyer_s_affiche_pour_admin_et_membre(): void
    {
        $admin = $this->householdUser();
        $member = $this->householdUser(User::HOUSEHOLD_MEMBER, $admin->household);
        HouseholdMember::create(['household_id' => $admin->household_id, 'name' => 'Louis', 'category' => 'adulte', 'portion_coefficient' => 1, 'user_id' => $admin->id]);

        $this->actingAs($admin)->get('/foyer')->assertOk()->assertSee('Créer un lien d\'invitation', false)->assertSee('Enregistrer les appareils');
        $this->actingAs($member)->get('/foyer')->assertOk()->assertDontSee('Créer un lien d\'invitation', false)->assertSee('Seuls les administrateurs');
    }

    public function test_un_membre_ne_peut_rien_modifier(): void
    {
        $admin = $this->householdUser();
        $member = $this->householdUser(User::HOUSEHOLD_MEMBER, $admin->household);

        $this->actingAs($member)->put('/foyer/reglages', ['name' => 'Piraté', 'budget' => 500])->assertForbidden();
        $this->actingAs($member)->post('/foyer/invitations')->assertForbidden();
        $this->assertSame('Famille Test', $admin->household->fresh()->name);
    }

    public function test_admin_modifie_reglages_membres_et_appareils(): void
    {
        $admin = $this->householdUser();
        $household = $admin->household;

        $this->actingAs($admin)->put('/foyer/reglages', ['name' => 'Les Test', 'budget' => '85.5'])->assertRedirect();
        $this->assertSame(8550, $household->fresh()->weekly_budget_cents);

        $this->post('/foyer/membres', ['name' => 'Léo', 'category' => 'enfant', 'coefficient' => '0.6', 'user_id' => $admin->id])->assertRedirect();
        $leo = HouseholdMember::firstWhere('name', 'Léo');
        $this->assertSame($admin->id, $leo->user_id);

        // Lier le même compte à une autre fiche le détache de la première
        $this->post('/foyer/membres', ['name' => 'Autre', 'category' => 'adulte', 'coefficient' => '1', 'user_id' => $admin->id]);
        $this->assertNull($leo->fresh()->user_id);

        $this->put("/foyer/membres/{$leo->id}", ['name' => 'Léo', 'category' => 'enfant', 'coefficient' => '0.8'])->assertRedirect();
        $this->assertEquals(0.8, $leo->fresh()->portion_coefficient);

        $yaourtiere = Equipment::firstWhere('slug', 'yaourtiere');
        $this->put('/foyer/appareils', ['equipment' => [$yaourtiere->id], 'other_equipment' => 'Déshydrateur'])->assertRedirect();
        $this->assertEqualsCanonicalizing(['yaourtiere', 'deshydrateur'], $household->fresh()->equipment->pluck('slug')->all());

        $this->delete("/foyer/membres/{$leo->id}")->assertRedirect();
        $this->assertModelMissing($leo);
    }

    public function test_un_compte_lie_d_un_autre_foyer_est_refuse(): void
    {
        $admin = $this->householdUser();
        $stranger = $this->householdUser();

        $this->actingAs($admin)->post('/foyer/membres', ['name' => 'X', 'category' => 'adulte', 'coefficient' => 1, 'user_id' => $stranger->id])
            ->assertSessionHasErrors('user_id');
    }

    public function test_impossible_de_toucher_un_autre_foyer(): void
    {
        $admin = $this->householdUser();
        $other = $this->householdUser();
        $foreign = HouseholdMember::create(['household_id' => $other->household_id, 'name' => 'Étranger', 'category' => 'adulte', 'portion_coefficient' => 1]);

        $this->actingAs($admin)->put("/foyer/membres/{$foreign->id}", ['name' => 'Piraté', 'category' => 'adulte', 'coefficient' => 1])->assertNotFound();
        $this->actingAs($admin)->delete("/foyer/comptes/{$other->id}")->assertNotFound();
    }

    public function test_le_dernier_admin_est_protege(): void
    {
        $admin = $this->householdUser();
        $member = $this->householdUser(User::HOUSEHOLD_MEMBER, $admin->household);

        // Le dernier admin ne peut pas partir
        $this->actingAs($admin)->post('/foyer/quitter')->assertSessionHasErrors('account');
        $this->assertSame($admin->household_id, $admin->fresh()->household_id);

        // Nommer un autre admin, puis rétrograder le premier devient possible
        $this->put("/foyer/comptes/{$member->id}", ['household_role' => 'admin'])->assertRedirect();
        $this->assertTrue($member->fresh()->isHouseholdAdmin());

        $this->actingAs($member->fresh())->put("/foyer/comptes/{$admin->id}", ['household_role' => 'membre'])->assertRedirect();
        $this->assertFalse($admin->fresh()->isHouseholdAdmin());
    }

    public function test_un_membre_peut_quitter_le_foyer(): void
    {
        $admin = $this->householdUser();
        $member = $this->householdUser(User::HOUSEHOLD_MEMBER, $admin->household);
        $fiche = HouseholdMember::create(['household_id' => $admin->household_id, 'name' => 'Camille', 'category' => 'adulte', 'portion_coefficient' => 1, 'user_id' => $member->id]);

        $this->actingAs($member)->post('/foyer/quitter')->assertRedirect(route('onboarding'));

        $this->assertNull($member->fresh()->household_id);
        $this->assertNull($fiche->fresh()->user_id);
    }
}
