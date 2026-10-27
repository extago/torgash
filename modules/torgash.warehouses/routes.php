<?php

use Illuminate\Support\Facades\Route;
use Torgash\Warehouses\Http\PointController;

// Storefront routes, registered through StorefrontRoutes::group() so they exist
// in every locale shape — see the routing rules in CLAUDE.md.

// POST, not GET: choosing a shop changes what the next page shows, and a link
// that changes state is a link a crawler follows.
Route::post('/pickup-point', [PointController::class, 'choose'])->name('warehouses.point.choose');
Route::delete('/pickup-point', [PointController::class, 'forget'])->name('warehouses.point.forget');
