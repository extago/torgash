<?php

use Illuminate\Support\Facades\Route;
use Torgash\Points\Http\PointsController;

// Registered through StorefrontRoutes::group(): in every locale shape.
Route::middleware('auth')->group(function () {
    Route::post('/cart/points', [PointsController::class, 'spend'])->name('points.spend');
    Route::post('/cart/points/lines/{key}', [PointsController::class, 'toggleLine'])->name('points.line')->where('key', '[A-Za-z0-9\-]+');
    Route::get('/account/points', [PointsController::class, 'account'])->name('account.points.index');
});
