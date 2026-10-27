<?php

use Illuminate\Support\Facades\Route;
use Torgash\Cdek\Http\PointsController;

// Storefront routes, registered per locale shape by StorefrontRoutes::group.
// The point picker's search: the page carries the first pageful, this answers
// the typing (see PointsController).
Route::get('cdek/points', [PointsController::class, 'index'])->name('cdek.points');
