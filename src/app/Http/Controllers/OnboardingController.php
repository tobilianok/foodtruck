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
            'members.*.birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1899-12-31'],
            'members.*.coefficient' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'members.*.coefficient_manual' => ['nullable', 'boolean'],
            'me' => ['nullable', 'integer'],
            'equipment' => ['nullable', 'array'],
            'equipment.*' => ['integer', 'exists:equipment,id'],
            'other_equipment' => ['nullable', 'string', 'max:300'],
        ], [], [
            'name' => 'nom du foyer',
            'budget' => 'budget hebdomadaire',
            'members' => 'membres',
            'members.*.name' => 'prénom',
            'members.*.birth_date' => 'date de naissance',
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
                $household->members()->create(HouseholdMember::attributesFromInput($member['birth_date'] ?? null, $member['coefficient'] ?? null, ! empty($member['coefficient_manual'])) + [
                    'name' => trim($member['name']),
                    'user_id' => isset($data['me']) && (string) $data['me'] === (string) $key ? $user->id : null,
                    'position' => $position += 10,
                ]);
            }

            HouseholdEquipment::sync($household, $data['equipment'] ?? [], $data['other_equipment'] ?? null, $user->id);
        });

        return redirect()->route('home')->with('status', 'Ton foyer est prêt. Tu peux inviter la famille depuis « Mon foyer ».');
    }
}
