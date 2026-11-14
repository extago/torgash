<?php

namespace Torgash\Checkout\Orders;

use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Seo\ProductFacts;
use Torgash\Catalog\Seo\ProductsEvent;
use Torgash\Checkout\Models\Order;

/**
 * The one moment an order is shown as just placed, told to the page as
 * `torgash:order-placed` — what a counter reports a purchase from
 * (torgash.metrika, torgash.google-analytics, PLAN §73).
 *
 * ONCE per order: on the first page that shows it placed — the order's own
 * page on the way out of checkout, or the answer to a one-click order on a
 * product page, which is the page such an order has instead. The order's
 * page goes on saying "thank you" to whoever reloads it for two hours; a
 * counter told of the purchase on every reload would count it as many times.
 * Remembered in the session that placed it, the only one that ever sees the
 * order as just placed.
 *
 * On the order's page the event is dispatched by a script of the page (once
 * it is parsed, so a counter's deferred script is listening), and modules
 * may print beside it (`checkout.order.just_placed`); in the one-click answer
 * it is the HX-Trigger header — a script inside an HTMX answer does not run
 * under the page's content security policy.
 */
class JustPlaced
{
    public const EVENT = 'torgash:order-placed';

    /** The number of the order last announced — read only on the pages that show one placed. */
    public const SESSION = 'last_order_announced';

    /** On the order's own page: the event and whatever modules print there, the first time only. */
    public static function announce(Order $order): string
    {
        if (! self::first($order)) {
            return '';
        }

        return ProductsEvent::script(self::EVENT, self::describe($order))
            .hook_render('checkout.order.just_placed', ['order' => $order]);
    }

    /** On the answer that is a one-click order's page: the event as HX-Trigger, or null after the first time. */
    public static function header(Order $order): ?string
    {
        if (! self::first($order)) {
            return null;
        }

        $described = self::describe($order);
        $lines = $described['lines'];
        unset($described['lines']);

        return ProductsEvent::described(self::EVENT, ['lines' => $lines], $described);
    }

    /**
     * The order as a counter is told of it: the shop's number, the money in
     * the books as major-unit numbers (`total` is what the customer pays,
     * delivery and surcharges included), the coupon, and every line that is
     * a product — a build's parts included, each at its own price, so the
     * lines add up to the goods. Brand and categories are the product's as
     * it is now; the name is the line's, as it was bought.
     *
     * @return array{number: string, total: float, shipping: float, discount: float, coupon: ?string, currency: string, lines: list<array<string, mixed>>}
     */
    public static function describe(Order $order): array
    {
        $lines = collect($order->items ?? [])->filter(fn ($line) => is_array($line) && ! empty($line['product_id']))->values();
        $products = Product::query()->whereIn('id', $lines->pluck('product_id')->unique()->all())
            ->with(['categories', 'featureAssignments.feature', 'featureAssignments.featureValue'])
            ->get()->keyBy('id');
        $major = fn ($minor) => round((int) $minor / 100, 2);

        return [
            'number' => (string) $order->order_number,
            'total' => $major($order->total),
            'shipping' => $major($order->shipping_cost),
            'discount' => $major($order->discount_amount),
            'coupon' => $order->coupon_code ?: null,
            'currency' => shop_currency(),
            'lines' => $lines->map(function (array $line) use ($products) {
                $name = is_array($line['name'] ?? null) ? pick_translation($line['name']) : ($line['name'] ?? null);
                $product = $products->get((int) $line['product_id']);
                $item = $product
                    ? (new ProductFacts($product))->item((int) ($line['unit_price'] ?? 0), (int) ($line['qty'] ?? 1))
                    : [
                        'id' => (string) $line['product_id'], 'sku' => $line['sku'] ?? null, 'name' => '', 'brand' => null,
                        'categories' => [], 'price' => round(price_scale()->toMajor((int) ($line['unit_price'] ?? 0)), 2),
                        'quantity' => max(1, (int) ($line['qty'] ?? 1)),
                    ];

                return ['name' => (string) ($name ?? $item['name'])] + $item;
            })->all(),
        ];
    }

    protected static function first(Order $order): bool
    {
        if (session(self::SESSION) === $order->order_number) {
            return false;
        }

        session()->put(self::SESSION, $order->order_number);

        return true;
    }
}
