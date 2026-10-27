<?php

use Illuminate\Support\Facades\Route;
use Torgash\Wishlist\Http\WishlistController;

// Registered through StorefrontRoutes::group(): in every locale shape. The
// page is for guests too — their list is kept as a basket is — so it is not
// under /account, though a signed-in visitor sees it inside their profile.

Route::get('/wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
Route::post('/wishlist/toggle/{product:slug}', [WishlistController::class, 'toggle'])->name('wishlist.toggle');
Route::post('/wishlist/remove/{product:slug}', [WishlistController::class, 'remove'])->name('wishlist.remove');
Route::post('/wishlist/clear', [WishlistController::class, 'clear'])->name('wishlist.clear');
Route::post('/wishlist/notify', [WishlistController::class, 'notify'])->name('wishlist.notify');
Route::post('/wishlist/share', [WishlistController::class, 'share'])->name('wishlist.share');
Route::post('/wishlist/unshare', [WishlistController::class, 'unshare'])->name('wishlist.unshare');
Route::get('/wishlist/shared/{token}', [WishlistController::class, 'shared'])->name('wishlist.shared')->where('token', '[A-Za-z0-9]{20,64}');
