<?php

use Illuminate\Support\Facades\Route;
use Torgash\Payments\Http\Admin\GatewaysController;

// Loaded by PaymentsServiceProvider with prefix "admin/payments",
// name prefix "admin.payments.".
//
// There is no configuration screen here on purpose: a gateway is set up on the
// payment method it belongs to (Admin → Payment), because "set up payments" is
// one job and should not be two pages. What is left is the log and the buttons
// an order screen posts to.

Route::get('log', [GatewaysController::class, 'transactions'])->name('log');

// The scheduled check, on demand. On a development machine no callback ever
// arrives, so this is the only way to see a payment land.
Route::post('reconcile', [GatewaysController::class, 'reconcile'])->name('reconcile');

// An admin acting on a placed order's payment: refresh, capture, refund, cancel.
Route::post('orders/{order:order_number}/{capability}', [GatewaysController::class, 'act'])->name('act');
