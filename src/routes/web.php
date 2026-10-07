<?php

use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\HouseholdController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PlanningController;
use App\Http\Controllers\PriceController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\RecipeImportController;
use App\Http\Controllers\SavedMenuController;
use App\Http\Controllers\ShoppingController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\TagController;
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
        Route::get('/', HomeController::class)->name('home');
        Route::view('/plus', 'plus')->name('plus');
        Route::view('/aide', 'help')->name('help');
        Route::get('/foyer', [HouseholdController::class, 'show'])->name('household.show');
        Route::post('/foyer/quitter', [HouseholdController::class, 'leave'])->name('household.leave');

        // Recettes : publiques, saisies par tous ; modification par l'auteur ou un admin
        Route::get('/recettes', [RecipeController::class, 'index'])->name('recipes.index');
        Route::get('/recettes/nouvelle', [RecipeController::class, 'create'])->name('recipes.create');
        Route::post('/recettes', [RecipeController::class, 'store'])->name('recipes.store');
        // v0.22.0 : étiquettes des recettes (créer, renommer, supprimer)
        Route::get('/recettes/etiquettes', [TagController::class, 'index'])->name('recipes.tags');
        Route::post('/recettes/etiquettes', [TagController::class, 'store'])->name('recipes.tags.store');
        Route::put('/recettes/etiquettes/{tag}', [TagController::class, 'update'])->name('recipes.tags.update');
        Route::delete('/recettes/etiquettes/{tag}', [TagController::class, 'destroy'])->name('recipes.tags.destroy');
        Route::get('/recettes/importees', [RecipeImportController::class, 'index'])->name('recipes.imports.index');
        Route::post('/recettes/importees/synchroniser', [RecipeImportController::class, 'sync'])->name('recipes.imports.sync');
        Route::post('/recettes/importees/tout-supprimer', [RecipeImportController::class, 'discardAll'])->name('recipes.imports.discard-all');
        Route::get('/recettes/importees/progression', [RecipeImportController::class, 'progress'])->name('recipes.imports.progress');
        Route::get('/recettes/importees/{recipeImport}', [RecipeImportController::class, 'show'])->name('recipes.imports.show');
        Route::post('/recettes/importees/{recipeImport}/relire', [RecipeImportController::class, 'reanalyse'])->name('recipes.imports.reanalyse');
        Route::get('/recettes/importees/{recipeImport}/ia', [RecipeImportController::class, 'ai'])->name('recipes.imports.ai');
        Route::post('/recettes/importees/{recipeImport}/ia', [RecipeImportController::class, 'aiSend'])->name('recipes.imports.ai.send');
        Route::post('/recettes/importees/{recipeImport}/ia/annuler', [RecipeImportController::class, 'aiCancel'])->name('recipes.imports.ai.cancel');
        Route::post('/recettes/importees/{recipeImport}/ignorer', [RecipeImportController::class, 'ignore'])->name('recipes.imports.ignore');
        Route::get('/recettes/{recipe:slug}', [RecipeController::class, 'show'])->name('recipes.show');
        Route::get('/recettes/{recipe:slug}/modifier', [RecipeController::class, 'edit'])->name('recipes.edit');
        Route::put('/recettes/{recipe:slug}', [RecipeController::class, 'update'])->name('recipes.update');
        Route::delete('/recettes/{recipe:slug}', [RecipeController::class, 'destroy'])->name('recipes.destroy');
        Route::post('/recettes/{recipe:slug}/dupliquer', [RecipeController::class, 'duplicate'])->name('recipes.duplicate');
        Route::post('/recettes/{recipe:slug}/favori', [RecipeController::class, 'favorite'])->name('recipes.favorite');

        // Planning des repas (semaine du lundi au dimanche), partagé par le foyer
        Route::get('/planning', [PlanningController::class, 'index'])->name('planning.index');
        Route::get('/planning/repas/nouveau', [PlanningController::class, 'create'])->name('planning.create');
        Route::post('/planning/repas', [PlanningController::class, 'store'])->name('planning.store');
        Route::get('/planning/repas/{entry}/modifier', [PlanningController::class, 'edit'])->name('planning.edit');
        Route::put('/planning/repas/{entry}', [PlanningController::class, 'update'])->name('planning.update');
        Route::post('/planning/repas/{entry}/remplacer', [PlanningController::class, 'replace'])->name('planning.replace');
        Route::delete('/planning/repas/{entry}', [PlanningController::class, 'destroy'])->name('planning.destroy');
        Route::post('/planning/repas/{entry}/congeler', [PlanningController::class, 'freeze'])->name('planning.freeze');
        // Menu automatique : proposer la semaine, garder / changer un plat, valider ou effacer les propositions
        Route::post('/planning/menu/proposer', [MenuController::class, 'generate'])->name('menu.generate');
        Route::post('/planning/menu/valider', [MenuController::class, 'accept'])->name('menu.accept');
        Route::post('/planning/menu/effacer', [MenuController::class, 'clear'])->name('menu.clear');
        Route::post('/planning/repas/{entry}/garder', [MenuController::class, 'keep'])->name('menu.keep');
        Route::post('/planning/repas/{entry}/autre-idee', [MenuController::class, 'another'])->name('menu.another');
        // v0.22.0 : menus enregistrés (repas composés réutilisables), créés depuis un repas du planning
        Route::get('/planning/menus', [SavedMenuController::class, 'index'])->name('planning.menus');
        Route::get('/planning/menus/nouveau', [SavedMenuController::class, 'create'])->name('planning.menus.create');
        Route::post('/planning/menus', [SavedMenuController::class, 'store'])->name('planning.menus.store');
        Route::put('/planning/menus/{menu}', [SavedMenuController::class, 'update'])->name('planning.menus.update');
        Route::delete('/planning/menus/{menu}', [SavedMenuController::class, 'destroy'])->name('planning.menus.destroy');
        Route::get('/planning/{week}', [PlanningController::class, 'index'])->where('week', '\d{4}-\d{2}-\d{2}')->name('planning.week');

        // Liste de courses : calculée depuis le planning, partagée et cochable en direct
        Route::get('/courses', [ShoppingController::class, 'index'])->name('shopping.index');
        Route::post('/courses', [ShoppingController::class, 'store'])->name('shopping.store');
        Route::get('/courses/liste/{list}', [ShoppingController::class, 'show'])->name('shopping.show');
        Route::get('/courses/liste/{list}/bilan', [ShoppingController::class, 'bilan'])->name('shopping.bilan');
        Route::put('/courses/liste/{list}', [ShoppingController::class, 'update'])->name('shopping.update');
        Route::post('/courses/liste/{list}/actualiser', [ShoppingController::class, 'refresh'])->name('shopping.refresh');
        Route::post('/courses/liste/{list}/terminer', [ShoppingController::class, 'archive'])->name('shopping.archive');
        Route::post('/courses/liste/{list}/rouvrir', [ShoppingController::class, 'reopen'])->name('shopping.reopen');
        Route::get('/courses/liste/{list}/etat', [ShoppingController::class, 'state'])->middleware('throttle:120,1')->name('shopping.state');
        Route::post('/courses/liste/{list}/articles', [ShoppingController::class, 'storeItem'])->name('shopping.items.store');
        Route::post('/courses/articles/{item}/cocher', [ShoppingController::class, 'check'])->middleware('throttle:240,1')->name('shopping.items.check');
        Route::put('/courses/articles/{item}', [ShoppingController::class, 'updateItem'])->name('shopping.items.update');
        Route::delete('/courses/articles/{item}', [ShoppingController::class, 'destroyItem'])->name('shopping.items.destroy');

        // Stock du foyer et anti-gaspi
        Route::get('/stock', [StockController::class, 'index'])->name('stock.index');
        Route::get('/stock/recettes', [StockController::class, 'recipes'])->name('stock.recipes');
        Route::post('/stock', [StockController::class, 'store'])->name('stock.store');
        Route::put('/stock/{lot}', [StockController::class, 'update'])->name('stock.update');
        Route::delete('/stock/{lot}', [StockController::class, 'destroy'])->name('stock.destroy');

        // Référentiel : ingrédients, conditionnements, prix (tous les membres d'un foyer)
        Route::get('/ingredients', [IngredientController::class, 'index'])->name('ingredients.index');
        Route::get('/ingredients/nouveau', [IngredientController::class, 'create'])->name('ingredients.create');
        Route::post('/ingredients', [IngredientController::class, 'store'])->name('ingredients.store');
        // v0.17.0 : corrections en fenêtre depuis une recette en cours (réponses JSON)
        Route::post('/ingredients/rapide', [\App\Http\Controllers\IngredientQuickController::class, 'store'])->name('ingredients.quick.store');
        Route::post('/ingredients/{ingredient:slug}/unites/rapide', [\App\Http\Controllers\IngredientQuickController::class, 'storeUnit'])->name('ingredients.quick.unit');
        Route::post('/ingredients/{ingredient:slug}/mesures/rapide', [\App\Http\Controllers\IngredientQuickController::class, 'storeMeasure'])->name('ingredients.quick.measure');
        Route::get('/ingredients/{ingredient:slug}', [IngredientController::class, 'show'])->name('ingredients.show');
        Route::put('/ingredients/{ingredient:slug}', [IngredientController::class, 'update'])->name('ingredients.update');
        Route::post('/ingredients/{ingredient:slug}/conditionnements', [IngredientController::class, 'storePack'])->name('ingredients.packs.store');
        Route::put('/ingredients/{ingredient:slug}/conditionnements/{pack}', [IngredientController::class, 'updatePack'])->name('ingredients.packs.update');
        Route::delete('/ingredients/{ingredient:slug}/conditionnements/{pack}', [IngredientController::class, 'destroyPack'])->name('ingredients.packs.destroy');
        Route::post('/ingredients/{ingredient:slug}/prix', [IngredientController::class, 'storePrice'])->name('ingredients.prices.store');
        Route::post('/ingredients/{ingredient:slug}/unites', [IngredientController::class, 'storeUnit'])->name('ingredients.units.store');
        Route::put('/ingredients/{ingredient:slug}/unites/{unit}', [IngredientController::class, 'updateUnit'])->name('ingredients.units.update');
        Route::delete('/ingredients/{ingredient:slug}/unites/{unit}', [IngredientController::class, 'destroyUnit'])->name('ingredients.units.destroy');

        // Tickets de caisse (Paperless ou saisie manuelle) : prix réellement payés
        Route::get('/tickets', [ReceiptController::class, 'index'])->name('receipts.index');
        Route::post('/tickets', [ReceiptController::class, 'store'])->name('receipts.store');
        Route::post('/tickets/synchroniser', [ReceiptController::class, 'sync'])->middleware('throttle:6,1')->name('receipts.sync');
        // v0.19.0 : envoi à l'IA ticket par ticket, après une page de contrôle ; avancement de la lecture
        Route::get('/tickets/avancement', [ReceiptController::class, 'progress'])->name('receipts.progress');
        Route::get('/tickets/{receipt}/ia', [ReceiptController::class, 'ai'])->name('receipts.ai');
        Route::post('/tickets/{receipt}/ia', [ReceiptController::class, 'aiSend'])->name('receipts.ai.send');
        Route::post('/tickets/{receipt}/ia/annuler', [ReceiptController::class, 'aiCancel'])->name('receipts.ai.cancel');
        Route::get('/tickets/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');
        Route::put('/tickets/{receipt}', [ReceiptController::class, 'update'])->name('receipts.update');
        Route::post('/tickets/{receipt}/liste', [ReceiptController::class, 'link'])->name('receipts.link');
        Route::post('/tickets/{receipt}/relire', [ReceiptController::class, 'reparse'])->name('receipts.reparse');
        Route::post('/tickets/{receipt}/ignorer', [ReceiptController::class, 'ignore'])->name('receipts.ignore');

        Route::get('/prix', [PriceController::class, 'index'])->name('prices.index');
        Route::get('/prix/{store:slug}', [PriceController::class, 'edit'])->name('prices.edit');
        Route::post('/prix/{store:slug}', [PriceController::class, 'update'])->name('prices.update');

        Route::middleware('can:manage-household')->prefix('foyer')->group(function () {
            Route::put('/reglages', [HouseholdController::class, 'updateSettings'])->name('household.settings');
            Route::post('/membres', [HouseholdController::class, 'storeMember'])->name('household.members.store');
            Route::put('/membres/{member}', [HouseholdController::class, 'updateMember'])->name('household.members.update');
            Route::delete('/membres/{member}', [HouseholdController::class, 'destroyMember'])->name('household.members.destroy');
            Route::put('/appareils', [HouseholdController::class, 'updateEquipment'])->name('household.equipment');
            Route::put('/semaine-type', [HouseholdController::class, 'updateUsualWeek'])->name('household.usual-week');
            Route::put('/paperless', [ReceiptController::class, 'settings'])->name('household.paperless');
            Route::put('/comptes/{account}', [HouseholdController::class, 'updateAccount'])->name('household.accounts.update');
            Route::delete('/comptes/{account}', [HouseholdController::class, 'removeAccount'])->name('household.accounts.remove');
            Route::post('/invitations', [InvitationController::class, 'store'])->name('household.invitations.store');
            Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('household.invitations.destroy');
        });
    });
});
