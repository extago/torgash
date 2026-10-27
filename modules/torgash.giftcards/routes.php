<?php

use Illuminate\Support\Facades\Route;
use Torgash\Giftcards\Http\GiftcardController;

// Registered through StorefrontRoutes::group(): in every locale shape. The
// card's page is addressed by a random TOKEN, not a signature, so it survives
// the language switcher like any other page.

Route::get('/gift/{token}', [GiftcardController::class, 'show'])->name('giftcards.show')->where('token', '[A-Za-z0-9]{40}');
Route::post('/gift/{token}/claim', [GiftcardController::class, 'claim'])->name('giftcards.claim')->where('token', '[A-Za-z0-9]{40}')->middleware('auth');
Route::post('/gift/{token}/use', [GiftcardController::class, 'useOnCart'])->name('giftcards.use')->where('token', '[A-Za-z0-9]{40}');

// The basket's cards: take one off, cap what it pays, use one of your own.
Route::delete('/cart/giftcards/{card}', [GiftcardController::class, 'remove'])->name('giftcards.cart.remove')->whereNumber('card');
Route::post('/cart/giftcards/{card}/limit', [GiftcardController::class, 'limit'])->name('giftcards.cart.limit')->whereNumber('card');
Route::post('/cart/giftcards/{card}/own', [GiftcardController::class, 'useOwn'])->name('giftcards.cart.own')->whereNumber('card')->middleware('auth');

Route::middleware('auth')->prefix('account')->name('account.')->group(function () {
    Route::get('gift-cards', [GiftcardController::class, 'account'])->name('giftcards.index');
    Route::post('gift-cards', [GiftcardController::class, 'attach'])->name('giftcards.attach');
});
