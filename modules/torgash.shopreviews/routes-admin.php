<?php

use Illuminate\Support\Facades\Route;
use Torgash\ShopReviews\Http\Admin\CriteriaController;
use Torgash\ShopReviews\Http\Admin\PlatformsController;
use Torgash\ShopReviews\Http\Admin\ReviewsController;

// Loaded with prefix "admin/shop-reviews", name prefix "admin.shopreviews.".

Route::get('/', [ReviewsController::class, 'index'])->name('index');
// Words before "{review}/…", so they are not read as an id.
Route::get('create', [ReviewsController::class, 'create'])->name('create');
Route::post('/', [ReviewsController::class, 'store'])->name('store');
Route::post('bulk', [ReviewsController::class, 'bulk'])->name('bulk');

Route::get('criteria', [CriteriaController::class, 'index'])->name('criteria.index');
Route::get('criteria/create', [CriteriaController::class, 'create'])->name('criteria.create');
Route::post('criteria', [CriteriaController::class, 'store'])->name('criteria.store');
Route::get('criteria/{criterion}/edit', [CriteriaController::class, 'edit'])->name('criteria.edit');
Route::patch('criteria/{criterion}/status', [CriteriaController::class, 'setStatus'])->name('criteria.status');
Route::put('criteria/{criterion}', [CriteriaController::class, 'update'])->name('criteria.update');
Route::delete('criteria/{criterion}', [CriteriaController::class, 'destroy'])->name('criteria.destroy');

Route::get('platforms', [PlatformsController::class, 'index'])->name('platforms.index');
Route::get('platforms/create', [PlatformsController::class, 'create'])->name('platforms.create');
Route::post('platforms', [PlatformsController::class, 'store'])->name('platforms.store');
Route::get('platforms/{platform}/edit', [PlatformsController::class, 'edit'])->name('platforms.edit');
Route::put('platforms/{platform}', [PlatformsController::class, 'update'])->name('platforms.update');
Route::delete('platforms/{platform}', [PlatformsController::class, 'destroy'])->name('platforms.destroy');

Route::patch('{review}/status', [ReviewsController::class, 'status'])->name('status');
Route::post('{review}/reply', [ReviewsController::class, 'reply'])->name('reply');
Route::delete('{review}', [ReviewsController::class, 'destroy'])->name('destroy');
