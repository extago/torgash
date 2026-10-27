<?php

use Illuminate\Support\Facades\Route;
use Torgash\Shipping\Http\DeliveryController;

// Storefront delivery endpoints. Registered per locale shape by
// StorefrontRoutes::group, same as checkout's own routes. Mounted under /cart
// and /checkout because that is where the fragments they return live; cart and
// checkout know nothing about shipping.

Route::post('/cart/delivery', [DeliveryController::class, 'estimate'])->name('cart.delivery.estimate');
Route::post('/checkout/quote', [DeliveryController::class, 'quote'])->name('checkout.quote');
