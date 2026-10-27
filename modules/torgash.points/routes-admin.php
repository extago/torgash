<?php

use Illuminate\Support\Facades\Route;
use Torgash\Points\Http\Admin\PointsAdminController;

Route::get('/', [PointsAdminController::class, 'index'])->name('index');
Route::post('/rules', [PointsAdminController::class, 'storeRule'])->name('rules.store');
Route::delete('/rules/{rule}', [PointsAdminController::class, 'destroyRule'])->name('rules.destroy')->whereNumber('rule');
Route::post('/adjust', [PointsAdminController::class, 'adjust'])->name('adjust');
Route::post('/orders/{order:order_number}/earn', [PointsAdminController::class, 'orderEarn'])->name('orders.earn');
