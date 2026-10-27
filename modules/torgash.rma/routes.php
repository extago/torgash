<?php

use Illuminate\Support\Facades\Route;
use Torgash\Rma\Http\ReturnsController;

// Registered through StorefrontRoutes, so it exists in every locale shape.
//
// Addressed by the order NUMBER and authorised the way the order page is: the
// person either owns it, placed it in this session, or holds its signed link.
// Not a route of its own kind of key — one order, one answer to "is this
// yours", given in one place.
Route::post('/checkout/{order:order_number}/return', [ReturnsController::class, 'store'])
    ->name('rma.request');
