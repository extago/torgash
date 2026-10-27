<?php

use Illuminate\Support\Facades\Route;
use Torgash\CommerceMl\Http\Admin\ExchangeScreenController;

Route::get('/', [ExchangeScreenController::class, 'index'])->name('index');
Route::get('/matching/{kind}', [ExchangeScreenController::class, 'matching'])->name('matching');
Route::post('/matching/{kind}', [ExchangeScreenController::class, 'save'])->name('matching.save');
Route::post('/language', [ExchangeScreenController::class, 'language'])->name('language');
Route::post('/legacy', [ExchangeScreenController::class, 'legacy'])->name('legacy');
Route::get('/orders', [ExchangeScreenController::class, 'orders'])->name('orders');
Route::post('/orders', [ExchangeScreenController::class, 'saveOrders'])->name('orders.save');
