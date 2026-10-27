<?php

use App\Http\Controllers\InstallController;
use Illuminate\Support\Facades\Route;

// The web installer (PLAN §54). Outside the `web` group on purpose: before the
// install there is no application key, so no cookie, no session and no CSRF
// token can exist. What proves the visitor is the owner is the install code
// in a file on the server (InstallController). Every route answers 404 once
// the shop is installed.
Route::prefix('install')->name('install.')->group(function () {
    Route::get('/', [InstallController::class, 'show'])->name('show');
    Route::post('check', [InstallController::class, 'check'])->name('check');
    Route::post('start', [InstallController::class, 'start'])->name('start');
    Route::post('step', [InstallController::class, 'step'])->name('step');
});
