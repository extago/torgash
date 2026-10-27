<?php

use Illuminate\Support\Facades\Route;
use Torgash\Model3d\Http\Admin\ModelsController;

// Loaded by Model3dServiceProvider with prefix "admin/model3d", name prefix "admin.model3d.".

Route::get('product/{id}', [ModelsController::class, 'index'])->name('index')->whereNumber('id');
Route::post('product/{id}', [ModelsController::class, 'upload'])->name('upload')->whereNumber('id');
Route::post('product/{id}/{model}/poster', [ModelsController::class, 'poster'])->name('poster')->whereNumber(['id', 'model']);
Route::delete('product/{id}/{model}', [ModelsController::class, 'destroy'])->name('destroy')->whereNumber(['id', 'model']);
