<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un compte sans foyer est envoyé vers l'invitation en attente,
 * ou à défaut vers l'assistant de création de foyer.
 */
class EnsureHousehold
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->household_id === null) {
            return $request->session()->has('invitation_token')
                ? redirect()->route('invitation.show')
                : redirect()->route('onboarding');
        }

        return $next($request);
    }
}
