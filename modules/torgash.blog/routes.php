<?php

use Illuminate\Support\Facades\Route;
use Torgash\Blog\Http\BlogController;

// Loaded through StorefrontRoutes::group(): once bare and once per non-default
// locale, like every storefront page.

Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/rubric/{slug}', [BlogController::class, 'rubric'])->name('blog.rubric');

// The text rendition BEFORE the article: a slug matches `[^/]+`, which would
// swallow ".md" — the catalogue's own lesson.
Route::get('/blog/{slug}.md', [BlogController::class, 'text'])->middleware('throttle:torgash-agent')->name('blog.post.text');
Route::get('/blog/{slug}', [BlogController::class, 'post'])->name('blog.post');
Route::post('/blog/{slug}/like', [BlogController::class, 'like'])->middleware('throttle:30,1')->name('blog.like');
