<?php

use Illuminate\Support\Facades\Route;
use Torgash\Api\Http\CartController;
use Torgash\Api\Http\CheckoutController;
use Torgash\Api\Http\DocsController;
use Torgash\Api\Http\ResourceController;

/**
 * The API's public routes: the shop half, and the description of the whole
 * thing — which has to be readable before you have a token, because a client
 * generating a stub from it does not have one yet.
 */
Route::get('api/v2/openapi.json', [DocsController::class, 'document'])->name('api.openapi');

/**
 * The shop half: what a visitor may see, and the cart they are building.
 *
 * PUBLIC, exactly as the storefront's own HTML is. There is nothing here a
 * browser could not already fetch by opening the page, and demanding a token to
 * read a catalogue would only mean every mobile app shipped with one embedded —
 * a secret in an app store is not a secret.
 *
 * The cart is the exception that proves it: it is addressed by a token, and
 * that token IS the authorisation. Nobody can read or change a cart without
 * having been given its address.
 *
 * Only GET is registered for resources. The read-only half of the API is
 * read-only because of what is routed, not because of a check somebody could
 * forget to write.
 */
Route::prefix('api/v2/shop')->name('api.shop.')->group(function () {
    Route::get('{resource}', [ResourceController::class, 'index'])
        ->where('resource', 'products|categories')
        ->name('index');
    Route::get('{resource}/{key}', [ResourceController::class, 'show'])
        ->where('resource', 'products|categories')
        ->name('show');

    // A cart of one's own: created empty, then written to by its token.
    Route::post('carts', [CartController::class, 'store'])->name('carts.store');
    Route::get('carts/{token}', [CartController::class, 'show'])->name('carts.show');
    Route::patch('carts/{token}/items', [CartController::class, 'items'])->name('carts.items');
    Route::delete('carts/{token}', [CartController::class, 'clear'])->name('carts.clear');

    // Checkout as a conversation with that cart: answer a step, read what is
    // now on offer, complete. Each answer is kept on the cart, so a client can
    // stop and come back — which is the whole of a resumable checkout.
    Route::get('carts/{token}/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
    Route::patch('carts/{token}/checkout', [CheckoutController::class, 'update'])->name('checkout.update');
    Route::post('carts/{token}/checkout/complete', [CheckoutController::class, 'complete'])->name('checkout.complete');
});
