<?php

use Illuminate\Support\Facades\Route;
use Torgash\Collections\Http\Admin\CollectionsController;

// Loaded by CollectionsServiceProvider with prefix "admin/collections", name prefix "admin.collections.".

Route::get('/', [CollectionsController::class, 'index'])->name('index');
Route::get('create', [CollectionsController::class, 'create'])->name('create');
Route::post('/', [CollectionsController::class, 'store'])->name('store');
// The product pickers' answer to what the admin types (Catalog\Admin\ProductLookup).
Route::get('products', [CollectionsController::class, 'products'])->name('products');
Route::get('{collection}/edit', [CollectionsController::class, 'edit'])->name('edit');
Route::put('{collection}', [CollectionsController::class, 'update'])->name('update');
Route::delete('{collection}', [CollectionsController::class, 'destroy'])->name('destroy');
