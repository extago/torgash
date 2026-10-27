<?php

use Illuminate\Support\Facades\Route;
use Torgash\Guard\Http\AccountSecurityController;
use Torgash\Guard\Http\ChallengeController;

// Registered through StorefrontRoutes::group(), so all of this exists in every
// locale shape — a Russian customer is asked for the code in Russian, on
// /ru/two-factor, and the challenge screen the middleware sends them to is the
// one their session is already speaking.
//
// `auth` on both halves: there is nothing to ask somebody who is not logged in,
// and the code box is the SECOND question rather than a way of answering the
// first. Somebody who reaches it with no session is sent to the login form by
// the framework, which is the right answer.
Route::middleware('auth')->group(function () {
    Route::get('/two-factor', [ChallengeController::class, 'show'])->name('guard.two_factor');
    Route::post('/two-factor', [ChallengeController::class, 'verify'])->name('guard.two_factor.verify');

    // The customer's own Security page, under the account area's address and
    // name so that the item core draws in the account menu points at it like
    // any other — the same arrangement as the enquiries module's threads, and
    // for the same reason: core must not learn this module's name to keep
    // working, and uninstalling it takes the routes and the menu item together.
    Route::prefix('account')->name('account.')->group(function () {
        Route::get('security', [AccountSecurityController::class, 'show'])->name('security');
        Route::post('security', [AccountSecurityController::class, 'start'])->name('security.start');
        Route::post('security/confirm', [AccountSecurityController::class, 'confirm'])->name('security.confirm');
        Route::post('security/codes', [AccountSecurityController::class, 'codes'])->name('security.codes');
        Route::post('security/codes/new', [AccountSecurityController::class, 'newCodes'])->name('security.codes.new');
        Route::delete('security', [AccountSecurityController::class, 'disable'])->name('security.disable');
    });
});
