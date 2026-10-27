<?php

use Illuminate\Support\Facades\Route;
use Torgash\Speed\Http\Admin\SpeedController;

// Loaded by SpeedServiceProvider with prefix "admin/speed", name prefix "admin.speed.".

Route::get('/', [SpeedController::class, 'index'])->name('index');
Route::get('page', [SpeedController::class, 'route'])->name('route');
Route::get('trace/{trace}', [SpeedController::class, 'trace'])->whereNumber('trace')->name('trace');
Route::post('trace-me', [SpeedController::class, 'traceMe'])->name('trace-me');
Route::post('trace-me/stop', [SpeedController::class, 'stopTraceMe'])->name('trace-me.stop');
