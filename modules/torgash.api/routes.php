<?php

use Illuminate\Support\Facades\Route;
use Torgash\Api\Http\ResourceController;

/**
 * One route table for every registered resource.
 *
 * `/api/v2/admin/...` mirrors the split the shop already has: these answer with
 * everything, including what a visitor may not see, and are gated by the same
 * permissions the admin panel checks. A `shop` section over the same resources
 * (active products only, storefront prices) is the next half — the URL says
 * which of the two you are talking to, so neither has to guess.
 *
 * The version is in the path and not in a header on purpose: it survives
 * copy-pasting into a browser, a log line and somebody's cURL, which is where
 * integration problems are actually diagnosed.
 */
Route::prefix('api/v2/admin')->name('api.admin.')->group(function () {
    Route::get('{resource}', [ResourceController::class, 'index'])->name('index');
    Route::get('{resource}/{key}', [ResourceController::class, 'show'])->name('show');
    Route::patch('{resource}/{key}', [ResourceController::class, 'update'])->name('update');
    // Only for a resource that declares `create` — a gift card is issued, an
    // order is never written in (it is placed, and that is PlaceOrder's).
    Route::post('{resource}', [ResourceController::class, 'store'])->name('store');

    // Named transitions, not state writes — see ResourceController::action.
    Route::post('{resource}/{key}/actions/{action}', [ResourceController::class, 'action'])->name('action');
});
