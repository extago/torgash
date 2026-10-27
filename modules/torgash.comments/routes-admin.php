<?php

use Illuminate\Support\Facades\Route;
use Torgash\Comments\Http\Admin\CommentsController;

// Loaded by CommentsServiceProvider with prefix "admin/comments", name prefix "admin.comments.".

Route::get('/', [CommentsController::class, 'index'])->name('index');
Route::post('bulk', [CommentsController::class, 'bulk'])->name('bulk');
Route::patch('{comment}/status', [CommentsController::class, 'status'])->name('status');
Route::post('{comment}/reply', [CommentsController::class, 'reply'])->name('reply');
Route::delete('{comment}', [CommentsController::class, 'destroy'])->name('destroy');
