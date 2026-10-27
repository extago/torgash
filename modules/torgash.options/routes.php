<?php

use Illuminate\Support\Facades\Route;
use Torgash\Options\Http\QuoteController;
use Torgash\Options\Http\SetController;

// What the build costs. A POST because it carries the whole form, and inside
// StorefrontRoutes like every other storefront address, so `/ru/...` works and
// the price comes back in the visitor's own language and currency.
Route::post('/options/quote/{product:slug}', QuoteController::class)->name('options.quote');

// The two fragments a set-of-products answer needs: its narrowed list, and one
// more row of a "rows you add" question. GETs, because both only read the
// catalogue — and the narrowing has to be the server's, since a set IS a
// catalogue query.
Route::get('/options/set/{choice}', [SetController::class, 'set'])->name('options.set');
Route::get('/options/row/{group}', [SetController::class, 'row'])->name('options.row');