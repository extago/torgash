<?php

use Illuminate\Support\Facades\Route;
use Torgash\Cdek\Http\Admin\WaybillsController;

// Loaded by CdekServiceProvider: prefix "admin/cdek", name "admin.cdek.", and
// a permission of its own — the people who pack orders are not the ones who
// set up the integration.

Route::post('orders/{order}/waybills', [WaybillsController::class, 'store'])->name('waybills.store');
Route::post('orders/{order}/waybills/{waybill}/refresh', [WaybillsController::class, 'refresh'])->name('waybills.refresh');
Route::delete('orders/{order}/waybills/{waybill}', [WaybillsController::class, 'cancel'])->name('waybills.cancel');
Route::post('orders/{order}/waybills/{waybill}/print/{kind}', [WaybillsController::class, 'print'])->name('waybills.print');
