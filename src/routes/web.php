<?php

use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\HouseholdController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PriceController;
use Illuminate\Support\Facades\Route;

// Connexion via Authentik (OIDC)
Route::get('/login', [OidcController::class, 'redirect'])->middleware('guest')->name('login');
Route::get('/auth/callback', [OidcController::class, 'callback'])->name('oidc.callback');
Route::view('/deconnecte', 'auth.logged-out')->middleware('guest')->name('logged-out');

// Lien d'invitation (accessible avant connexion : le jeton est gardé en session)
Route::get('/invitation/{token}', [InvitationController::class, 'open'])
    ->where('token', '[A-Za-z0-9]{20,100}')
    ->middleware('throttle:30,1')
    ->name('invitation.open');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [OidcController::class, 'logout'])->name('logout');

    // Comptes sans foyer : invitation en attente ou assistant de création
    Route::get('/invitation', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('/invitation', [InvitationController::class, 'accept'])->name('invitation.accept');
    Route::post('/invitation/ignorer', [InvitationController::class, 'dismiss'])->name('invitation.dismiss');
    Route::get('/bienvenue', [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('/bienvenue', [OnboardingController::class, 'store'])->name('onboarding.store');

    // Espace du foyer
    Route::middleware('household')->group(function () {
        Route::view('/', 'home')->name('home');
        Route::get('/foyer', [HouseholdController::class, 'show'])->name('household.show');
        Route::post('/foyer/quitter', [HouseholdController::class, 'leave'])->name('household.leave');

        // Référentiel : ingrédients, conditionnements, prix (tous les membres d'un foyer)
        Route::get('/ingredients', [IngredientController::class, 'index'])->name('ingredients.index');
        Route::get('/ingredients/nouveau', [IngredientController::class, 'create'])->name('ingredients.create');
        Route::post('/ingredients', [IngredientController::class, 'store'])->name('ingredients.store');
        Route::get('/ingredients/{ingredient:slug}', [IngredientController::class, 'show'])->name('ingredients.show');
        Route::put('/ingredients/{ingredient:slug}', [IngredientController::class, 'update'])->name('ingredients.update');
        Route::post('/ingredients/{ingredient:slug}/conditionnements', [IngredientController::class, 'storePack'])->name('ingredients.packs.store');
        Route::put('/ingredients/{ingredient:slug}/conditionnements/{pack}', [IngredientController::class, 'updatePack'])->name('ingredients.packs.update');
        Route::delete('/ingredients/{ingredient:slug}/conditionnements/{pack}', [IngredientController::class, 'destroyPack'])->name('ingredients.packs.destroy');
        Route::post('/ingredients/{ingredient:slug}/prix', [IngredientController::class, 'storePrice'])->name('ingredients.prices.store');

        Route::get('/prix', [PriceController::class, 'index'])->name('prices.index');
        Route::get('/prix/{store:slug}', [PriceController::class, 'edit'])->name('prices.edit');
        Route::post('/prix/{store:slug}', [PriceController::class, 'update'])->name('prices.update');

        Route::middleware('can:manage-household')->prefix('foyer')->group(function () {
            Route::put('/reglages', [HouseholdController::class, 'updateSettings'])->name('household.settings');
            Route::post('/membres', [HouseholdController::class, 'storeMember'])->name('household.members.store');
            Route::put('/membres/{member}', [HouseholdController::class, 'updateMember'])->name('household.members.update');
            Route::delete('/membres/{member}', [HouseholdController::class, 'destroyMember'])->name('household.members.destroy');
            Route::put('/appareils', [HouseholdController::class, 'updateEquipment'])->name('household.equipment');
            Route::put('/comptes/{account}', [HouseholdController::class, 'updateAccount'])->name('household.accounts.update');
            Route::delete('/comptes/{account}', [HouseholdController::class, 'removeAccount'])->name('household.accounts.remove');
            Route::post('/invitations', [InvitationController::class, 'store'])->name('household.invitations.store');
            Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('household.invitations.destroy');
        });
    });
});
