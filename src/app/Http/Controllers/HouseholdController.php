<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\HouseholdMember;
use App\Models\Store;
use App\Models\User;
use App\Support\HouseholdEquipment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Page « Mon foyer » : consultation pour tous, modification pour les admins du foyer.
 */
class HouseholdController extends Controller
{
    public function show(Request $request)
    {
        $household = $request->user()->household->load(['members.user', 'users', 'equipment', 'mainStore', 'produceStore']);

        return view('household.show', [
            'household' => $household,
            'categories' => HouseholdMember::CATEGORIES,
            'equipment' => Equipment::ordered(),
            'owned' => $household->equipment->pluck('id')->all(),
            'invitations' => $household->invitations()->with('creator', 'user')->limit(10)->get(),
            'canManage' => $request->user()->can('manage-household'),
            'stores' => Store::active(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'budget' => ['required', 'numeric', 'min:10', 'max:2000'],
            'main_store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'produce_store_id' => ['nullable', 'integer', 'exists:stores,id'],
        ], [], ['name' => 'nom du foyer', 'budget' => 'budget hebdomadaire', 'main_store_id' => 'magasin principal', 'produce_store_id' => 'magasin des fruits et légumes']);

        $request->user()->household->update([
            'name' => trim($data['name']),
            'weekly_budget_cents' => (int) round($data['budget'] * 100),
            'main_store_id' => $data['main_store_id'] ?? null,
            'produce_store_id' => $data['produce_store_id'] ?? null,
        ]);

        return back()->with('status', 'Réglages du foyer enregistrés.');
    }

    public function storeMember(Request $request)
    {
        $household = $request->user()->household;
        $data = $this->validateMember($request, $household->id);

        DB::transaction(function () use ($household, $data) {
            $this->releaseAccount($household->id, $data['user_id'] ?? null);
            $household->members()->create([
                'name' => trim($data['name']),
                'category' => $data['category'],
                'portion_coefficient' => round((float) $data['coefficient'], 2),
                'user_id' => $data['user_id'] ?? null,
                'position' => ((int) $household->members()->max('position')) + 10,
            ]);
        });

        return back()->with('status', 'Membre ajouté.');
    }

    public function updateMember(Request $request, HouseholdMember $member)
    {
        $household = $request->user()->household;
        abort_unless($member->household_id === $household->id, 404);
        $data = $this->validateMember($request, $household->id);

        DB::transaction(function () use ($member, $household, $data) {
            $this->releaseAccount($household->id, $data['user_id'] ?? null, $member->id);
            $member->update([
                'name' => trim($data['name']),
                'category' => $data['category'],
                'portion_coefficient' => round((float) $data['coefficient'], 2),
                'user_id' => $data['user_id'] ?? null,
            ]);
        });

        return back()->with('status', "Membre « {$member->name} » mis à jour.");
    }

    public function destroyMember(Request $request, HouseholdMember $member)
    {
        $household = $request->user()->household;
        abort_unless($member->household_id === $household->id, 404);

        if ($household->members()->count() <= 1) {
            return back()->withErrors(['member' => 'Le foyer doit garder au moins un membre.']);
        }

        $member->delete();

        return back()->with('status', "Membre « {$member->name} » retiré du foyer.");
    }

    public function updateEquipment(Request $request)
    {
        $data = $request->validate([
            'equipment' => ['nullable', 'array'],
            'equipment.*' => ['integer', 'exists:equipment,id'],
            'other_equipment' => ['nullable', 'string', 'max:300'],
        ]);

        HouseholdEquipment::sync($request->user()->household, $data['equipment'] ?? [], $data['other_equipment'] ?? null, $request->user()->id);

        return back()->with('status', 'Appareils de cuisine enregistrés.');
    }

    public function updateAccount(Request $request, User $account)
    {
        $household = $request->user()->household;
        abort_unless($account->household_id === $household->id, 404);

        $data = $request->validate([
            'household_role' => ['required', Rule::in([User::HOUSEHOLD_ADMIN, User::HOUSEHOLD_MEMBER])],
        ]);

        if ($data['household_role'] === User::HOUSEHOLD_MEMBER && $account->isHouseholdAdmin() && $household->adminCount() <= 1) {
            return back()->withErrors(['account' => 'Le foyer doit garder au moins un administrateur.']);
        }

        $account->forceFill(['household_role' => $data['household_role']])->save();

        return back()->with('status', "{$account->name} est maintenant {$data['household_role']} du foyer.");
    }

    public function removeAccount(Request $request, User $account)
    {
        $household = $request->user()->household;
        abort_unless($account->household_id === $household->id, 404);

        return $this->detach($request, $account);
    }

    /** Quitter soi-même le foyer (tout compte, sauf le dernier admin). */
    public function leave(Request $request)
    {
        return $this->detach($request, $request->user());
    }

    private function detach(Request $request, User $account)
    {
        $household = $account->household;

        if ($account->isHouseholdAdmin() && $household->adminCount() <= 1) {
            return back()->withErrors(['account' => 'Le foyer doit garder au moins un administrateur. Nomme d\'abord un autre admin.']);
        }

        DB::transaction(function () use ($account, $household) {
            HouseholdMember::where('household_id', $household->id)->where('user_id', $account->id)->update(['user_id' => null]);
            $account->forceFill(['household_id' => null, 'household_role' => null])->save();
        });

        if ($account->is($request->user())) {
            return redirect()->route('onboarding')->with('status', 'Tu as quitté le foyer « '.$household->name.' ».');
        }

        return back()->with('status', "{$account->name} ne fait plus partie du foyer.");
    }

    private function validateMember(Request $request, int $householdId): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'category' => ['required', Rule::in(array_keys(HouseholdMember::CATEGORIES))],
            'coefficient' => ['required', 'numeric', 'min:0', 'max:2'],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('household_id', $householdId)],
        ], [], ['name' => 'prénom', 'category' => 'catégorie', 'coefficient' => 'coefficient', 'user_id' => 'compte lié']);
    }

    /** Un compte ne peut être lié qu'à un seul membre : on le détache des autres fiches. */
    private function releaseAccount(int $householdId, ?int $userId, ?int $exceptMemberId = null): void
    {
        if (! $userId) {
            return;
        }

        HouseholdMember::where('household_id', $householdId)
            ->where('user_id', $userId)
            ->when($exceptMemberId, fn ($q) => $q->where('id', '!=', $exceptMemberId))
            ->update(['user_id' => null]);
    }
}
