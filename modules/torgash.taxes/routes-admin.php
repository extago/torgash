<?php

use Illuminate\Support\Facades\Route;
use Torgash\Taxes\Http\Admin\TaxesController;

// Loaded by TaxesServiceProvider with prefix "admin/taxes", name prefix "admin.taxes.".

Route::get('/', [TaxesController::class, 'index'])->name('index');
Route::post('bulk', [TaxesController::class, 'bulk'])->name('bulk');
Route::patch('{tax}/status', [TaxesController::class, 'setStatus'])->name('status');
Route::get('create', [TaxesController::class, 'create'])->name('create');
Route::post('/', [TaxesController::class, 'store'])->name('store');
Route::get('{tax}/edit', [TaxesController::class, 'edit'])->name('edit');
Route::put('{tax}', [TaxesController::class, 'update'])->name('update');
Route::delete('{tax}', [TaxesController::class, 'destroy'])->name('destroy');
