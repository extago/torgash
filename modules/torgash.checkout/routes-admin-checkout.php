<?php

use Illuminate\Support\Facades\Route;
use Torgash\Checkout\Http\Admin\CouponsController;

// Shipping methods, rates and zones moved to the torgash.shipping module
// (routes there, prefix "admin/shipping", name "admin.shipping.").
//
// Payment methods are NOT here either — they live under /admin/payments
// alongside the payment log, see PaymentMethodsController's own group. The
// controller and the table stay checkout's; only the address is shared, because
// "checkout" is the name of our module and "payments" is the name of the thing
// the shop owner came looking for.

// Before the resource, so "bulk" is never read as a coupon.
Route::post('coupons/bulk', [CouponsController::class, 'bulk'])->name('coupons.bulk');
Route::patch('coupons/{coupon}/status', [CouponsController::class, 'setStatus'])->name('coupons.status');
Route::resource('coupons', CouponsController::class)->except(['show']);
