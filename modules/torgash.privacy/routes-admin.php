<?php

use Illuminate\Support\Facades\Route;
use Torgash\Privacy\Http\Admin\ConsentsController;
use Torgash\Privacy\Http\Admin\RequestsController;
use Torgash\Privacy\Http\Admin\SettingsController;

Route::redirect('/', '/admin/privacy/requests');

Route::get('/requests', [RequestsController::class, 'index'])->name('requests.index');
Route::get('/requests/create', [RequestsController::class, 'create'])->name('requests.create');
Route::post('/requests', [RequestsController::class, 'store'])->name('requests.store');
Route::get('/requests/{privacyRequest}', [RequestsController::class, 'show'])->name('requests.show');
Route::get('/requests/{privacyRequest}/download', [RequestsController::class, 'download'])->name('requests.download');
Route::post('/requests/{privacyRequest}/erase', [RequestsController::class, 'erase'])->name('requests.erase');
Route::post('/requests/{privacyRequest}/reject', [RequestsController::class, 'reject'])->name('requests.reject');

Route::get('/consents', ConsentsController::class)->name('consents');

Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
