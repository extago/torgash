<?php

namespace Torgash\Automations;

use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Catalog\Support\Measurements;
use Torgash\Catalog\Support\Variations;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;

/**
 * The variables a template can use, and the list of them for the picker.
 *
 * Money appears twice on purpose. `total` is minor units — an integer, which is
 * what a receiving API wants and what cannot be mangled by a locale — and
 * `total_decimal` is "150.00" for the systems that insist on a decimal string.
 * Neither is a formatted price with a currency symbol. For the payloads a
 * PERSON reads — a chat message — there is a third spelling, `total_formatted`
 * ("1 042.25 $", in the currency the customer was quoted in, exactly as the
 * order prints), plus the status's own name and a link to the order in the
 * admin: a manager's phone buzzing with "status: new, total: 104225" is a
 * notification nobody reads twice.
 */
class Context
{
    /**
     * @param  list<string>  $changed
     * @return array<string, mixed>
     */
    public static function forOrder(Order $order, string $event, array $changed = []): array
    {
        // The lines' products in ONE query, with what a message may say
        // about them (photo, characteristics, weight) — never one per line.
        $products = Product::whereIn('id', array_filter(array_column($order->items ?? [], 'product_id')))
            ->withCardData()->with('featureAssignments.feature', 'featureAssignments.featureValue')
            ->get()->keyBy('id');
        app(Measurements::class)->prime($products);

        return [
            'event' => $event,
            'changed' => implode(',', $changed),
            'shop' => [
                'name' => (string) config('app.name'),
                'url' => (string) config('app.url'),
            ],
            'order' => [
                'id' => $order->id,
                'number' => (string) $order->order_number,
                'status' => (string) $order->status,
                'payment_state' => (string) $order->payment_state,
                'payment_state_label' => PaymentState::label($order->payment_state),
                'currency' => (string) $order->currency_code,
                'subtotal' => $order->subtotal,
                'subtotal_decimal' => self::decimal($order->subtotal),
                'discount' => (int) $order->discount_amount,
                'shipping_cost' => $order->shipping_cost,
                'total' => $order->total,
                'total_decimal' => self::decimal($order->total),
                'total_formatted' => $order->money((int) $order->total),
                'status_label' => (string) (\Torgash\Checkout\Models\OrderStatus::where('code', $order->status)->first()?->t('name') ?: $order->status),
                'admin_url' => route('admin.orders.show', $order->order_number),
                'items_count' => (int) collect($order->items ?? [])->sum(fn ($item) => (int) ($item['qty'] ?? 0)),
                'paid_total' => $order->paid_total,
                'paid_total_decimal' => self::decimal($order->paid_total),
                'customer_name' => (string) $order->customer_name,
                'email' => (string) $order->email,
                'phone' => (string) $order->phone,
                'address_line' => (string) $order->address_line,
                'city' => (string) $order->city,
                'postal_code' => (string) $order->postal_code,
                'country' => (string) $order->country,
                'notes' => (string) $order->notes,
                'manager' => (string) ($order->manager?->name ?? ''),
                'payment_method' => (string) $order->payment_method_name,
                'shipping_method' => (string) $order->shipping_method_name,
                'coupon' => (string) $order->coupon_code,
                'placed_at' => $order->created_at?->toIso8601String() ?? '',
                'items' => array_map(fn (array $item) => self::line($item, $products[$item['product_id'] ?? 0] ?? null) + [
                    'product_id' => $item['product_id'] ?? null,
                    'sku' => (string) ($item['sku'] ?? ''),
                    'name' => (string) ($item['name'] ?? ''),
                    'qty' => (int) ($item['qty'] ?? 0),
                    'unit_price' => (int) ($item['unit_price'] ?? 0),
                    'unit_price_decimal' => self::decimal((int) ($item['unit_price'] ?? 0)),
                    'line_total' => (int) ($item['line_total'] ?? 0),
                    'line_total_decimal' => self::decimal((int) ($item['line_total'] ?? 0)),
                    'line_total_formatted' => $order->money((int) ($item['line_total'] ?? 0)),
                ], $order->items ?? []),
            ],
        ];
    }

    /**
     * What a line says beyond its money: the product's addresses and photo,
     * its variation ("black, 256 GB"), its characteristics by code
     * (`item.features.COLOR`) and in one line, the options chosen with it,
     * and — for a part of a configured product — what it was chosen as.
     * Every product field is '' once the product is gone.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected static function line(array $item, ?Product $product): array
    {
        $options = array_map(
            fn (array $option) => (string) $option['label'].((int) ($option['qty'] ?? 1) > 1 ? ' ×'.(int) $option['qty'] : ''),
            (array) ($item['options'] ?? []),
        );

        $features = [];
        $featuresText = [];

        if ($product) {
            $assignments = $product->featureAssignments
                ->filter(fn (ProductFeature $pf) => $pf->feature && (string) $pf->feature->code !== '')
                ->sortBy(fn (ProductFeature $pf) => [(int) $pf->feature->sort, (int) $pf->feature_id]);

            foreach ($assignments as $pf) {
                $text = trim((string) $pf->display());

                if ($text === '') {
                    continue;
                }

                // A list characteristic may hold several values: one entry.
                $code = (string) $pf->feature->code;
                $features[$code] = isset($features[$code]) ? $features[$code].', '.$text : $text;
            }

            foreach ($assignments->unique('feature_id') as $pf) {
                if (isset($features[$pf->feature->code])) {
                    $featuresText[] = $pf->feature->t('name').': '.$features[$pf->feature->code];
                }
            }
        }

        return [
            'url' => $product ? route('catalog.product', $product->slug) : '',
            'admin_url' => $product ? route('admin.catalog.products.edit', $product->id) : '',
            'image_url' => $product ? (string) $product->primaryImageUrl(400) : '',
            'variation' => $product ? self::variation($product) : '',
            'features' => $features,
            'features_text' => implode('; ', $featuresText),
            'brand' => $product ? (string) ($product->brandValue()?->display() ?? '') : '',
            'weight' => $product?->resolvedWeight() ?? '',
            'options_text' => implode('; ', $options),
            'option_label' => (string) ($item['option_label'] ?? ''),
            'is_part' => isset($item['parent_key']),
        ];
    }

    /** The product's answers on its group's variation axes, in one line — '' for a product in no group. */
    protected static function variation(Product $product): string
    {
        $group = app(Variations::class)->for($product);
        $labels = [];

        foreach ($group['axes'] as $axis) {
            $label = $group['values'][$product->id][$axis['feature']->id]['label'] ?? '';

            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return implode(', ', $labels);
    }

    /**
     * Every variable, grouped and described, for the picker beside the form —
     * so an admin composing a message never has to guess a name, nor whether
     * `total` is "150.00" or 15000. Labels are English source strings.
     *
     * Item fields are shown once, as `item.*`, which is how they are addressed
     * inside a `{{#order.items}}` loop.
     *
     * @return array<string, array<string, string>> group label => [path => label]
     */
    public static function catalogue(): array
    {
        return [
            'Order' => [
                'order.number' => 'Order number',
                'order.status_label' => 'Status, by its name',
                'order.admin_url' => 'Link to the order in the admin',
                'order.placed_at' => 'When it was placed',
                'order.manager' => 'Manager',
                'order.notes' => 'Customer\'s comment',
            ],
            'Customer' => [
                'order.customer_name' => 'Customer name',
                'order.phone' => 'Phone',
                'order.email' => 'Email',
                'order.country' => 'Country',
                'order.city' => 'Town',
                'order.address_line' => 'Address',
                'order.postal_code' => 'Postal code',
            ],
            'Money' => [
                'order.total_formatted' => 'Total with the currency, "1 042.25 $"',
                'order.total_decimal' => 'Total as a number, "1042.25"',
                'order.total' => 'Total in cents, 104225',
                'order.subtotal_decimal' => 'Goods before delivery',
                'order.shipping_cost' => 'Delivery, in cents',
                'order.discount' => 'Discount, in cents',
                'order.paid_total_decimal' => 'Paid so far',
                'order.payment_state_label' => 'Payment state, by its name',
                'order.currency' => 'Currency code',
                'order.coupon' => 'Coupon',
            ],
            'Delivery and payment' => [
                'order.shipping_method' => 'Delivery method',
                'order.payment_method' => 'Payment method',
                'order.items_count' => 'How many items',
            ],
            'Each item (inside the loop)' => [
                'item.name' => 'Product name',
                'item.sku' => 'SKU',
                'item.qty' => 'Quantity',
                'item.line_total_formatted' => 'Line total with the currency',
                'item.unit_price_decimal' => 'Price of one',
                'item.product_id' => 'Product id',
                'item.url' => 'Link to the product',
                'item.admin_url' => 'Link to the product in the admin',
                'item.image_url' => 'Link to the product\'s photo',
                'item.variation' => 'Variation in one line: "black, 256 GB"',
                'item.features.CODE' => 'A characteristic by its code: item.features.COLOR',
                'item.features_text' => 'Every characteristic in one line: "Colour: black; Memory: 256 GB"',
                'item.brand' => 'Brand',
                'item.weight' => 'Weight, grams',
                'item.options_text' => 'Options chosen with it: "Licence: Windows Server 2022"',
                'item.option_label' => 'For a part of a configured product: what it was chosen as',
                'item.is_part' => 'true for a part of a configured product',
            ],
            'Technical' => [
                'order.id' => 'Order id',
                'order.status' => 'Status code',
                'order.payment_state' => 'Payment state code',
                'event' => 'What triggered the rule',
                'changed' => 'Fields that changed',
                'shop.name' => 'Shop name',
                'shop.url' => 'Shop address',
            ],
        ];
    }

    /**
     * Flat dotted paths, every one the context answers.
     *
     * @return list<string>
     */
    public static function variables(): array
    {
        return [
            'event', 'changed',
            'shop.name', 'shop.url',
            'order.id', 'order.number', 'order.status', 'order.payment_state', 'order.payment_state_label',
            'order.status_label', 'order.admin_url',
            'order.currency', 'order.subtotal', 'order.subtotal_decimal', 'order.discount',
            'order.shipping_cost', 'order.total', 'order.total_decimal', 'order.total_formatted', 'order.items_count',
            'order.paid_total', 'order.paid_total_decimal',
            'order.customer_name', 'order.email', 'order.phone',
            'order.address_line', 'order.city', 'order.postal_code', 'order.country',
            'order.notes', 'order.manager', 'order.payment_method', 'order.shipping_method',
            'order.coupon', 'order.placed_at',
            'item.sku', 'item.name', 'item.qty', 'item.unit_price', 'item.unit_price_decimal',
            'item.line_total', 'item.line_total_decimal', 'item.line_total_formatted', 'item.product_id',
            'item.url', 'item.admin_url', 'item.image_url', 'item.variation', 'item.features_text',
            'item.brand', 'item.weight', 'item.options_text', 'item.option_label', 'item.is_part',
        ];
    }

    protected static function decimal(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', '');
    }
}
