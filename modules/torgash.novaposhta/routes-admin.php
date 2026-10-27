<?php

use Illuminate\Support\Facades\Route;
use Torgash\NovaPoshta\Http\Admin\DataController;

// Loaded by NovaPoshtaServiceProvider: prefix "admin/nova-poshta", name "admin.nova-poshta.".

Route::get('/', [DataController::class, 'index'])->name('index');
Route::post('check', [DataController::class, 'check'])->name('check');
Route::post('sync', [DataController::class, 'sync'])->name('sync');
Route::post('match', [DataController::class, 'match'])->name('match');
Route::post('warehouses', [DataController::class, 'syncWarehouses'])->name('warehouses');

// The town parcels leave from, without which the carrier cannot price anything.
Route::get('sender', [DataController::class, 'senderSearch'])->name('sender.search');
Route::post('sender', [DataController::class, 'setSender'])->name('sender.set');

// The mapping as a file: the one thing here that cannot be rebuilt from the API.
Route::get('map.csv', [DataController::class, 'exportMap'])->name('map.export');
Route::post('map', [DataController::class, 'importMap'])->name('map.import');

// Binding one place by hand: the picker is a fragment swapped into its row.
Route::get('places/{place}/row', [DataController::class, 'showRow'])->name('row');
Route::get('places/{place}/candidates', [DataController::class, 'candidates'])->name('candidates');
Route::post('places/{place}/bind', [DataController::class, 'bind'])->name('bind');
Route::delete('places/{place}/bind', [DataController::class, 'unbind'])->name('unbind');
