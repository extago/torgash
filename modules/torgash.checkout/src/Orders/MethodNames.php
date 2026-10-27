<?php

namespace Torgash\Checkout\Orders;

use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;

/**
 * The method names orders were sold under, as a manager filters by them.
 *
 * An order keeps its payment and delivery method as the NAME the buyer saw, in
 * the language they shopped in, so one method reaches the orders list as
 * "Arrange with manager" and "Обсудить с менеджером" — two options in the
 * filter for one thing, and picking either found half its orders. Here a name
 * that is only another language of a method's current name collapses into the
 * reader's (Order::sameWordsFor()), and filtering by it matches every language
 * of it. A name no method carries any more stays as it was, on its own.
 *
 * Delivery belongs to another module, so its names are asked for with
 * `checkout.order.shipping_method_translations` (a list of translation maps).
 */
class MethodNames
{
    /** @param  'payment'|'shipping'  $kind */
    public function __construct(protected string $kind) {}

    /**
     * One option per method, in the reader's language.
     *
     * @param  list<string>  $stored  the distinct names orders carry
     * @return list<string>
     */
    public function options(array $stored): array
    {
        $labels = array_map(fn (string $name) => Order::sameWordsFor($name, $this->mapOf($name)), $stored);
        $labels = array_values(array_unique($labels));
        sort($labels);

        return $labels;
    }

    /**
     * Every spelling an order may carry for the option picked.
     *
     * @return list<string>
     */
    public function spellings(string $label): array
    {
        $map = array_filter((array) $this->mapOf($label), 'is_string');

        return array_values(array_unique([$label, ...array_values($map)]));
    }

    /** @return array<string, string>|null */
    protected function mapOf(string $name): ?array
    {
        foreach ($this->translations() as $map) {
            if (is_array($map) && in_array($name, $map, true)) {
                return $map;
            }
        }

        return null;
    }

    /** @var list<mixed>|null */
    protected ?array $maps = null;

    /** @return list<mixed> */
    protected function translations(): array
    {
        return $this->maps ??= $this->kind === 'payment'
            ? PaymentMethod::query()->pluck('name')->all()
            : array_values((array) hook_filter('checkout.order.shipping_method_translations', []));
    }
}
