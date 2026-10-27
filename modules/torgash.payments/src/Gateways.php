<?php

namespace Torgash\Payments;

use Torgash\Checkout\Models\Order;
use Torgash\Payments\Models\Binding;

/**
 * Who can take money here. A gateway module answers `payments.gateways` with
 * `['its_key' => ItsClass::class]` and needs nothing else from us.
 */
class Gateways
{
    /** @return array<string, class-string<Gateway>> */
    public function all(): array
    {
        return hook_filter('payments.gateways', []);
    }

    public function find(?string $key): ?Gateway
    {
        $class = $this->all()[$key] ?? null;

        return $class ? app($class) : null;
    }

    /** @return array<string, string> key => translated label, for a picker */
    public function options(): array
    {
        $options = [];

        foreach ($this->all() as $key => $class) {
            $options[$key] = __($class::label());
        }

        return $options;
    }

    /**
     * The gateway that should be handling this order, with its config — or null
     * when the order was paid by hand, by a method nobody wired up, or by one
     * that has been renamed since.
     *
     * @return array{gateway: Gateway, binding: Binding}|null
     */
    public function forOrder(Order $order): ?array
    {
        $binding = Binding::forOrder($order);
        $gateway = $binding ? $this->find($binding->gateway) : null;

        return $gateway ? ['gateway' => $gateway, 'binding' => $binding] : null;
    }
}
