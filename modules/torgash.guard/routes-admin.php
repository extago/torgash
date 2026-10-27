<?php

use Illuminate\Support\Facades\Route;
use Torgash\Guard\Http\Admin\AuditController;
use Torgash\Guard\Http\Admin\BlocksController;
use Torgash\Guard\Http\Admin\ErrorsController;
use Torgash\Guard\Http\Admin\InvariantsController;

// Registered before the wildcard {error} routes so "clear-resolved" is never
// captured as an id.
Route::post('errors/bulk', [ErrorsController::class, 'bulk'])->name('errors.bulk');
Route::post('errors/clear-resolved', [ErrorsController::class, 'clearResolved'])->name('errors.clear_resolved');

Route::get('errors', [ErrorsController::class, 'index'])->name('errors.index');
Route::get('errors/{error}', [ErrorsController::class, 'show'])->name('errors.show');
Route::post('errors/{error}/resolve', [ErrorsController::class, 'resolve'])->name('errors.resolve');
Route::post('errors/{error}/reopen', [ErrorsController::class, 'reopen'])->name('errors.reopen');
Route::delete('errors/{error}', [ErrorsController::class, 'destroy'])->name('errors.destroy');

// No delete on purpose: an audit trail with a delete button is not an audit
// trail. Rows leave by ageing out, and nothing else. Undoing a change writes
// to the THING changed (and so adds an entry), never to the trail.
Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
Route::post('audit/{entry}/revert', [AuditController::class, 'revert'])->middleware('can:guard.revert')->name('audit.revert');
Route::post('audit/task/{task}/revert', [AuditController::class, 'revertTask'])->whereNumber('task')->middleware('can:guard.revert')->name('audit.revert_task');

Route::get('invariants', [InvariantsController::class, 'index'])->name('invariants.index');
Route::post('invariants/run', [InvariantsController::class, 'run'])->name('invariants.run');
Route::post('invariants/self-test', [InvariantsController::class, 'selfTest'])->name('invariants.self_test');
Route::post('invariants/findings/bulk', [InvariantsController::class, 'bulk'])->name('invariants.bulk');
Route::post('invariants/{finding}/ignore', [InvariantsController::class, 'ignore'])->name('invariants.ignore');
Route::post('invariants/{finding}/unignore', [InvariantsController::class, 'unignore'])->name('invariants.unignore');

// Blocking. The Release button here is a convenience; the way back in when the
// admin itself is behind the door is `php artisan guard:unblock` (PLAN §25.2/4).
Route::get('blocks', [BlocksController::class, 'index'])->name('blocks.index');
Route::post('blocks', [BlocksController::class, 'store'])->name('blocks.store');
Route::post('blocks/bulk', [BlocksController::class, 'bulk'])->name('blocks.bulk');
Route::post('blocks/{block}/release', [BlocksController::class, 'release'])->name('blocks.release');
Route::post('blocks/{block}/enforce', [BlocksController::class, 'enforce'])->name('blocks.enforce');
