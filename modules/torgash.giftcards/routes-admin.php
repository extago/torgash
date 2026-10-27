<?php

use Illuminate\Support\Facades\Route;
use Torgash\Giftcards\Http\Admin\CardsController;
use Torgash\Giftcards\Http\Admin\OrderCardsController;

Route::get('/', [CardsController::class, 'index'])->name('index');
Route::get('/create', [CardsController::class, 'create'])->name('create');
Route::post('/', [CardsController::class, 'store'])->name('store');
Route::get('/export', [CardsController::class, 'export'])->name('export');
Route::get('/{card}', [CardsController::class, 'show'])->name('show')->whereNumber('card');
Route::put('/{card}', [CardsController::class, 'update'])->name('update')->whereNumber('card');
Route::post('/{card}/adjust', [CardsController::class, 'adjust'])->name('adjust')->whereNumber('card');
Route::post('/{card}/activate', [CardsController::class, 'activate'])->name('activate')->whereNumber('card');
Route::post('/{card}/status', [CardsController::class, 'status'])->name('status')->whereNumber('card');
Route::post('/{card}/send', [CardsController::class, 'send'])->name('send')->whereNumber('card');
Route::get('/{card}/code', [CardsController::class, 'code'])->name('code')->whereNumber('card');

Route::post('/orders/{order:order_number}/attach', [OrderCardsController::class, 'attach'])->name('orders.attach');
Route::post('/orders/{order:order_number}/detach/{card}', [OrderCardsController::class, 'detach'])->name('orders.detach')->whereNumber('card');
