<?php

namespace Torgash\WayForPay;

use Illuminate\Support\ServiceProvider;

/**
 * One gateway, announced — the shape monobank and LiqPay have. Everything
 * else belongs to torgash.payments.
 */
class WayForPayServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        hook_listen('payments.gateways', fn (array $gateways) => $gateways + [
            WayForPayGateway::key() => WayForPayGateway::class,
        ]);
    }
}
