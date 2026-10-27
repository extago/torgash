<?php

use Illuminate\Support\Facades\Route;
use Torgash\Blocks\Http\Admin\BlocksController;

Route::get('/', [BlocksController::class, 'index'])->name('index');
Route::get('zone/{zone}', [BlocksController::class, 'zone'])->name('zone');
Route::patch('zone/{zone}/toggle', [BlocksController::class, 'toggleZone'])->name('zone.toggle');
// Putting a zone back the way the theme meant it. An ACTION somebody presses,
// never a migration: a migration must be one-off and silent, and this is a
// person looking at a screen that says what is about to happen. Registered
// before the {block} routes so "theme-defaults" is never read as a block id.
Route::get('theme-defaults', [BlocksController::class, 'themeDefaults'])->name('theme_defaults');
Route::post('theme-defaults/{zone}/add', [BlocksController::class, 'restoreAdd'])->name('theme_defaults.add');
Route::post('theme-defaults/{zone}/replace', [BlocksController::class, 'restoreReplace'])->name('theme_defaults.replace');

Route::get('create/{zone}/{type}', [BlocksController::class, 'create'])->name('create');
Route::post('/', [BlocksController::class, 'store'])->name('store');
Route::get('{block}/edit', [BlocksController::class, 'edit'])->name('edit');
Route::put('{block}', [BlocksController::class, 'update'])->name('update');
Route::delete('{block}', [BlocksController::class, 'destroy'])->name('destroy');
Route::patch('{block}/toggle', [BlocksController::class, 'toggle'])->name('toggle');
Route::patch('{block}/move', [BlocksController::class, 'move'])->name('move');
Route::patch('{block}/join', [BlocksController::class, 'join'])->name('join');
Route::patch('{block}/detach', [BlocksController::class, 'detach'])->name('detach');
Route::patch('{block}/width', [BlocksController::class, 'width'])->name('width');
Route::patch('{block}/section', [BlocksController::class, 'section'])->name('section');
