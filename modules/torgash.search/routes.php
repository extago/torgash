<?php

use Illuminate\Support\Facades\Route;
use Torgash\Search\Http\SuggestController;

// Registered through StorefrontRoutes::group(): once bare and once per locale
// prefix, so the window answers in the language of the page it is on.

Route::get('/search/suggest', SuggestController::class)->name('search.suggest')->middleware('throttle:torgash-search');
Route::post('/search/recent/forget', [SuggestController::class, 'forget'])->name('search.recent.forget');
