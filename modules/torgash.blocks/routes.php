<?php

use Illuminate\Support\Facades\Route;
use Torgash\Blocks\Http\LaterController;

// Registered through StorefrontRoutes::group(): in every locale shape, so the
// cards it draws link in the language of the page that asked.

Route::get('/blocks/{block}/later', LaterController::class)->whereNumber('block')->name('blocks.later');
