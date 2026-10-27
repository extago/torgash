<?php

use Illuminate\Support\Facades\Route;
use Torgash\Blog\Http\Admin\PostsController;
use Torgash\Blog\Http\Admin\RubricsController;

// Loaded by BlogServiceProvider with prefix "admin/blog", name prefix "admin.blog.".
// Rubrics first: "rubrics" must never be read as an article's id.

Route::get('rubrics', [RubricsController::class, 'index'])->name('rubrics.index');
Route::get('rubrics/create', [RubricsController::class, 'create'])->name('rubrics.create');
Route::post('rubrics', [RubricsController::class, 'store'])->name('rubrics.store');
Route::get('rubrics/{rubric}/edit', [RubricsController::class, 'edit'])->name('rubrics.edit');
Route::put('rubrics/{rubric}', [RubricsController::class, 'update'])->name('rubrics.update');
Route::delete('rubrics/{rubric}', [RubricsController::class, 'destroy'])->name('rubrics.destroy');

Route::get('/', [PostsController::class, 'index'])->name('index');
Route::get('create', [PostsController::class, 'create'])->name('create');
Route::post('/', [PostsController::class, 'store'])->name('store');
Route::get('{post}/edit', [PostsController::class, 'edit'])->name('edit');
Route::put('{post}', [PostsController::class, 'update'])->name('update');
Route::delete('{post}', [PostsController::class, 'destroy'])->name('destroy');
