<?php

use Illuminate\Support\Facades\Route;
use Torgash\Comments\Http\CommentController;

// Registered through StorefrontRoutes::group() — once per locale shape, so the
// form on /ru/info/delivery posts to a route that stays Russian on the way back.

Route::post('/comments', [CommentController::class, 'store'])->name('comments.store');
