<?php

namespace App\Http\Controllers;

use App\Models\HouseholdInvitation;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Invitations : l'admin du foyer génère un lien à usage unique (7 jours).
 * La personne qui l'ouvre se connecte via Authentik puis rejoint le foyer.
 */
class InvitationController extends Controller
{
    /** Création (admin du foyer). Le lien n'est affiché qu'une fois. */
    public function store(Request $request)
    {
        [, $token] = HouseholdInvitation::issue($request->user()->household, $request->user());

        return back()
            ->with('invitation_link', route('invitation.open', $token))
            ->with('status', 'Lien d\'invitation créé, valable '.HouseholdInvitation::VALIDITY_DAYS.' jours et utilisable une seule fois.');
    }

    public function destroy(Request $request, HouseholdInvitation $invitation)
    {
        abort_unless($invitation->household_id === $request->user()->household_id, 404);

        if ($invitation->used_at === null) {
            $invitation->delete();
        }

        return back()->with('status', 'Invitation révoquée.');
    }

    /** Lien reçu : on garde le jeton en session, puis connexion si besoin. */
    public function open(Request $request, string $token)
    {
        $request->session()->put('invitation_token', $token);

        if (! $request->user()) {
            return redirect()->guest(route('login'));
        }

        return redirect()->route('invitation.show');
    }

    public function show(Request $request)
    {
        $invitation = HouseholdInvitation::findByToken($request->session()->get('invitation_token'));

        if (! $invitation || ! $invitation->isUsable()) {
            $request->session()->forget('invitation_token');

            return $this->invalid('Ce lien d\'invitation n\'est plus valable (déjà utilisé, révoqué ou expiré). Demande un nouveau lien à l\'admin du foyer.');
        }

        $user = $request->user();

        if ($user->household_id === $invitation->household_id) {
            $request->session()->forget('invitation_token');

            return redirect()->route('household.show')->with('status', 'Tu fais déjà partie de ce foyer.');
        }

        if ($user->household_id !== null) {
            $request->session()->forget('invitation_token');

            return $this->invalid('Ton compte appartient déjà au foyer « '.$user->household->name.' ». Quitte-le depuis « Mon foyer » avant de rejoindre un autre foyer.');
        }

        return view('invitations.show', [
            'invitation' => $invitation->load('household.members', 'creator'),
            'freeMembers' => $invitation->household->members->whereNull('user_id'),
        ]);
    }

    public function accept(Request $request)
    {
        $invitation = HouseholdInvitation::findByToken($request->session()->get('invitation_token'));
        $user = $request->user();

        if (! $invitation || ! $invitation->isUsable() || $user->household_id !== null) {
            return redirect()->route('invitation.show');
        }

        $data = $request->validate(['member' => ['nullable', 'string']]);

        $joined = DB::transaction(function () use ($invitation, $user, $data) {
            // Verrou : une invitation ne sert qu'une fois, même en cas de double clic.
            $fresh = HouseholdInvitation::whereKey($invitation->id)->lockForUpdate()->first();
            if (! $fresh || ! $fresh->isUsable()) {
                return false;
            }

            $user->forceFill([
                'household_id' => $fresh->household_id,
                'household_role' => User::HOUSEHOLD_MEMBER,
            ])->save();

            $member = ctype_digit((string) ($data['member'] ?? ''))
                ? HouseholdMember::where('household_id', $fresh->household_id)->whereNull('user_id')->find((int) $data['member'])
                : null;

            if ($member) {
                $member->update(['user_id' => $user->id]);
            } else {
                HouseholdMember::create([
                    'household_id' => $fresh->household_id,
                    'name' => $user->firstName(),
                    'category' => 'adulte',
                    'portion_coefficient' => HouseholdMember::defaultCoefficient('adulte'),
                    'user_id' => $user->id,
                    'position' => ((int) HouseholdMember::where('household_id', $fresh->household_id)->max('position')) + 10,
                ]);
            }

            $fresh->update(['used_at' => now(), 'used_by' => $user->id]);

            return true;
        });

        $request->session()->forget('invitation_token');

        if (! $joined) {
            return $this->invalid('Ce lien d\'invitation vient d\'être utilisé. Demande un nouveau lien à l\'admin du foyer.');
        }

        return redirect()->route('home')->with('status', 'Bienvenue dans le foyer « '.$invitation->household->name.' » !');
    }

    /** Ignorer l'invitation en attente et créer son propre foyer. */
    public function dismiss(Request $request)
    {
        $request->session()->forget('invitation_token');

        return redirect()->route('onboarding');
    }

    private function invalid(string $message)
    {
        return response()->view('invitations.invalid', ['message' => $message], 410);
    }
}
