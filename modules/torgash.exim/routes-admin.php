<?php

use Illuminate\Support\Facades\Route;
use Torgash\Exim\Http\Admin\ImportController;
use Torgash\Exim\Http\Admin\ProfilesController;

Route::post('profiles/{profile}/run', [ProfilesController::class, 'run'])->name('profiles.run');
Route::post('profiles/{profile}/rebuild', [ProfilesController::class, 'rebuild'])->name('profiles.rebuild');
Route::get('profiles/schedule', [ProfilesController::class, 'schedule'])->name('profiles.schedule');
Route::post('profiles/bulk', [ProfilesController::class, 'bulk'])->name('profiles.bulk');
Route::patch('profiles/{profile}/status', [ProfilesController::class, 'setStatus'])->name('profiles.status');
Route::resource('profiles', ProfilesController::class)->except(['show']);

Route::get('import', [ImportController::class, 'index'])->name('import.index');
Route::post('import/upload', [ImportController::class, 'upload'])->name('import.upload');
Route::get('import/{token}/map', [ImportController::class, 'map'])->name('import.map');
Route::post('import/{token}/save', [ImportController::class, 'save'])->name('import.save');
Route::post('import/{token}/formula', [ImportController::class, 'formula'])->name('import.formula');
Route::post('import/preview', [ImportController::class, 'preview'])->name('import.preview');
Route::post('import/commit', [ImportController::class, 'commit'])->name('import.commit');
Route::delete('import/drafts/{draft}', [ImportController::class, 'destroy'])->name('import.destroy');
Route::delete('import/presets/{preset}', [ImportController::class, 'destroyPreset'])->name('import.presets.destroy');
