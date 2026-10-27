<?php

use Illuminate\Support\Facades\Route;
use Torgash\ShopReviews\Http\ShopReviewController;

// Through StorefrontRoutes::group(), once per locale shape.

Route::get('/reviews', [ShopReviewController::class, 'index'])->name('shopreviews.index');
Route::post('/reviews', [ShopReviewController::class, 'store'])->name('shopreviews.store');
