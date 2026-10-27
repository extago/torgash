<?php

use Illuminate\Support\Facades\Route;
use Torgash\Checkout\Http\Admin\PaymentMethodsController;

// Loaded by CheckoutServiceProvider with prefix "admin/payments", name prefix
// "admin.checkout." — so the route names still say who owns the code
// (`admin.checkout.payment-methods.*`) while the address says what it is.
//
// torgash.payments mounts its log under the same prefix (/admin/payments/log)
// and nothing collides: two modules, one address space, distinct paths. That is
// the deliberate exception to "a module keeps to its own prefix" — payments is
// one place in a shop owner's head, and a payment method with no gateway (cash
// on delivery, a transfer) has to keep working with that module uninstalled,
// which is why the methods themselves stay checkout's.

// Before the resource, so "bulk" is never read as a method id.
Route::post('methods/bulk', [PaymentMethodsController::class, 'bulk'])->name('payment-methods.bulk');
Route::patch('methods/{paymentMethod}/status', [PaymentMethodsController::class, 'setStatus'])->name('payment-methods.status');

Route::resource('methods', PaymentMethodsController::class)
    ->except(['show'])
    ->names('payment-methods')
    ->parameters(['methods' => 'paymentMethod']);
