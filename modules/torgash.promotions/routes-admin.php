<?php

use Illuminate\Support\Facades\Route;
use Torgash\Promotions\Http\Admin\PromotionsController;
use Torgash\Promotions\Http\Admin\UsageController;

// Loaded by PromotionsServiceProvider with prefix "admin/promotions", name prefix "admin.promotions.".

// "Recheck the promotions" on a placed order (PLAN §40.6/4): the difference
// first, then — on a second press — applied.
Route::post('orders/{order:order_number}/recheck', [\Torgash\Promotions\Http\Admin\OrderRecheckController::class, 'preview'])->name('orders.recheck');
Route::post('orders/{order:order_number}/reapply', [\Torgash\Promotions\Http\Admin\OrderRecheckController::class, 'apply'])->name('orders.reapply');

// Before the wildcard {promotion} routes, so "usage" is never read as an id.
Route::get('usage', [UsageController::class, 'index'])->name('usage');
Route::post('bulk', [PromotionsController::class, 'bulk'])->name('bulk');

Route::get('/', [PromotionsController::class, 'index'])->name('index');
Route::get('create', [PromotionsController::class, 'create'])->name('create');
Route::post('/', [PromotionsController::class, 'store'])->name('store');
Route::get('{promotion}/edit', [PromotionsController::class, 'edit'])->name('edit');
Route::put('{promotion}', [PromotionsController::class, 'update'])->name('update');
Route::delete('{promotion}', [PromotionsController::class, 'destroy'])->name('destroy');
Route::patch('{promotion}/status', [PromotionsController::class, 'setStatus'])->name('status');
Route::post('{promotion}/duplicate', [PromotionsController::class, 'duplicate'])->name('duplicate');
