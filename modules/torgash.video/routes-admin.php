<?php

use Illuminate\Support\Facades\Route;
use Torgash\Video\Http\Admin\VideosController;

// Loaded by VideoServiceProvider with prefix "admin/video", name prefix "admin.video.".
// {owner} is a key registered in VideoOwners (`product`).

Route::get('{owner}/{id}', [VideosController::class, 'index'])->name('index')->whereNumber('id');
Route::post('{owner}/{id}', [VideosController::class, 'store'])->name('store')->whereNumber('id');
Route::post('{owner}/{id}/{video}/poster', [VideosController::class, 'poster'])->name('poster')->whereNumber(['id', 'video']);
Route::post('{owner}/{id}/{video}/prepare', [VideosController::class, 'prepare'])->name('prepare')->whereNumber(['id', 'video']);
Route::delete('{owner}/{id}/{video}', [VideosController::class, 'destroy'])->name('destroy')->whereNumber(['id', 'video']);

// A file of the shop's own, in chunks (Uploads): start, chunk × n, finish — or cancel.
Route::post('{owner}/{id}/upload', [VideosController::class, 'uploadStart'])->name('upload.start')->whereNumber('id');
Route::post('{owner}/{id}/upload/{token}', [VideosController::class, 'uploadChunk'])->name('upload.chunk')->whereNumber('id')->whereAlphaNumeric('token');
Route::post('{owner}/{id}/upload/{token}/finish', [VideosController::class, 'uploadFinish'])->name('upload.finish')->whereNumber('id')->whereAlphaNumeric('token');
Route::delete('{owner}/{id}/upload/{token}', [VideosController::class, 'uploadCancel'])->name('upload.cancel')->whereNumber('id')->whereAlphaNumeric('token');
