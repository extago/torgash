<?php

use Illuminate\Support\Facades\Route;
use Torgash\Cart\Http\CartController;

Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
Route::post('/cart/add/{product:slug}', [CartController::class, 'add'])->name('cart.add');
// The choice window of a list (a card, the search window): variations, the
// product's questions, the quantity — a fragment for the one shared dialog.
Route::get('/cart/choose/{product:slug}', [CartController::class, 'choose'])->name('cart.choose');
// Quick view from a card (setting `quick_view`): the choice window with the
// product's pictures and its short list — the same buy box, so buying here
// and on the page are one thing.
Route::get('/cart/quick/{product:slug}', [CartController::class, 'quick'])->name('cart.quick');
// A LINE, not a product: one product can be in the cart twice, configured two
// ways (PLAN §33.3). For a product with nothing configured the key IS its
// product id, so every link written before this still addresses what it meant.
Route::patch('/cart/items/{line}', [CartController::class, 'updateItem'])->name('cart.update');
Route::delete('/cart/items/{line}', [CartController::class, 'removeItem'])->name('cart.remove');
Route::delete('/cart', [CartController::class, 'clear'])->name('cart.clear');
