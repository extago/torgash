<?php

use Illuminate\Support\Facades\Route;
use Torgash\Promotions\Http\StorefrontController;

// Registered through StorefrontRoutes::group() — once per locale shape, so
// /sale and /ru/sale are the same file and never depend on the current request.

Route::get('/sale', [StorefrontController::class, 'index'])->name('promotions.index');
// A plain string param, not implicit binding: the controller decides what a
// missing or out-of-storefront slug means (404 here), and Eloquent's own
// binding would answer before it could.
Route::get('/sale/{slug}', [StorefrontController::class, 'show'])->name('promotions.show');
