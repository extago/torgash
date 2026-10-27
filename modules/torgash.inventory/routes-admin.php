<?php

use Illuminate\Support\Facades\Route;
use Torgash\Inventory\Http\Admin\DocumentsController;
use Torgash\Inventory\Http\Admin\MovesController;
use Torgash\Inventory\Http\Admin\OrderCostsController;
use Torgash\Inventory\Http\Admin\ProfitController;

// Loaded by InventoryServiceProvider with prefix "admin/inventory", name prefix "admin.inventory.".

Route::get('moves', [MovesController::class, 'index'])->name('moves');

Route::get('documents', [DocumentsController::class, 'index'])->name('documents.index');
Route::post('documents/new/{type}', [DocumentsController::class, 'create'])->name('documents.create');
Route::get('documents/{document}', [DocumentsController::class, 'edit'])->name('documents.edit');
Route::put('documents/{document}', [DocumentsController::class, 'update'])->name('documents.update');
Route::delete('documents/{document}', [DocumentsController::class, 'destroy'])->name('documents.destroy');
Route::post('documents/{document}/post', [DocumentsController::class, 'post'])->name('documents.post');
Route::post('documents/{document}/revoke', [DocumentsController::class, 'revoke'])->name('documents.revoke');
Route::get('documents/{document}/print', [DocumentsController::class, 'print'])->name('documents.print');
Route::get('documents/{document}/search', [DocumentsController::class, 'search'])->name('documents.search');
Route::post('documents/{document}/lines', [DocumentsController::class, 'addLine'])->name('documents.lines.add');
Route::delete('documents/{document}/lines/{line}', [DocumentsController::class, 'removeLine'])->name('documents.lines.remove');

// An order's own expenses and income (§42.5а), and the period's profit.
Route::post('orders/{order:order_number}/costs', [OrderCostsController::class, 'store'])->name('orders.costs.store');
Route::delete('orders/{order:order_number}/costs/{cost}', [OrderCostsController::class, 'destroy'])->name('orders.costs.destroy')->whereNumber('cost');
Route::get('profit', [ProfitController::class, 'index'])->name('profit');
