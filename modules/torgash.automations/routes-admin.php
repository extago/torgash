<?php

use Illuminate\Support\Facades\Route;
use Torgash\Automations\Http\Admin\CodeChannelsController;
use Torgash\Automations\Http\Admin\RulesController;
use Torgash\Automations\Http\Admin\RunsController;
use Torgash\Automations\Http\Admin\TemplatesController;

// Loaded by AutomationsServiceProvider with prefix "admin/automations",
// name prefix "admin.automations.".

// Before the wildcard {rule} routes, so "runs" is never read as a rule id.
Route::get('runs', [RunsController::class, 'index'])->name('runs');

// Ways to deliver a verification code by an HTTP request (PLAN §44).
Route::prefix('channels')->name('channels.')->group(function () {
    Route::get('/', [CodeChannelsController::class, 'index'])->name('index');
    Route::get('create', [CodeChannelsController::class, 'create'])->name('create');
    Route::post('/', [CodeChannelsController::class, 'store'])->name('store');
    Route::get('{channel}/edit', [CodeChannelsController::class, 'edit'])->name('edit');
    Route::put('{channel}', [CodeChannelsController::class, 'update'])->name('update');
    Route::delete('{channel}', [CodeChannelsController::class, 'destroy'])->name('destroy');
    Route::patch('{channel}/status', [CodeChannelsController::class, 'setStatus'])->name('status');
    Route::post('{channel}/check', [CodeChannelsController::class, 'check'])->name('check');
    Route::post('{channel}/test', [CodeChannelsController::class, 'test'])->name('test');
});

Route::get('import', [TemplatesController::class, 'form'])->name('import');
Route::post('import/review', [TemplatesController::class, 'review'])->name('import.review');
Route::post('import', [TemplatesController::class, 'store'])->name('import.store');

Route::post('bulk', [RulesController::class, 'bulk'])->name('bulk');

Route::get('/', [RulesController::class, 'index'])->name('index');
Route::get('create', [RulesController::class, 'create'])->name('create');
Route::post('/', [RulesController::class, 'store'])->name('store');
Route::get('{rule}/edit', [RulesController::class, 'edit'])->name('edit');
Route::put('{rule}', [RulesController::class, 'update'])->name('update');
Route::delete('{rule}', [RulesController::class, 'destroy'])->name('destroy');
Route::patch('{rule}/status', [RulesController::class, 'setStatus'])->name('status');

// Fire a rule at a real order, now, without waiting for one to happen.
Route::post('{rule}/test', [RulesController::class, 'test'])->name('test');

Route::get('{rule}/export', [TemplatesController::class, 'export'])->name('export');
