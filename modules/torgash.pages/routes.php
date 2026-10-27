<?php

use Illuminate\Support\Facades\Route;
use Torgash\Pages\Http\PageController;

// The LEGACY shape, kept as a permanent redirect to the page's real address.
// Links made while pages lived under /page/ must not become 404s just because
// the addresses moved to the root — the same rule the module applies to its
// own renames.
//
// The address itself is a FALLBACK route, registered in PagesServiceProvider
// rather than here: it has to be registered per locale in a specific order,
// which this file (loaded once per shape) cannot express.

Route::get('/page/{slug}', [PageController::class, 'legacy'])->name('pages.show');
