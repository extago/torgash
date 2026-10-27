<?php

use Illuminate\Support\Facades\Route;
use Torgash\Rma\Http\Admin\ReturnsController;

// Actions only, and every one of them a POST. There is no page here: a return
// is read on its ticket, in the inbox the shop already reads (§29.2), and a
// list of its own would be the second inbox this whole design refuses.
// Drawing one up on a ticket that started as something else — addressed by the
// ENQUIRY, because that is what it is being attached to. Registered before the
// {return} routes so "for" is never read as a return id.
Route::post('/for/{enquiry}', [ReturnsController::class, 'create'])->name('create');

Route::post('/{return}/approve', [ReturnsController::class, 'approve'])->name('approve');
Route::post('/{return}/decline', [ReturnsController::class, 'decline'])->name('decline');
Route::post('/{return}/receive', [ReturnsController::class, 'receive'])->name('receive');
Route::post('/{return}/refund', [ReturnsController::class, 'refund'])->name('refund');
Route::post('/{return}/exchange', [ReturnsController::class, 'exchange'])->name('exchange');
Route::post('/{return}/reopen', [ReturnsController::class, 'reopen'])->name('reopen');
