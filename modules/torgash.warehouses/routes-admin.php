<?php

use Illuminate\Support\Facades\Route;
use Torgash\Warehouses\Http\Admin\WarehousesController;
use Torgash\Warehouses\Http\Admin\WarehouseStockController;

// Loaded by WarehousesServiceProvider with prefix "admin/warehouses", name prefix "admin.warehouses.".

Route::get('/', [WarehousesController::class, 'index'])->name('index');
Route::get('create', [WarehousesController::class, 'create'])->name('create');
Route::post('/', [WarehousesController::class, 'store'])->name('store');
Route::post('bulk', [WarehousesController::class, 'bulk'])->name('bulk');
Route::patch('{warehouse}/status', [WarehousesController::class, 'setStatus'])->name('status');
Route::get('{warehouse}/edit', [WarehousesController::class, 'edit'])->name('edit');
Route::put('{warehouse}', [WarehousesController::class, 'update'])->name('update');
Route::delete('{warehouse}', [WarehousesController::class, 'destroy'])->name('destroy');

Route::get('{warehouse}/stock', [WarehouseStockController::class, 'index'])->name('stock');
Route::post('{warehouse}/stock', [WarehouseStockController::class, 'update'])->name('stock.update');
