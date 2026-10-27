<?php

use Illuminate\Support\Facades\Route;
use Torgash\Enquiries\Http\Admin\EnquiriesController;

Route::get('/', [EnquiriesController::class, 'index'])->name('index');
// Before the {enquiry} wildcards, so "bulk" is never read as an enquiry.
Route::post('/bulk', [EnquiriesController::class, 'bulk'])->name('bulk');
Route::get('/{enquiry}', [EnquiriesController::class, 'show'])->name('show');
Route::patch('/{enquiry}', [EnquiriesController::class, 'update'])->name('update');
Route::post('/{enquiry}/messages', [EnquiriesController::class, 'reply'])->name('reply');
Route::patch('/{enquiry}/status', [EnquiriesController::class, 'setStatus'])->name('status');
Route::patch('/{enquiry}/manager', [EnquiriesController::class, 'setManager'])->name('manager');
Route::post('/{enquiry}/take', [EnquiriesController::class, 'take'])->name('take');
Route::post('/{enquiry}/close', [EnquiriesController::class, 'close'])->name('close');
Route::post('/{enquiry}/reopen', [EnquiriesController::class, 'reopen'])->name('reopen');
Route::delete('/{enquiry}', [EnquiriesController::class, 'destroy'])->name('destroy');
