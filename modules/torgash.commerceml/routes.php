<?php

use Illuminate\Support\Facades\Route;
use Torgash\CommerceMl\Http\ExchangeController;

/**
 * The one address a shop gives to 1C.
 *
 * Every mode of the protocol comes through here — `?type=catalog&mode=checkauth`
 * and the rest — because that is how CommerceML is shaped: one URL, a query
 * string, and answers that are bare words. It is not a REST API and pretending
 * otherwise would mean an address 1C cannot be told to use.
 *
 * Both spellings are routed. `/commerceml` is ours; `/1c_exchange.php` is what
 * a decade of 1C configurations, articles and support scripts have hard-coded,
 * and a shop moving from another engine should be able to paste in the address
 * it already has.
 */
Route::match(['get', 'post'], '/commerceml', ExchangeController::class)->name('commerceml.exchange');
Route::match(['get', 'post'], '/1c_exchange.php', ExchangeController::class)->name('commerceml.exchange.legacy');
