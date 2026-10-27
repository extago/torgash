<?php

use Illuminate\Support\Facades\Route;
use Torgash\Newsletter\Http\NewsletterController;

// Registered by StorefrontRoutes::group() — once per locale shape.

Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe'])->middleware('throttle:20,1')->name('newsletter.subscribe');
// A letter's link opens a page with a button: a scanner that opens every link
// in a letter must not confirm or unsubscribe anybody.
Route::get('/newsletter/confirm/{token}', [NewsletterController::class, 'confirmPage'])->where('token', '[A-Za-z0-9]{40}')->name('newsletter.confirm');
Route::post('/newsletter/confirm/{token}', [NewsletterController::class, 'confirm'])->where('token', '[A-Za-z0-9]{40}')->name('newsletter.confirm.do');
Route::get('/newsletter/unsubscribe/{token}', [NewsletterController::class, 'unsubscribePage'])->where('token', '[A-Za-z0-9]{40}')->name('newsletter.unsubscribe');
Route::post('/newsletter/unsubscribe/{token}', [NewsletterController::class, 'unsubscribe'])->where('token', '[A-Za-z0-9]{40}')->name('newsletter.unsubscribe.do');

Route::middleware('auth')->group(function () {
    Route::get('/account/newsletter', [NewsletterController::class, 'account'])->name('account.newsletter');
    Route::post('/account/newsletter', [NewsletterController::class, 'accountSave'])->name('account.newsletter.save');
});
