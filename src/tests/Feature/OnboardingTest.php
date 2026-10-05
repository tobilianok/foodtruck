<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_liste_des_appareils_par_defaut_est_en_place(): void
    {
        $this->assertSame(14, Equipment::where('is_default', true)->count());
        $this->assertNotNull(Equipment::firstWhere('slug', 'yaourtiere'));
    }

    public function test_l_assistant_s_affiche_pour_un_compte_sans_foyer(): void
    {
        $user = User::factory()->create(['name' => 'Louis Test']);

        $this->actingAs($user)->get('/bienvenue')
            ->assertOk()
            ->assertSee('Créer mon foyer')
            ->assertSee('Yaourtière');
    }

    public function test_creation_du_foyer(): void
    {
        $user = User::factory()->create(['name' => 'Louis Test']);
        $four = Equipment::firstWhere('slug', 'four');
        $cookeo = Equipment::firstWhere('slug', 'cookeo');

        $this->actingAs($user)->post('/bienvenue', [
            'name' => 'Famille Test',
            'budget' => '100',
            'members' => [
                '0' => ['name' => 'Louis', 'category' => 'adulte', 'coefficient' => '1'],
                '2' => ['name' => 'Camille', 'category' => 'adulte', 'coefficient' => '1'],
                '5' => ['name' => 'Bébé', 'category' => 'tout-petit', 'coefficient' => '0'],
            ],
            'me' => '0',
            'equipment' => [$four->id, $cookeo->id],
            'other_equipment' => 'Thermomix, cookeo ,  ',
        ])->assertRedirect(route('home'));

        $user->refresh();
        $household = $user->household;

        $this->assertSame('Famille Test', $household->name);
        $this->assertSame(10000, $household->weekly_budget_cents);
        $this->assertTrue($user->isHouseholdAdmin());
        $this->assertSame(3, $household->members()->count());
        $this->assertEquals(2.0, $household->totalPortions());
        $this->assertSame('Louis', $user->member->name);
        // Four + Cookeo + Thermomix (Cookeo saisi deux fois n'est compté qu'une fois)
        $this->assertEqualsCanonicalizing(['four', 'cookeo', 'thermomix'], $household->equipment->pluck('slug')->all());
        $this->assertFalse(Equipment::firstWhere('slug', 'thermomix')->is_default);

        $this->get('/foyer')->assertOk()->assertSee('2 parts par repas');
        $this->get('/')->assertOk()->assertSee('Choisis les repas de la semaine');
    }

    public function test_validation_en_francais(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/bienvenue')->post('/bienvenue', ['name' => '', 'budget' => 5, 'members' => []])
            ->assertRedirect('/bienvenue')
            ->assertSessionHasErrors(['name', 'budget', 'members']);

        $this->assertStringContainsString('obligatoire', session('errors')->first('name'));
        $this->assertNull($user->fresh()->household_id);
    }

    public function test_un_compte_avec_foyer_ne_revoit_pas_l_assistant(): void
    {
        $user = $this->householdUser();

        $this->actingAs($user)->get('/bienvenue')->assertRedirect(route('home'));
    }
}
