<?php

use Illuminate\Support\Facades\Route;
use Torgash\Reviews\Http\Admin\CriteriaController;
use Torgash\Reviews\Http\Admin\ReviewsController;

// Loaded by ReviewsServiceProvider with prefix "admin/reviews", name prefix
// "admin.reviews.".

Route::get('/', [ReviewsController::class, 'index'])->name('index');
// Declared before "{review}/…" so the word is not read as a review's id.
Route::post('recount', [ReviewsController::class, 'recount'])->name('recount');
Route::post('bulk', [ReviewsController::class, 'bulk'])->name('bulk');
Route::patch('{review}/status', [ReviewsController::class, 'status'])->name('status');
Route::post('{review}/reply', [ReviewsController::class, 'reply'])->name('reply');
Route::delete('{review}', [ReviewsController::class, 'destroy'])->name('destroy');

// The shop's own list of what it asks people to rate.
Route::get('criteria', [CriteriaController::class, 'index'])->name('criteria.index');
Route::get('criteria/create', [CriteriaController::class, 'create'])->name('criteria.create');
Route::post('criteria', [CriteriaController::class, 'store'])->name('criteria.store');
Route::get('criteria/{criterion}/edit', [CriteriaController::class, 'edit'])->name('criteria.edit');
Route::patch('criteria/{criterion}/status', [CriteriaController::class, 'setStatus'])->name('criteria.status');
Route::put('criteria/{criterion}', [CriteriaController::class, 'update'])->name('criteria.update');
Route::delete('criteria/{criterion}', [CriteriaController::class, 'destroy'])->name('criteria.destroy');
