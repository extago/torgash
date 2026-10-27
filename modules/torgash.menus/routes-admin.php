<?php

use Illuminate\Support\Facades\Route;
use Torgash\Menus\Http\Admin\MenusController;

// Loaded by MenusServiceProvider with prefix "admin/menus", name prefix "admin.menus.".

Route::get('/', [MenusController::class, 'index'])->name('index');
Route::get('create', [MenusController::class, 'create'])->name('create');
Route::post('/', [MenusController::class, 'store'])->name('store');
Route::post('bulk', [MenusController::class, 'bulk'])->name('bulk');
Route::patch('{menu}/status', [MenusController::class, 'setStatus'])->name('status');
Route::get('{menu}/edit', [MenusController::class, 'edit'])->name('edit');
Route::put('{menu}', [MenusController::class, 'update'])->name('update');
Route::delete('{menu}', [MenusController::class, 'destroy'])->name('destroy');

// Item tree of one menu.
Route::get('{menu}/items', [MenusController::class, 'items'])->name('items');
Route::post('{menu}/items/arrange', [MenusController::class, 'arrangeItems'])->name('items.arrange');
Route::get('{menu}/items/preview', [MenusController::class, 'previewItem'])->name('items.preview');
Route::get('{menu}/items/create', [MenusController::class, 'createItem'])->name('items.create');
Route::post('{menu}/items', [MenusController::class, 'storeItem'])->name('items.store');
Route::get('{menu}/items/{item}/edit', [MenusController::class, 'editItem'])->name('items.edit');
Route::put('{menu}/items/{item}', [MenusController::class, 'updateItem'])->name('items.update');
Route::post('{menu}/items/{item}/move', [MenusController::class, 'moveItem'])->name('items.move');
Route::delete('{menu}/items/{item}', [MenusController::class, 'destroyItem'])->name('items.destroy');
