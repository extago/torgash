<?php

use Illuminate\Support\Facades\Route;
use Torgash\Compare\Http\CompareController;

// Registered through StorefrontRoutes::group(): in every locale shape. Kept in
// the session, so the page is the same for a guest and a customer.

Route::get('/compare', [CompareController::class, 'index'])->name('compare.index');
Route::post('/compare/toggle/{product:slug}', [CompareController::class, 'toggle'])->name('compare.toggle');
Route::post('/compare/remove/{product:slug}', [CompareController::class, 'remove'])->name('compare.remove');
Route::post('/compare/clear', [CompareController::class, 'clear'])->name('compare.clear');
