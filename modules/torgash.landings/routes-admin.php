<?php

use Illuminate\Support\Facades\Route;
use Torgash\Landings\Http\Admin\LandingsController;

// Loaded by LandingsServiceProvider with prefix "admin/landings", name prefix "admin.landings.".

Route::get('/', [LandingsController::class, 'index'])->name('index');
Route::get('create', [LandingsController::class, 'create'])->name('create');
Route::post('/', [LandingsController::class, 'store'])->name('store');
Route::post('import', [LandingsController::class, 'import'])->name('import.new');
Route::post('bulk', [LandingsController::class, 'bulk'])->name('bulk');
// The product pickers' answer to what the admin types (Catalog\Admin\ProductLookup).
Route::get('products', [LandingsController::class, 'products'])->name('products');
Route::get('{landing}/edit', [LandingsController::class, 'edit'])->name('edit');
Route::put('{landing}', [LandingsController::class, 'update'])->name('update');
Route::delete('{landing}', [LandingsController::class, 'destroy'])->name('destroy');
Route::post('{landing}/copy', [LandingsController::class, 'copy'])->name('copy');
// The list's in-place switch, answered with its cell.
Route::patch('{landing}/status', [LandingsController::class, 'status'])->name('status');
// On the product asked for (?product=), else on one it is for (PreviewProduct).
Route::get('{landing}/preview', [LandingsController::class, 'preview'])->name('preview');
Route::post('{landing}/files', [LandingsController::class, 'upload'])->name('files.upload');
Route::delete('{landing}/files', [LandingsController::class, 'deleteFile'])->name('files.delete');
Route::post('{landing}/import', [LandingsController::class, 'import'])->name('import');
Route::get('{landing}/export', [LandingsController::class, 'export'])->name('export');
