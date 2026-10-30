<?php

use Illuminate\Support\Facades\Route;
use Torgash\Catalog\Http\Admin\CategoriesController;
use Torgash\Catalog\Http\Admin\ProductsController;

// What a reader of the catalogue opens (catalog.view, which catalog.manage
// includes): the lists, a product's card and the lookup the order editor
// and other pickers ask. Everything that changes a row stays in
// routes-admin.php under catalog.manage; the views hide those controls
// from a reader (access.md).
Route::get('products/lookup', [ProductsController::class, 'lookup'])->name('products.lookup');
Route::get('products', [ProductsController::class, 'index'])->name('products.index');
Route::get('products/{product}/edit', [ProductsController::class, 'edit'])->name('products.edit');
Route::get('categories', [CategoriesController::class, 'index'])->name('categories.index');
