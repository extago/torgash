<?php

use App\Http\Controllers\ModuleAssetController;
use App\Http\Controllers\ThemeAssetController;
use Illuminate\Support\Facades\Route;

/*
 * Files, not pages — registered OUTSIDE the `web` group (bootstrap/app.php,
 * `then:`): a script needs no session, no CSRF cookie, no storefront and no
 * locale, and inside the group every one of them started a session row and
 * sent cookies back. The controllers answer a revalidation with 304, which
 * matters because a server in front may force one on every load (OSPanel's
 * Caddy sends "max-age=0, must-revalidate" on everything, static files too).
 */

// What the content security policy blocked, as the browser reports it (PLAN §53).
Route::post('/csp-report', \App\Core\Http\CspReportController::class)
    ->middleware('throttle:120,1')
    ->name('csp.report');

// A module's own JS/CSS/fonts, straight out of modules/<id>/assets/ — modules
// are never copied into public/, so this is how they reach the browser.
Route::get('/modules/{module}/assets/{path}', ModuleAssetController::class)
    ->where('path', '.*')
    ->name('module.asset');

// ...and a theme's: previews, stylesheets, fonts (themes/<dir>/assets/).
Route::get('/themes/{theme}/assets/{path}', ThemeAssetController::class)
    ->where('path', '.*')
    ->name('theme.asset');
