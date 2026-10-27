<?php

use Illuminate\Support\Facades\Route;
use Torgash\Options\Http\Admin\GroupsController;

// Both actions a PRODUCT takes about a shared group. Before the resource, so
// neither is read as a group called "exclude".
Route::post('groups/{group}/exclude', [GroupsController::class, 'toggleExclusion'])->name('groups.exclude');
Route::post('groups/{group}/copy', [GroupsController::class, 'copyToProduct'])->name('groups.copy');
Route::patch('groups/{group}/status', [GroupsController::class, 'setStatus'])->name('groups.status');
Route::post('groups/bulk', [GroupsController::class, 'bulk'])->name('groups.bulk');
Route::resource('groups', GroupsController::class)->except(['show']);
