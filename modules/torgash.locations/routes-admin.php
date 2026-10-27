<?php

use Illuminate\Support\Facades\Route;
use Torgash\Locations\Http\Admin\CountriesController;
use Torgash\Locations\Http\Admin\ImportController;
use Torgash\Locations\Http\Admin\PlacesController;
use Torgash\Locations\Http\Admin\StatesController;
use Torgash\Locations\Http\SuggestController;

// Loaded by LocationsServiceProvider with prefix "admin/locations", name prefix "admin.locations.".

Route::get('/', [PlacesController::class, 'index'])->name('index');
Route::get('suggest', SuggestController::class)->name('suggest');
Route::get('create', [PlacesController::class, 'create'])->name('create');
Route::post('/', [PlacesController::class, 'store'])->name('store');
Route::post('bulk', [PlacesController::class, 'bulk'])->name('bulk');

// All of these must come before the {place} routes, or "countries" would be
// matched as a place id.
Route::get('countries', [CountriesController::class, 'index'])->name('countries');
Route::get('countries/create', [CountriesController::class, 'create'])->name('countries.create');
Route::post('countries/new', [CountriesController::class, 'store'])->name('countries.store');
Route::get('countries/{country}/edit', [CountriesController::class, 'edit'])->name('countries.edit');
Route::put('countries/{country}', [CountriesController::class, 'update'])->name('countries.update');
Route::delete('countries/{country}', [CountriesController::class, 'destroy'])->name('countries.destroy');
Route::post('countries/import', [CountriesController::class, 'import'])->name('countries.import');
Route::post('countries/bulk', [CountriesController::class, 'bulk'])->name('countries.bulk');
Route::post('countries/{country}/toggle', [CountriesController::class, 'toggle'])->name('countries.toggle');
Route::get('countries/{code}/purge', [CountriesController::class, 'confirmPurge'])->name('countries.purge.confirm');
Route::post('countries/purge', [CountriesController::class, 'purge'])->name('countries.purge');

Route::get('regions', [StatesController::class, 'index'])->name('regions');
Route::get('regions/create', [StatesController::class, 'create'])->name('regions.create');
Route::post('regions', [StatesController::class, 'store'])->name('regions.store');
Route::get('regions/{state}/edit', [StatesController::class, 'edit'])->name('regions.edit');
Route::put('regions/{state}', [StatesController::class, 'update'])->name('regions.update');
Route::delete('regions/{state}', [StatesController::class, 'destroy'])->name('regions.destroy');
Route::post('regions/bulk', [StatesController::class, 'bulk'])->name('regions.bulk');
Route::post('regions/{state}/toggle', [StatesController::class, 'toggle'])->name('regions.toggle');

Route::get('import', [ImportController::class, 'index'])->name('import');
Route::post('import/folder', [ImportController::class, 'fromFolder'])->name('import.folder');
Route::post('import/upload', [ImportController::class, 'upload'])->name('import.upload');

Route::get('{place}/edit', [PlacesController::class, 'edit'])->name('edit');
Route::put('{place}', [PlacesController::class, 'update'])->name('update');
Route::post('{place}/toggle', [PlacesController::class, 'toggle'])->name('toggle');
Route::delete('{place}', [PlacesController::class, 'destroy'])->name('destroy');
