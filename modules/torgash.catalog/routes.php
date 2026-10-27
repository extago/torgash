<?php

use Illuminate\Support\Facades\Route;
use Torgash\Catalog\Http\BrandsController;
use Torgash\Catalog\Http\StorefrontController;

Route::get('/', [StorefrontController::class, 'home'])->name('catalog.home');
Route::get('/search', [StorefrontController::class, 'search'])->name('catalog.search');
Route::get('/catalog', [StorefrontController::class, 'index'])->name('catalog.index');
Route::get('/brands', [BrandsController::class, 'index'])->name('catalog.brands.index');
Route::get('/brand/{slug}', [BrandsController::class, 'show'])->name('catalog.brands.show');
// Plain string params (not {category:slug}/{product:slug} implicit binding)
// so the controller can fall back to a 301 redirect when the slug is stale
// instead of Eloquent throwing a 404 before that check ever runs.
Route::get('/catalog/{slug}', [StorefrontController::class, 'category'])->name('catalog.category');
// A category plus a set of characteristic values, at an address of its own
// (PLAN §30.8). Two segments, so there is nothing for it to collide with:
// our categories are flat in the URL, `/catalog/{slug}` matches one segment
// and never a slash.
Route::get('/catalog/{category}/{filters}', [StorefrontController::class, 'filterPage'])
    ->name('catalog.filter');
// The same page as text: `/product/foo.md`, and `/ru/product/foo.md` like
// every other storefront route — an agent asking in Russian gets the Russian
// page, which a single "machine version" address could never do.
//
// A suffix rather than content negotiation on the address beside it: a page that
// changes shape by request header is invisible in a log, uncacheable without
// Vary, and impossible to send somebody as a link. Limited per address, since
// the text half of a catalogue is one more way to download all of it.
//
// BEFORE the HTML route, and that is not cosmetic: a slug matches `[^/]+`,
// which swallows the suffix, so registered the other way round `/product/x.md`
// is answered by the page itself with a slug of "x.md" — a 404 that looks like
// a missing product.
Route::get('/product/{slug}.md', [StorefrontController::class, 'productText'])
    ->middleware('throttle:torgash-agent')->name('catalog.product.text');

Route::get('/product/{slug}', [StorefrontController::class, 'product'])->name('catalog.product');
