<?php

namespace Torgash\Stripe;

use Illuminate\Support\ServiceProvider;

/**
 * One gateway, announced — the shape every gateway module here has.
 */
class StripeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        hook_listen('payments.gateways', fn (array $gateways) => $gateways + [
            StripeGateway::key() => StripeGateway::class,
        ]);
    }
}
