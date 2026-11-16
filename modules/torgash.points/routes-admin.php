<?php

use Illuminate\Support\Facades\Route;
use Torgash\Points\Http\Admin\PointsAdminController;
use Torgash\Points\Http\Admin\RulesController;

Route::get('/', [PointsAdminController::class, 'index'])->name('index');
Route::get('/rules', [RulesController::class, 'index'])->name('rules.index');
Route::get('/rules/create', [RulesController::class, 'create'])->name('rules.create');
Route::post('/rules', [RulesController::class, 'store'])->name('rules.store');
Route::post('/rules/sort', [RulesController::class, 'sort'])->name('rules.sort');
Route::get('/rules/{rule}/edit', [RulesController::class, 'edit'])->name('rules.edit')->whereNumber('rule');
Route::put('/rules/{rule}', [RulesController::class, 'update'])->name('rules.update')->whereNumber('rule');
Route::post('/rules/{rule}/raise', [RulesController::class, 'raise'])->name('rules.raise')->whereNumber('rule');
Route::delete('/rules/{rule}', [RulesController::class, 'destroy'])->name('rules.destroy')->whereNumber('rule');
Route::post('/groups', [RulesController::class, 'storeGroup'])->name('groups.store');
Route::delete('/groups/{group}', [RulesController::class, 'destroyGroup'])->name('groups.destroy')->whereNumber('group');
Route::post('/adjust', [PointsAdminController::class, 'adjust'])->name('adjust');
Route::post('/orders/{order:order_number}/earn', [PointsAdminController::class, 'orderEarn'])->name('orders.earn');
