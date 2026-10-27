<?php

use Illuminate\Support\Facades\Route;
use Torgash\Kits\Http\Admin\KitsController;

// Written out rather than a resource: this module's own prefix IS the
// collection (`/admin/kits`), and a resource named "" reads worse than six
// lines that say what they do.
Route::get('/', [KitsController::class, 'index'])->name('index');
Route::get('/create', [KitsController::class, 'create'])->name('create');
Route::post('/', [KitsController::class, 'store'])->name('store');
Route::get('/{kit}/edit', [KitsController::class, 'edit'])->name('edit');
Route::patch('/{kit}/status', [KitsController::class, 'setStatus'])->name('status');
Route::post('/bulk', [KitsController::class, 'bulk'])->name('bulk');
Route::put('/{kit}', [KitsController::class, 'update'])->name('update');
Route::delete('/{kit}', [KitsController::class, 'destroy'])->name('destroy');
