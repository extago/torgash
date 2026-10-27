<?php

use Illuminate\Support\Facades\Route;
use Torgash\Media\Http\Admin\ContentController;
use Torgash\Media\Http\Admin\GalleryController;
use Torgash\Media\Http\Admin\MaintenanceController;

// Loaded by MediaServiceProvider with prefix "admin/media", name prefix "admin.media.".
// {owner} is a key registered in MediaOwners by the module that owns the entity.

// Before the {owner} wildcard so it is not read as an owner key.
// Every picture of the shop, rebuilt: the media library's own right, not
// anybody who can open the admin (audit 2026-10-15).
Route::post('regenerate', [MaintenanceController::class, 'regenerate'])->middleware('can:media.manage')->name('regenerate');

// Images inside the record's TEXT, before the gallery's own wildcards: a
// literal `content` segment would otherwise be read as a media id by
// `{owner}/{id}/{media}/rotate` and as an owner key by nothing at all.
Route::get('{owner}/{id}/content', [ContentController::class, 'index'])->name('content.index');
Route::post('{owner}/{id}/content', [ContentController::class, 'upload'])->name('content.upload');
Route::post('{owner}/{id}/content/from-url', [ContentController::class, 'fromUrl'])->name('content.from-url');
Route::delete('{owner}/{id}/content/{media}', [ContentController::class, 'destroy'])->name('content.destroy');

Route::get('{owner}/{id}', [GalleryController::class, 'index'])->name('index');
Route::post('{owner}/{id}/upload', [GalleryController::class, 'upload'])->name('upload');
Route::post('{owner}/{id}/from-url', [GalleryController::class, 'fromUrl'])->name('from-url');
Route::post('{owner}/{id}/{media}/rotate', [GalleryController::class, 'rotate'])->name('rotate');
Route::delete('{owner}/{id}/{media}', [GalleryController::class, 'destroy'])->name('destroy');
Route::delete('{owner}/{id}', [GalleryController::class, 'destroyAll'])->name('destroy-all');
