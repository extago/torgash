<?php

use Illuminate\Support\Facades\Route;
use Torgash\Seo\Http\Admin\AuditController;
use Torgash\Seo\Http\Admin\TemplatesController;

// Loaded by SeoServiceProvider with prefix "admin/seo", name prefix "admin.seo.".

Route::get('/', [TemplatesController::class, 'index'])->name('index');

// Before the {template} routes: a literal segment registered after a wildcard
// one of the same shape is a segment the wildcard swallows.
Route::get('audit', [AuditController::class, 'index'])->name('audit');
Route::post('audit', [AuditController::class, 'save'])->name('audit.save');

Route::get('create', [TemplatesController::class, 'create'])->name('create');
Route::post('preview', [TemplatesController::class, 'preview'])->name('preview');
Route::post('bulk', [TemplatesController::class, 'bulk'])->name('bulk');
Route::post('/', [TemplatesController::class, 'store'])->name('store');
Route::get('{template}/edit', [TemplatesController::class, 'edit'])->name('edit');
Route::put('{template}', [TemplatesController::class, 'update'])->name('update');
Route::delete('{template}', [TemplatesController::class, 'destroy'])->name('destroy');
Route::post('{template}/duplicate', [TemplatesController::class, 'duplicate'])->name('duplicate');
Route::patch('{template}/status', [TemplatesController::class, 'setStatus'])->name('status');
