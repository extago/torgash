<?php

use Illuminate\Support\Facades\Route;
use Torgash\Banners\Http\Admin\BannersController;
use Torgash\Banners\Http\Admin\SlidersController;
use Torgash\Banners\Http\Admin\TemplatesController;

// Loaded by BannersServiceProvider with prefix "admin/banners", name prefix
// "admin.banners." and `can:banners.manage`.

Route::get('/', [BannersController::class, 'index'])->name('index');
Route::get('create', [BannersController::class, 'create'])->name('create');
Route::post('/', [BannersController::class, 'store'])->name('store');
Route::post('bulk', [BannersController::class, 'bulk'])->name('bulk');

Route::get('sliders', [SlidersController::class, 'index'])->name('sliders.index');
Route::get('sliders/create', [SlidersController::class, 'create'])->name('sliders.create');
Route::post('sliders', [SlidersController::class, 'store'])->name('sliders.store');
Route::get('sliders/{slider}/edit', [SlidersController::class, 'edit'])->name('sliders.edit')->whereNumber('slider');
Route::put('sliders/{slider}', [SlidersController::class, 'update'])->name('sliders.update')->whereNumber('slider');
Route::delete('sliders/{slider}', [SlidersController::class, 'destroy'])->name('sliders.destroy')->whereNumber('slider');

// Templates are HTML and CSS printed on the storefront: their own right.
Route::middleware('can:banners.templates')->group(function () {
    Route::get('templates', [TemplatesController::class, 'index'])->name('templates.index');
    Route::get('templates/create', [TemplatesController::class, 'create'])->name('templates.create');
    Route::post('templates', [TemplatesController::class, 'store'])->name('templates.store');
    Route::post('templates/preview', [TemplatesController::class, 'preview'])->name('templates.preview');
    Route::post('templates/seed', [TemplatesController::class, 'seed'])->name('templates.seed');
    Route::post('templates/import', [TemplatesController::class, 'import'])->name('templates.import');
    Route::get('templates/{template}/edit', [TemplatesController::class, 'edit'])->name('templates.edit')->whereNumber('template');
    Route::put('templates/{template}', [TemplatesController::class, 'update'])->name('templates.update')->whereNumber('template');
    Route::delete('templates/{template}', [TemplatesController::class, 'destroy'])->name('templates.destroy')->whereNumber('template');
    Route::post('templates/{template}/restore', [TemplatesController::class, 'restore'])->name('templates.restore')->whereNumber('template');
    Route::get('templates/{template}/export', [TemplatesController::class, 'export'])->name('templates.export')->whereNumber('template');
});

Route::get('{banner}/edit', [BannersController::class, 'edit'])->name('edit')->whereNumber('banner');
Route::put('{banner}', [BannersController::class, 'update'])->name('update')->whereNumber('banner');
Route::delete('{banner}', [BannersController::class, 'destroy'])->name('destroy')->whereNumber('banner');
Route::post('{banner}/duplicate', [BannersController::class, 'duplicate'])->name('duplicate')->whereNumber('banner');
Route::patch('{banner}/status', [BannersController::class, 'setStatus'])->name('status')->whereNumber('banner');
