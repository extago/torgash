<?php

namespace Torgash\Exim;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Exim\Console\RunScheduledCommand;
use Torgash\Exim\Http\FeedController;

class EximServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Public, non-locale-prefixed — mirrors catalog's own /sitemap.xml
        // route (CatalogServiceProvider.php) for a machine-consumed endpoint.
        Route::middleware('web')->get('/exim/feed/{profile:public_token}', [FeedController::class, 'show'])
            ->name('exim.feed');

        Route::middleware(['web', 'auth', 'can:exim.manage'])
            ->prefix('admin/exim')
            ->name('admin.exim.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        if ($this->app->runningInConsole()) {
            $this->commands([RunScheduledCommand::class]);
        }

        // A profile carries its own `schedule` and change rule and works out
        // its own due-ness (Rebuilds); it needs something to ask. Every minute,
        // so a cron expression is kept to the minute — one small query when
        // nothing is due, and the builds themselves go to the queue. Changes
        // are looked for every fifth minute: that is a look at the catalogue.
        hook_listen('core.schedule', fn (array $context) => $context['schedule']
            ->call(fn () => app(Rebuilds::class)->startDue(lookForChanges: now()->minute % 5 === 0))
            ->name('exim:rebuilds')
            ->everyMinute()
            ->withoutOverlapping());

        // A draft saves itself on every pick of the mapping screen: a trail
        // entry each time would bury the decisions — the import is audited
        // by what it writes.
        hook_listen('guard.audit.ignored_tables', fn (array $tables) => [...$tables, 'm_exim_import_drafts']);

        // Uploads nobody imported, and files an import left behind.
        hook_listen('core.schedule', fn (array $context) => $context['schedule']
            ->call(fn () => Models\ImportDraft::sweep())
            ->dailyAt('04:40')
            ->name('exim:sweep-import-drafts'));
    }
}
