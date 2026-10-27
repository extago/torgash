<?php

use Illuminate\Support\Facades\Route;
use Torgash\Shipping\Http\Admin\OrderDeliveryController;
use Torgash\Shipping\Http\Admin\OrderShippingController;
use Torgash\Shipping\Http\Admin\ShippingMethodsController;
use Torgash\Shipping\Http\Admin\ShippingRatesController;
use Torgash\Shipping\Http\Admin\ZonesController;

// Loaded by ShippingServiceProvider: prefix "admin/shipping", name "admin.shipping.".

// A rate is edited inside the method screen and is identified by the pair it
// prices — (method, zone) — so there is no create/update split and no id in the
// URL. Declared before the resource so nothing swallows the nested paths.
Route::name('methods.')->prefix('methods/{shipping_method}')->group(function () {
    Route::get('rates/{zone}', [ShippingRatesController::class, 'show'])->name('rates.show');
    Route::get('rates/{zone}/edit', [ShippingRatesController::class, 'edit'])->name('rates.edit');
    Route::put('rates/{zone}', [ShippingRatesController::class, 'save'])->name('rates.save');
    Route::delete('rates/{zone}', [ShippingRatesController::class, 'destroy'])->name('rates.destroy');
});

// Before the resource, like the rates above.
Route::post('methods/bulk', [ShippingMethodsController::class, 'bulk'])->name('methods.bulk');
Route::patch('methods/{shipping_method}/status', [ShippingMethodsController::class, 'setStatus'])->name('methods.status');

Route::resource('methods', ShippingMethodsController::class)
    ->parameters(['methods' => 'shipping_method'])
    ->except(['show']);

// Pricing an order whose delivery was left to a human. The screen it appears
// on is checkout's; this endpoint is delivery's, and goes when the module does.
Route::post('orders/{order}/cost', [OrderShippingController::class, 'setCost'])->name('orders.cost');
Route::patch('orders/{order}/method', [OrderShippingController::class, 'updateMethod'])->name('orders.method');

// Re-quoting a placed order: the same GET renders the offered methods for
// wherever the screen says the order is going, the PATCH applies the choice.
Route::get('orders/{order}/delivery', [OrderDeliveryController::class, 'methods'])->name('orders.delivery');
Route::patch('orders/{order}/delivery', [OrderDeliveryController::class, 'apply']);

// Declared before the resource so "zones/test" is not swallowed by "zones/{zone}".
Route::post('zones/test', [ZonesController::class, 'test'])->name('zones.test');
Route::post('zones/bulk', [ZonesController::class, 'bulk'])->name('zones.bulk');
Route::patch('zones/{zone}/status', [ZonesController::class, 'setStatus'])->name('zones.status');
Route::resource('zones', ZonesController::class)->except(['show']);
