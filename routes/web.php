<?php

use App\Core\Storefronts\StorefrontRoutes;
use App\Http\Controllers\LocalizationController;
use Illuminate\Support\Facades\Route;

Route::post('/currency', [LocalizationController::class, 'setCurrency'])->name('currency.set');

// account.* — locale-prefixed like every other storefront route, so it goes
// through StorefrontRoutes and gets the same set of shapes (/account/... and
// /ru/account/...). Passed as a closure, not a bare file, so that this
// prefix/name pair nests INSIDE the locale group and the two concatenate —
// chaining ->prefix() twice on one registrar would overwrite instead.
StorefrontRoutes::group(function () {
    Route::prefix('account')->name('account.')->group(base_path('routes/account.php'));

    // "Confirm the contact by a code" inside any public form (PLAN §44):
    // fragments for the widget, never a page.
    Route::post('verify-contact', [\App\Http\Controllers\ContactVerifyController::class, 'send'])->name('contact_verify.send');
    Route::post('verify-contact/check', [\App\Http\Controllers\ContactVerifyController::class, 'check'])->name('contact_verify.check');
});
