<?php

use App\Http\Controllers\Auth\OidcController;
use Illuminate\Support\Facades\Route;

// Connexion via Authentik (OIDC)
Route::get('/login', [OidcController::class, 'redirect'])->middleware('guest')->name('login');
Route::get('/auth/callback', [OidcController::class, 'callback'])->name('oidc.callback');
Route::view('/deconnecte', 'auth.logged-out')->middleware('guest')->name('logged-out');

// Espace connecté
Route::middleware('auth')->group(function () {
    Route::view('/', 'home')->name('home');
    Route::post('/logout', [OidcController::class, 'logout'])->name('logout');
});
