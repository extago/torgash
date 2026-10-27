<?php

use Illuminate\Support\Facades\Route;
use Torgash\Locations\Http\LocationController;
use Torgash\Locations\Http\SuggestController;

// Storefront routes, registered through StorefrontRoutes::group() so they exist
// in every locale shape — see the routing rules in CLAUDE.md.

Route::get('/places/suggest', SuggestController::class)->name('places.suggest');

// "Your town" (PLAN §38): the window's body (an HTML fragment, asked when the
// window opens and again when a town is picked in it), the nearest town to a
// browser's coordinates, and saving the answer — a POST, since it changes
// what every next page shows.
Route::get('/location/dialog', [LocationController::class, 'dialog'])->name('locations.dialog');
Route::get('/places/nearest', [LocationController::class, 'nearest'])->name('places.nearest')->middleware('throttle:30,1');
Route::post('/location', [LocationController::class, 'save'])->name('locations.save');
