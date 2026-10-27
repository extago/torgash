<?php

use Illuminate\Support\Facades\Route;
use Torgash\Api\Http\Admin\TokensController;
use Torgash\Api\Http\DocsController;

Route::get('docs', [DocsController::class, 'page'])->name('docs');
Route::get('tokens', [TokensController::class, 'index'])->name('tokens');
Route::post('tokens', [TokensController::class, 'store'])->name('tokens.store');
Route::post('tokens/bulk', [TokensController::class, 'bulk'])->name('tokens.bulk');
Route::delete('tokens/{token}', [TokensController::class, 'destroy'])->name('tokens.destroy');
