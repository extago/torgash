<?php

use Illuminate\Support\Facades\Route;
use Torgash\Pages\Http\Admin\PagesController;

// Loaded by PagesServiceProvider with prefix "admin/pages", name prefix "admin.pages.".

Route::get('/', [PagesController::class, 'index'])->name('index');
Route::get('create', [PagesController::class, 'create'])->name('create');
Route::post('/', [PagesController::class, 'store'])->name('store');
Route::post('bulk', [PagesController::class, 'bulk'])->name('bulk');
Route::patch('{page}/status', [PagesController::class, 'setStatus'])->name('status');
Route::get('{page}/edit', [PagesController::class, 'edit'])->name('edit');
Route::put('{page}', [PagesController::class, 'update'])->name('update');
Route::delete('{page}', [PagesController::class, 'destroy'])->name('destroy');
