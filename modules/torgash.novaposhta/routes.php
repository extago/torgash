<?php

use Illuminate\Support\Facades\Route;
use Torgash\NovaPoshta\Http\BranchesController;

// Storefront routes, registered per locale shape by StorefrontRoutes::group.
// The branch picker's search: the page carries the first pageful, this answers
// the typing (see BranchesController).
Route::get('np/branches', [BranchesController::class, 'index'])->name('np.branches');
