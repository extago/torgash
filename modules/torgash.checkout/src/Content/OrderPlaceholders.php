<?php

namespace Torgash\Checkout\Content;

use App\Core\Content\Placeholders;
use Torgash\Checkout\Models\Order;

/**
 * What a page about ONE order may say in admin-written content — which in
 * practice means the thank-you page and the analytics snippet sitting on it.
 *
 * The order is read off the ROUTE, not passed in: `checkout.confirmation` has
 * the order bound into it (`/checkout/{order:order_number}/confirmation`), so
 * asking the request is both exact and free. Anywhere else these resolve to '',
 * which is what every token off its own page does.
 *
 * Registered by CHECKOUT because the data is checkout's — `torgash.snippets`
 * names no module and knows nothing about orders, and with checkout uninstalled
 * these tokens simply do not exist.
 *
 * The numbers are for a MACHINE. `{order_total}` is a bare decimal in the
 * currency the customer was charged in, not `format_price()`: a counter fed
 * "1 200 ₴" records nothing, and the thousands separator alone breaks every
 * analytics snippet ever pasted into a shop.
 */
class OrderPlaceholders
{
    public function register(Placeholders $placeholders): void
    {
        $group = 'order';

        $placeholders->register('order_number', [
            'label' => 'Order number',
            'group' => $group,
            'resolve' => fn () => $this->order()?->order_number ?? '',
        ]);

        $placeholders->register('order_total', [
            'label' => 'Order total, as a plain number',
            'group' => $group,
            'resolve' => fn () => ($order = $this->order())
                ? $this->amount($order, (int) $order->total)
                : '',
        ]);

        $placeholders->register('order_currency', [
            'label' => 'Currency the order was charged in',
            'group' => $group,
            'resolve' => fn () => $this->order()?->chargeCurrency() ?? '',
        ]);

        // The one token that emits markup rather than a value, and it is
        // declared raw for exactly that reason: an escaped JSON array is a
        // syntax error in every snippet that would use it.
        $placeholders->register('order_items_json', [
            'label' => 'Order items as a JSON array',
            'group' => $group,
            'raw' => true,
            'example' => '[{"sku":"ST-1002","name":"…","price":"374.25","quantity":1}]',
            'resolve' => fn () => $this->items(),
        ]);
    }

    /** The order this page is about, or null — see the class docblock. */
    protected function order(): ?Order
    {
        $order = request()?->route('order');

        return $order instanceof Order ? $order : null;
    }

    /**
     * Minor units of the books turned into the number the customer agreed to,
     * at the rate frozen on the order — the same arithmetic `Order::money()`
     * prints, without the symbol and without the spaces.
     */
    protected function amount(Order $order, int $minor): string
    {
        return number_format(($minor / 100) * $order->chargeRate(), 2, '.', '');
    }

    /**
     * The lines, in the shape every ecommerce tag wants them: an id, a name, a
     * unit price and a quantity.
     *
     * `JSON_HEX_TAG` because this lands inside a `<script>`, where a literal
     * `</script>` in a product name would end the tag — the same reason the
     * structured data uses it.
     */
    protected function items(): string
    {
        $order = $this->order();

        if (! $order) {
            return '';
        }

        $items = [];

        foreach ((array) $order->items as $line) {
            $items[] = [
                'sku' => (string) ($line['sku'] ?? ''),
                'name' => (string) ($line['name'] ?? ''),
                'price' => $this->amount($order, (int) ($line['unit_price'] ?? 0)),
                'quantity' => (int) ($line['qty'] ?? 0),
            ];
        }

        return (string) json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    }
}
