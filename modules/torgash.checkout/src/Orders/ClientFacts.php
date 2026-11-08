<?php

namespace Torgash\Checkout\Orders;

use App\Core\Support\ClientFacts as CoreClientFacts;
use Torgash\Checkout\Models\Order;

/**
 * What an order writes down about the browser it was placed from (the facts
 * and how they are captured are core's). `checkout.order.client_facts` adds a
 * fact to orders alone, and may give it a column of the order's own.
 *
 * PlaceOrder is the one place this is captured. The order's own `locale` is
 * not one of these: it is what the letters and a reprint are written in, not
 * something the shop may decide not to know.
 */
class ClientFacts extends CoreClientFacts
{
    protected function ticked(): array
    {
        return (array) setting('torgash.checkout', 'client_facts', self::DEFAULTS);
    }

    protected function extended(array $facts): array
    {
        return hook_filter('checkout.order.client_facts', $facts);
    }

    /** @return list<array{key: string, label: string, value: string, raw: string}> */
    public function describe(Order $order): array
    {
        return $this->describeBag($order->client);
    }
}
