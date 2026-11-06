<?php

use Illuminate\Support\Facades\Route;
use Torgash\Files\Http\Admin\FilesController;

// Loaded by FilesServiceProvider with prefix "admin/files", name prefix
// "admin.files.", behind `can:files.manage`.

Route::get('/', [FilesController::class, 'index'])->name('index');
Route::post('upload', [FilesController::class, 'upload'])->name('upload');
Route::post('folders', [FilesController::class, 'createFolder'])->name('folders.store');
Route::patch('folders/{folder}', [FilesController::class, 'renameFolder'])->whereNumber('folder')->name('folders.update');
Route::delete('folders/{folder}', [FilesController::class, 'deleteFolder'])->whereNumber('folder')->name('folders.destroy');
Route::patch('{file}', [FilesController::class, 'rename'])->whereNumber('file')->name('rename');
Route::delete('{file}', [FilesController::class, 'destroy'])->whereNumber('file')->name('destroy');
Route::post('bulk', [FilesController::class, 'bulk'])->name('bulk');
