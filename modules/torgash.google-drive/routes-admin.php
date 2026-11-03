<?php

use Illuminate\Support\Facades\Route;
use Torgash\GoogleDrive\Http\ConnectController;

// Loaded by GoogleDriveServiceProvider: prefix "admin/google-drive", name "admin.google-drive.".

// Signing a storage in with a Google account: a POST sends the owner to
// Google (a GET would be followed by a link prefetcher), Google sends the
// owner back to the callback with a code, which is swapped for the key the
// storage keeps. The buttons sit inside the storage's own form and send it
// here with formaction — along with its `_method=PUT` on an existing
// storage, which is why PUT is taken too.
Route::match(['post', 'put'], 'connect/{destination}', [ConnectController::class, 'connect'])->name('connect');
Route::get('callback', [ConnectController::class, 'callback'])->name('callback');
Route::match(['post', 'put'], 'disconnect/{destination}', [ConnectController::class, 'disconnect'])->name('disconnect');
