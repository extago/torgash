<?php

use Illuminate\Support\Facades\Route;
use Torgash\Kits\Http\KitController;

// The card's own addresses, inside StorefrontRoutes like every other storefront
// address, so `/ru/...` works and prices come back in the visitor's own
// currency. `quote` is a POST because it carries the whole form; the other two
// only read the catalogue.
Route::post('/kits/{kit}/add', [KitController::class, 'add'])->name('kits.add');
Route::post('/kits/{kit}/quote', [KitController::class, 'quote'])->name('kits.quote');
Route::get('/kits/{kit}/slots/{slot}/picker', [KitController::class, 'picker'])->name('kits.picker');
Route::get('/kits/{kit}/slots/{slot}', [KitController::class, 'slot'])->name('kits.slot');
