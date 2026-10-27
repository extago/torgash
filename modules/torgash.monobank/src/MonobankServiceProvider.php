<?php

namespace Torgash\Monobank;

use Illuminate\Support\ServiceProvider;

/**
 * The whole module: one gateway, announced. Everything else — the callback
 * route, the signature check being called at all, the deduplication, the
 * ordering, the log, the buttons — belongs to torgash.payments.
 *
 * That is the point of the split: a bank integration is a class, not a module
 * full of plumbing (an add-on for another engine is ~1000 lines, most of it plumbing
 * this shop already owns).
 */
class MonobankServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        hook_listen('payments.gateways', fn (array $gateways) => $gateways + [
            MonobankGateway::key() => MonobankGateway::class,
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([Console\CheckCommand::class]);
        }
    }
}
