<?php

use Illuminate\Support\Facades\Route;
use Torgash\Motivation\Http\Admin\MotivationItemsController;

// Loaded by MotivationServiceProvider with prefix "admin/motivation", name prefix
// "admin.motivation.". Spelled out rather than Route::resource('', ...): an empty
// resource name is untested territory (Laravel joins it into the route name with
// a stray extra dot), and the module's own prefix already reads as the resource.

Route::get('/', [MotivationItemsController::class, 'index'])->name('index');
Route::get('create', [MotivationItemsController::class, 'create'])->name('create');
Route::post('/', [MotivationItemsController::class, 'store'])->name('store');
Route::get('{item}/edit', [MotivationItemsController::class, 'edit'])->name('edit');
Route::put('{item}', [MotivationItemsController::class, 'update'])->name('update');
Route::delete('{item}', [MotivationItemsController::class, 'destroy'])->name('destroy');
Route::post('{item}/duplicate', [MotivationItemsController::class, 'duplicate'])->name('duplicate');
Route::patch('{item}/status', [MotivationItemsController::class, 'setStatus'])->name('status');
Route::post('bulk', [MotivationItemsController::class, 'bulk'])->name('bulk');
