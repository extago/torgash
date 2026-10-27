<?php

namespace Torgash\LiqPay;

use Illuminate\Support\ServiceProvider;

/**
 * The whole module: one gateway, announced — the same shape as monobank's.
 * The callback route, the deduplication, the ordering, the log and the order
 * screen's buttons all belong to torgash.payments.
 */
class LiqPayServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        hook_listen('payments.gateways', fn (array $gateways) => $gateways + [
            LiqPayGateway::key() => LiqPayGateway::class,
        ]);
    }
}
