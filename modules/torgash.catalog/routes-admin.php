<?php

use Illuminate\Support\Facades\Route;
use Torgash\Catalog\Http\Admin\AvailabilityStatusesController;
use Torgash\Catalog\Http\Admin\CategoriesController;
use Torgash\Catalog\Http\Admin\FeaturesController;
use Torgash\Catalog\Http\Admin\FeatureValuesController;
use Torgash\Catalog\Http\Admin\FilterPagesController;
use Torgash\Catalog\Http\Admin\FilterRulesController;
use Torgash\Catalog\Http\Admin\LabelsController;
use Torgash\Catalog\Http\Admin\MaintenanceController;
use Torgash\Catalog\Http\Admin\PriceTypeMarkupRulesController;
use Torgash\Catalog\Http\Admin\PriceTypesController;
use Torgash\Catalog\Http\Admin\ProductsController;
use Torgash\Catalog\Http\Admin\ProductTabsController;

// Fired by the button on the catalogue's settings card (a manifest "action").
Route::post('fill-weights', [MaintenanceController::class, 'fillWeights'])->name('fill-weights');

// Trash routes go before the resource so "products/trash" is not swallowed by
// the {product} wildcard.
Route::get('products/trash', [ProductsController::class, 'trash'])->name('products.trash');
Route::delete('products/trash', [ProductsController::class, 'emptyTrash'])->name('products.trash.empty');
Route::post('products/trash/{id}/restore', [ProductsController::class, 'restore'])->name('products.restore');
Route::delete('products/trash/{id}', [ProductsController::class, 'forceDelete'])->name('products.force-delete');
// What a product picker offers as the admin types (Admin\ProductLookup).
Route::get('products/lookup', [ProductsController::class, 'lookup'])->name('products.lookup');
Route::post('products/{product}/duplicate', [ProductsController::class, 'duplicate'])->name('products.duplicate');
// List-screen actions (HTMX row swaps + the bulk bar). Declared before the
// resource so none of them is swallowed by the {product} wildcard.
Route::post('products/bulk', [ProductsController::class, 'bulk'])->name('products.bulk');
Route::patch('products/{product}/quick', [ProductsController::class, 'quickUpdate'])->name('products.quick');
Route::patch('products/{product}/status', [ProductsController::class, 'setStatus'])->name('products.status');
Route::resource('products', ProductsController::class)->except(['show']);

Route::get('categories/trash', [CategoriesController::class, 'trash'])->name('categories.trash');
Route::delete('categories/trash', [CategoriesController::class, 'emptyTrash'])->name('categories.trash.empty');
Route::post('categories/trash/{id}/restore', [CategoriesController::class, 'restore'])->name('categories.restore');
Route::delete('categories/trash/{id}', [CategoriesController::class, 'forceDelete'])->name('categories.force-delete');
Route::patch('categories/{category}/status', [CategoriesController::class, 'setStatus'])->name('categories.status');
Route::post('categories/bulk', [CategoriesController::class, 'bulk'])->name('categories.bulk');
Route::resource('categories', CategoriesController::class)->except(['show']);

// Tabs the shop writes onto its product pages (PLAN §30.10). One screen for
// both kinds — a tab for every product and a tab for one — because they are one
// table; which it is, is `product_id`.
Route::patch('product-tabs/section/{section}/status', [ProductTabsController::class, 'setSectionStatus'])
    ->where('section', '[a-z0-9_-]+')->name('product-tabs.section-status');
Route::patch('product-tabs/{tab}/status', [ProductTabsController::class, 'setStatus'])->name('product-tabs.status');
Route::resource('product-tabs', ProductTabsController::class)
    ->except(['show'])->parameters(['product-tabs' => 'tab']);

// Declared before the resource, or "bulk" would be read as a {feature}.
Route::post('features/bulk', [FeaturesController::class, 'bulk'])->name('features.bulk');
Route::resource('features', FeaturesController::class)->except(['show']);
Route::get('features/{feature}/values/{value}/edit', [FeatureValuesController::class, 'edit'])->name('features.values.edit');
Route::put('features/{feature}/values/{value}', [FeatureValuesController::class, 'update'])->name('features.values.update');
Route::post('features/{feature}/values/merge', [FeaturesController::class, 'mergeValues'])->name('features.values.merge');
Route::patch('labels/{label}/status', [LabelsController::class, 'setStatus'])->name('labels.status');
Route::post('labels/bulk', [LabelsController::class, 'bulk'])->name('labels.bulk');
Route::resource('labels', LabelsController::class)->except(['show']);
// Availability statuses (PLAN §31.6): addressed by KEY, since a status registered
// in code has no row until the shop renames or weighs it. `weights` goes
// before the resource so it is not read as a status called "weights".
Route::put('availability-statuses/weights', [AvailabilityStatusesController::class, 'weights'])->name('availability-statuses.weights');
Route::resource('availability-statuses', AvailabilityStatusesController::class)
    ->except(['show'])->parameters(['availability-statuses' => 'status'])
    ->where(['status' => '[a-z0-9_]+']);
Route::patch('price-types/{price_type}/status', [PriceTypesController::class, 'setStatus'])->name('price-types.status');
Route::resource('price-types', PriceTypesController::class)->except(['show']);
Route::resource('price-types.rules', PriceTypeMarkupRulesController::class)
    ->except(['show'])->parameters(['rules' => 'rule']);

// Filter pages (PLAN §30.8): the combinations the shop publishes as addresses,
// and the rules that make them. The two "generate" routes go before the
// resource so neither is swallowed by the {rule} wildcard.
Route::post('filter-rules/generate', [FilterRulesController::class, 'generate'])->name('filter-rules.generate');
Route::post('filter-rules/{rule}/generate', [FilterRulesController::class, 'generate'])->name('filter-rules.generate-one');
Route::post('filter-rules/bulk', [FilterRulesController::class, 'bulk'])->name('filter-rules.bulk');
Route::patch('filter-rules/{rule}/status', [FilterRulesController::class, 'setStatus'])->name('filter-rules.status');
Route::resource('filter-rules', FilterRulesController::class)
    ->except(['show'])->parameters(['filter-rules' => 'rule']);
// The characteristic picker on its own — swapped in when the category changes,
// before the {filterPage} wildcard can swallow the word.
Route::get('filter-pages/values', [FilterPagesController::class, 'values'])->name('filter-pages.values');
Route::post('filter-pages/bulk', [FilterPagesController::class, 'bulk'])->name('filter-pages.bulk');
Route::patch('filter-pages/{filterPage}/status', [FilterPagesController::class, 'setStatus'])->name('filter-pages.status');
Route::resource('filter-pages', FilterPagesController::class)->except(['show']);
