<?php

use Illuminate\Support\Facades\Route;
use Torgash\Search\Http\Admin\RulesController;
use Torgash\Search\Http\Admin\SearchController;
use Torgash\Search\Http\Admin\StatsController;

// Loaded by SearchServiceProvider with prefix "admin/search-engine", name prefix "admin.search-engine.".

Route::get('/', [SearchController::class, 'index'])->name('index');
Route::post('reindex', [SearchController::class, 'reindex'])->name('reindex');

// Synonyms, exclusions, pinned phrases — {kind} is one of the three.
Route::get('rules/{kind}', [RulesController::class, 'index'])->name('rules.index')->whereIn('kind', ['synonyms', 'exclusions', 'phrases']);
Route::post('rules/{kind}', [RulesController::class, 'store'])->name('rules.store')->whereIn('kind', ['synonyms', 'exclusions', 'phrases']);
Route::get('rules/{kind}/{id}/edit', [RulesController::class, 'edit'])->name('rules.edit')->whereIn('kind', ['synonyms', 'exclusions', 'phrases'])->whereNumber('id');
Route::put('rules/{kind}/{id}', [RulesController::class, 'update'])->name('rules.update')->whereIn('kind', ['synonyms', 'exclusions', 'phrases'])->whereNumber('id');
Route::delete('rules/{kind}/{id}', [RulesController::class, 'destroy'])->name('rules.destroy')->whereIn('kind', ['synonyms', 'exclusions', 'phrases'])->whereNumber('id');
Route::patch('rules/{kind}/{id}/status', [RulesController::class, 'setStatus'])->name('rules.status')->whereIn('kind', ['synonyms', 'exclusions', 'phrases'])->whereNumber('id');
Route::post('rules/{kind}/bulk', [RulesController::class, 'bulk'])->name('rules.bulk')->whereIn('kind', ['synonyms', 'exclusions', 'phrases']);

// What is searched, what finds nothing, what is chosen.
Route::get('stats', [StatsController::class, 'index'])->name('stats');
