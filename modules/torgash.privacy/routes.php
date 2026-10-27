<?php

use Illuminate\Support\Facades\Route;
use Torgash\Privacy\Http\AccountPrivacyController;
use Torgash\Privacy\Http\ConsentController;

// Through StorefrontRoutes::group(), so a Russian visitor's choice posts to
// /ru/privacy/consent and comes back to the Russian page.
Route::post('/privacy/consent', ConsentController::class)->name('privacy.consent');

Route::middleware('auth')->prefix('account')->name('account.')->group(function () {
    Route::get('privacy', [AccountPrivacyController::class, 'index'])->name('privacy.index');
    Route::get('privacy/download', [AccountPrivacyController::class, 'download'])->name('privacy.download');
    Route::post('privacy/erase', [AccountPrivacyController::class, 'erase'])->name('privacy.erase');
});
