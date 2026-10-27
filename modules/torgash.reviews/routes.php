<?php

use Illuminate\Support\Facades\Route;
use Torgash\Reviews\Http\ReviewController;

// Through StorefrontRoutes::group(), once per locale shape — a review written
// on /ru/product/x posts to a route that stays Russian on the way back.
//
// `auth` on the route as well as in Eligibility: a guest never reaches the
// form, so a guest reaching the endpoint is not a person to explain anything
// to.

Route::middleware('auth')->post('/product/{product}/review', [ReviewController::class, 'store'])->name('reviews.store');

// Answering one. A review is a conversation, and its own author is usually the
// person with the next thing to say — see PostReply.
Route::middleware('auth')->post('/review/{review}/reply', [ReviewController::class, 'reply'])->name('reviews.reply');

// "Was this review helpful?" — a POST, because it writes, and signed in for
// the reason the module refuses guest reviews: a vote counted per session is
// one a script casts by clearing a cookie.
Route::middleware('auth')->post('/review/{review}/helpful', [ReviewController::class, 'helpful'])->name('reviews.helpful');

// The list on its own, for changing the order without reloading a page the
// reader has just finished scrolling. A GET, and open to everybody: it shows
// exactly what the product page already shows.
Route::get('/product/{product}/reviews', [ReviewController::class, 'list'])->name('reviews.list');
