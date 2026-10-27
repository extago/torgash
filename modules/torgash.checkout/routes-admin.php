<?php

use Illuminate\Support\Facades\Route;
use Torgash\Checkout\Http\Admin\OrderDiscountsController;
use Torgash\Checkout\Http\Admin\OrderItemsController;
use Torgash\Checkout\Http\Admin\OrderMergeController;
use Torgash\Checkout\Http\Admin\OrdersController;
use Torgash\Checkout\Http\Admin\OrderStatusesController;

// Registered before the wildcard {order:order_number} routes so "statuses"
// itself is never captured as an order_number.
Route::resource('statuses', OrderStatusesController::class)->except(['show']);

Route::get('/', [OrdersController::class, 'index'])->name('index');
// Before the {order:order_number} wildcards, so "bulk" is never read as an order number.
Route::post('/bulk', [OrdersController::class, 'bulk'])->name('bulk');
// The trash. By NUMBER, not by route-model binding: a trashed order is outside
// the default query, and these are the two routes that must find it.
Route::post('/trash/{number}/restore', [OrdersController::class, 'restore'])->name('restore');
Route::delete('/trash/{number}', [OrdersController::class, 'purge'])->name('purge');
Route::get('/{order:order_number}', [OrdersController::class, 'show'])->name('show');
Route::delete('/{order:order_number}', [OrdersController::class, 'destroy'])->name('destroy');
Route::get('/{order:order_number}/invoice', [OrdersController::class, 'invoice'])->name('invoice');
Route::patch('/{order:order_number}/status', [OrdersController::class, 'updateStatus'])->name('status');
Route::patch('/{order:order_number}/manager', [OrdersController::class, 'updateManager'])->name('manager');
Route::patch('/{order:order_number}/payment', [OrdersController::class, 'updatePayment'])->name('payment');
Route::patch('/{order:order_number}/payment-state', [OrdersController::class, 'updatePaymentState'])->name('payment-state');

// Pouring another order into this one, and undoing it (PLAN §40.10/4).
Route::get('/{order:order_number}/merge', [OrderMergeController::class, 'preview'])->name('merge.preview');
Route::post('/{order:order_number}/merge', [OrderMergeController::class, 'merge'])->name('merge');
Route::post('/{order:order_number}/merge/{merge}/undo', [OrderMergeController::class, 'undo'])->name('merge.undo');

// The item editor. A line is addressed by product id — see OrderItemsController.
// A discount on a placed order: a coupon the customer forgot, the manager's own.
Route::post('/{order:order_number}/coupon', [OrderDiscountsController::class, 'applyCoupon'])->name('coupon.apply');
Route::delete('/{order:order_number}/coupon', [OrderDiscountsController::class, 'removeCoupon'])->name('coupon.remove');
Route::put('/{order:order_number}/discount', [OrderDiscountsController::class, 'setDiscount'])->name('discount.set');
Route::delete('/{order:order_number}/discount', [OrderDiscountsController::class, 'removeDiscount'])->name('discount.remove');
Route::get('/{order:order_number}/items', [OrderItemsController::class, 'edit'])->name('items.edit');
Route::get('/{order:order_number}/items/search', [OrderItemsController::class, 'search'])->name('items.search');
Route::post('/{order:order_number}/items', [OrderItemsController::class, 'store'])->name('items.store');
// A LINE, not a product: one order can hold the same product twice since a
// product can be configured (PLAN §33.3).
// Changing what a configured line IS, as against how many of it there are.
// Before the plain {line} routes: a line key may hold anything a hash does,
// and 'config' as the next segment is this module's own word.
Route::get('/{order:order_number}/items/{line}/config', [OrderItemsController::class, 'configure'])->name('items.configure');
Route::patch('/{order:order_number}/items/{line}/config', [OrderItemsController::class, 'reconfigure'])->name('items.reconfigure');
Route::patch('/{order:order_number}/items/{line}', [OrderItemsController::class, 'update'])->name('items.update');
Route::delete('/{order:order_number}/items/{line}', [OrderItemsController::class, 'destroy'])->name('items.destroy');
