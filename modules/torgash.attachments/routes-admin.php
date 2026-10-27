<?php

use Illuminate\Support\Facades\Route;
use Torgash\Attachments\Http\Admin\DocumentsController;

// Loaded by AttachmentsServiceProvider with prefix "admin/attachments", name prefix "admin.attachments.".

Route::get('product/{id}', [DocumentsController::class, 'index'])->name('index')->whereNumber('id');
Route::post('product/{id}/files', [DocumentsController::class, 'upload'])->name('upload')->whereNumber('id');
Route::post('product/{id}/link', [DocumentsController::class, 'link'])->name('link')->whereNumber('id');
Route::delete('product/{id}/{attachment}', [DocumentsController::class, 'destroy'])->name('destroy')->whereNumber(['id', 'attachment']);
