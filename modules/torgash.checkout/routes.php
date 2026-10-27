<?php

use Illuminate\Support\Facades\Route;
use Torgash\Checkout\Http\CheckoutAccountController;
use Torgash\Checkout\Http\CheckoutController;
use Torgash\Checkout\Http\CouponController;
use Torgash\Checkout\Http\DeliveryController;
use Torgash\Checkout\Http\OneClickController;
use Torgash\Checkout\Http\OrderEnquiryController;

Route::post('/cart/coupon', [CouponController::class, 'apply'])->name('cart.coupon.apply');
Route::delete('/cart/coupon', [CouponController::class, 'remove'])->name('cart.coupon.remove');

// The cart page's delivery estimate. Owned by checkout, mounted under /cart,
// same as the coupon box above: cart knows nothing about either.
Route::post('/cart/delivery', [DeliveryController::class, 'estimate'])->name('cart.delivery.estimate');

// Buying with a phone number and nothing else. Its own endpoint rather than a
// mode of /checkout: it takes a product, not a cart, and answers with an order
// number instead of a page.
Route::post('/one-click', [OneClickController::class, 'store'])->name('checkout.one_click');

// "This email has an account — sign in with a code" inside the form (PLAN §44).
Route::post('/checkout/account', [CheckoutAccountController::class, 'check'])->name('checkout.account.check');
Route::post('/checkout/account/code', [CheckoutAccountController::class, 'send'])->name('checkout.account.send');
Route::post('/checkout/account/verify', [CheckoutAccountController::class, 'verify'])->name('checkout.account.verify');
Route::post('/checkout/account/resend', [CheckoutAccountController::class, 'resend'])->name('checkout.account.resend');

Route::get('/checkout', [CheckoutController::class, 'create'])->name('checkout.create');
Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
Route::post('/checkout/quote', [DeliveryController::class, 'quote'])->name('checkout.quote');
Route::get('/checkout/{order:order_number}/confirmation', [CheckoutController::class, 'confirmation'])->name('checkout.confirmation');
Route::get('/checkout/{order:order_number}/invoice', [CheckoutController::class, 'invoice'])->name('checkout.invoice');

// A question about your own order, from the order page. Authorised the way that
// page is — account, session, or the order's signed key — which is why it lives
// beside it rather than in the enquiries module: only checkout can answer "is
// this order yours".
Route::post('/checkout/{order:order_number}/enquiry', [OrderEnquiryController::class, 'store'])
    ->name('checkout.enquiry');
