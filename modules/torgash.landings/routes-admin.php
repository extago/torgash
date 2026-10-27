<?php

use Illuminate\Support\Facades\Route;
use Torgash\Landings\Http\Admin\LandingsController;

// Loaded by LandingsServiceProvider with prefix "admin/landings", name prefix "admin.landings.".

Route::get('/', [LandingsController::class, 'index'])->name('index');
Route::get('create', [LandingsController::class, 'create'])->name('create');
Route::post('/', [LandingsController::class, 'store'])->name('store');
Route::post('import', [LandingsController::class, 'import'])->name('import.new');
// The product pickers' answer to what the admin types (Catalog\Admin\ProductLookup).
Route::get('products', [LandingsController::class, 'products'])->name('products');
Route::get('{landing}/edit', [LandingsController::class, 'edit'])->name('edit');
Route::put('{landing}', [LandingsController::class, 'update'])->name('update');
Route::delete('{landing}', [LandingsController::class, 'destroy'])->name('destroy');
Route::post('{landing}/copy', [LandingsController::class, 'copy'])->name('copy');
Route::post('{landing}/toggle', [LandingsController::class, 'toggle'])->name('toggle');
Route::post('{landing}/files', [LandingsController::class, 'upload'])->name('files.upload');
Route::delete('{landing}/files', [LandingsController::class, 'deleteFile'])->name('files.delete');
Route::post('{landing}/import', [LandingsController::class, 'import'])->name('import');
Route::get('{landing}/export', [LandingsController::class, 'export'])->name('export');
