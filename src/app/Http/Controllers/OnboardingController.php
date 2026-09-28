<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Store;
use App\Models\User;
use App\Support\HouseholdEquipment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Assistant de première connexion : création du foyer
 * (nom, budget, membres et coefficients, appareils de cuisine).
 */
class OnboardingController extends Controller
{
    public function show(Request $request)
    {
        if ($request->user()->household_id) {
            return redirect()->route('home');
        }

        return view('onboarding.show', [
            'categories' => HouseholdMember::CATEGORIES,
            'equipment' => Equipment::ordered(),
            'stores' => Store::active(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->household_id) {
            return redirect()->route('home');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'budget' => ['required', 'numeric', 'min:10', 'max:2000'],
            'main_store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'produce_store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'members' => ['required', 'array', 'min:1', 'max:20'],
            'members.*.name' => ['required', 'string', 'max:60'],
            'members.*.category' => ['required', Rule::in(array_keys(HouseholdMember::CATEGORIES))],
            'members.*.coefficient' => ['required', 'numeric', 'min:0', 'max:2'],
            'me' => ['nullable', 'integer'],
            'equipment' => ['nullable', 'array'],
            'equipment.*' => ['integer', 'exists:equipment,id'],
            'other_equipment' => ['nullable', 'string', 'max:300'],
        ], [], [
            'name' => 'nom du foyer',
            'budget' => 'budget hebdomadaire',
            'members' => 'membres',
            'members.*.name' => 'prénom',
            'members.*.category' => 'catégorie',
            'members.*.coefficient' => 'coefficient',
        ]);

        DB::transaction(function () use ($data, $user) {
            $household = Household::create([
                'name' => trim($data['name']),
                'weekly_budget_cents' => (int) round($data['budget'] * 100),
                'main_store_id' => $data['main_store_id'] ?? null,
                'produce_store_id' => $data['produce_store_id'] ?? null,
                'created_by' => $user->id,
            ]);

            $user->forceFill([
                'household_id' => $household->id,
                'household_role' => User::HOUSEHOLD_ADMIN,
            ])->save();

            $position = 0;
            foreach ($data['members'] as $key => $member) {
                $household->members()->create([
                    'name' => trim($member['name']),
                    'category' => $member['category'],
                    'portion_coefficient' => round((float) $member['coefficient'], 2),
                    'user_id' => isset($data['me']) && (string) $data['me'] === (string) $key ? $user->id : null,
                    'position' => $position += 10,
                ]);
            }

            HouseholdEquipment::sync($household, $data['equipment'] ?? [], $data['other_equipment'] ?? null, $user->id);
        });

        return redirect()->route('home')->with('status', 'Ton foyer est prêt. Tu peux inviter la famille depuis « Mon foyer ».');
    }
}
