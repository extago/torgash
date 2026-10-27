<?php

use Illuminate\Support\Facades\Route;
use Torgash\Sku\Http\Admin\SkuController;

// Loaded by SkuServiceProvider with prefix "admin/sku", name prefix "admin.sku.".

Route::get('/', [SkuController::class, 'index'])->name('index');
Route::post('/', [SkuController::class, 'save'])->name('save');
Route::post('renumber', [SkuController::class, 'renumber'])->name('renumber');
