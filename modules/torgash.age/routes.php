<?php

use Illuminate\Support\Facades\Route;
use Torgash\Age\Http\ConfirmController;

// Storefront routes (StorefrontRoutes::group: bare and per locale).
// "I am 18 or older" — the strip's and the window's button.
Route::post('/age/confirm', ConfirmController::class)->name('age.confirm');
