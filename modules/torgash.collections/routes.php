<?php

use Illuminate\Support\Facades\Route;
use Torgash\Collections\Http\StorefrontController;

// Registered by StorefrontRoutes::group() — once per locale shape.

Route::get('/collections', [StorefrontController::class, 'index'])->name('collections.index');
Route::get('/collections/{slug}', [StorefrontController::class, 'show'])->name('collections.show');
