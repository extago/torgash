<?php

use Illuminate\Support\Facades\Route;
use Torgash\Snippets\Http\Admin\SnippetsController;

// Loaded by SnippetsServiceProvider with prefix "admin/snippets", name prefix
// "admin.snippets.".

Route::get('/', [SnippetsController::class, 'index'])->name('index');
Route::get('create', [SnippetsController::class, 'create'])->name('create');
Route::post('/', [SnippetsController::class, 'store'])->name('store');
Route::get('{snippet}/edit', [SnippetsController::class, 'edit'])->name('edit');
Route::put('{snippet}', [SnippetsController::class, 'update'])->name('update');
Route::delete('{snippet}', [SnippetsController::class, 'destroy'])->name('destroy');
Route::post('{snippet}/duplicate', [SnippetsController::class, 'duplicate'])->name('duplicate');
Route::patch('{snippet}/status', [SnippetsController::class, 'setStatus'])->name('status');
Route::post('bulk', [SnippetsController::class, 'bulk'])->name('bulk');
