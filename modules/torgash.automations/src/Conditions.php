<?php

namespace Torgash\Automations;

use Torgash\Automations\Models\RuleCondition;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;

/**
 * Does this order meet what the rule asks of it?
 *
 * ALL conditions must hold; "one of these" lives inside a single row's value.
 * That is the shape a merchant writes rules in, and it means adding a row can
 * only ever narrow what fires, never widen it by accident.
 *
 * The same shape as the shipping module's method conditions — on purpose, so
 * both screens read alike — but NOT the same code: those ask questions about a
 * cart ("every product is from"), these ask questions about a placed order, and
 * automations must not depend on a delivery module a digital shop uninstalls.
 */
class Conditions
{
    /** kind => its heading, an English source string for the UI. */
    public const KIND_LABELS = [
        'status' => 'Order status',
        'payment_state' => 'Payment state',
        'total' => 'Order total',
        'product_category' => 'Product category',
        'payment_method' => 'Payment method',
        'shipping_method' => 'Delivery method',
        'country' => 'Country',
        'city' => 'City',
        'manager' => 'Manager',
        'coupon' => 'Coupon',
        'source' => 'How it was placed',
        'customer_orders' => 'The customer\'s orders',
        'stock' => 'Stock',
    ];

    /** kind => operator => English source string, read as "the order …". */
    public const KINDS = [
        'status' => [
            'status_in' => 'is in',
            'status_not_in' => 'is not in',
        ],
        'payment_state' => [
            'payment_in' => 'is in',
            'payment_not_in' => 'is not in',
        ],
        'total' => [
            'total_min' => 'is at least',
            'total_max' => 'is at most',
        ],
        // Read as "the order …". Membership is resolved from each line's
        // product at run time; a line whose product was deleted counts as
        // being in no category (it fails "has every item from"). A category
        // IS its subtree, as everywhere in the engine (CategoryTree): a rule
        // on "Electronics" sees an item from "Electronics › Phones".
        'product_category' => [
            'cat_has_any' => 'has an item from',
            'cat_all_from' => 'has every item from',
            'cat_has_none' => 'has no item from',
        ],
        'payment_method' => [
            'text_is' => 'is',
            'text_is_not' => 'is not',
        ],
        'shipping_method' => [
            'text_is' => 'is',
            'text_is_not' => 'is not',
        ],
        'country' => [
            'text_in' => 'is one of',
            'text_not_in' => 'is none of',
        ],
        'city' => [
            'text_is' => 'is',
            'text_contains' => 'contains',
        ],
        'manager' => [
            'manager_any' => 'somebody is assigned',
            'manager_none' => 'nobody is assigned',
        ],
        'coupon' => [
            'coupon_any' => 'any coupon was used',
            'coupon_is' => 'this coupon was used',
            'coupon_none' => 'no coupon was used',
        ],
        // OrderSource: the checkout, one click, a manager, the API, an
        // exchange. An order from before it was recorded is in none of them.
        'source' => [
            'source_in' => 'is in',
            'source_not_in' => 'is not in',
        ],
        // By customer_ref — the account, or the phone/email a guest gave —
        // counting every earlier order of theirs except a merged shell.
        'customer_orders' => [
            'first_order' => 'this is their first order',
            'repeat_order' => 'they have ordered before',
        ],
        // Each line's product as it stands when the rule runs: short when it
        // is gone, or its stock is below zero once the order took its units
        // (sold past the shelf — a preorder), or below the line's quantity
        // while the order holds none (awaiting payment).
        'stock' => [
            'all_in_stock' => 'every item is in stock',
            'some_short' => 'an item is not in stock',
            // After this order (LowStock): at or below the threshold, none
            // left, or sold past the shelf — the shelf the next buyer finds.
            'some_low' => 'an item runs low after this order',
            'some_out' => 'an item is sold out after this order',
        ],
    ];

    /**
     * @param  iterable<RuleCondition>  $conditions
     */
    public static function pass(iterable $conditions, Order $order): bool
    {
        foreach ($conditions as $condition) {
            if (! self::passes($condition, $order)) {
                return false;
            }
        }

        return true;
    }

    public static function passes(RuleCondition $condition, Order $order): bool
    {
        $list = $condition->list();
        $text = mb_strtolower($condition->text());

        return match ($condition->operator) {
            'status_in' => in_array((string) $order->status, $list, true),
            'status_not_in' => ! in_array((string) $order->status, $list, true),
            'payment_in' => in_array((string) $order->payment_state, $list, true),
            'payment_not_in' => ! in_array((string) $order->payment_state, $list, true),
            'total_min' => $order->total >= $condition->amount(),
            'total_max' => $order->total <= $condition->amount(),
            'cat_has_any' => (bool) array_intersect(self::orderCategoryIds($order), self::categoryIds($condition)),
            'cat_has_none' => ! array_intersect(self::orderCategoryIds($order), self::categoryIds($condition)),
            'cat_all_from' => self::everyLineInCategories(self::orderCategoryLines($order), self::categoryIds($condition)),
            'text_is' => mb_strtolower((string) self::field($condition->kind, $order)) === $text,
            'text_is_not' => mb_strtolower((string) self::field($condition->kind, $order)) !== $text,
            'text_contains' => $text !== '' && str_contains(mb_strtolower((string) self::field($condition->kind, $order)), $text),
            'text_in' => in_array(mb_strtolower((string) self::field($condition->kind, $order)), array_map('mb_strtolower', $list), true),
            'text_not_in' => ! in_array(mb_strtolower((string) self::field($condition->kind, $order)), array_map('mb_strtolower', $list), true),
            'manager_any' => $order->manager_id !== null,
            'manager_none' => $order->manager_id === null,
            'coupon_any' => (string) $order->coupon_code !== '',
            'coupon_none' => (string) $order->coupon_code === '',
            'coupon_is' => mb_strtolower((string) $order->coupon_code) === $text,
            'first_order' => ! self::orderedBefore($order),
            'repeat_order' => self::orderedBefore($order),
            'all_in_stock' => ! self::someShort($order),
            'some_short' => self::someShort($order),
            'some_low' => self::leaves($order, [LowStock::LOW, LowStock::OUT, LowStock::OVERSOLD]),
            'some_out' => self::leaves($order, [LowStock::OUT, LowStock::OVERSOLD]),
            'source_in' => in_array((string) $order->source, $list, true),
            'source_not_in' => ! in_array((string) $order->source, $list, true),
            // An operator nobody implements must not silently pass: a rule that
            // fires on everything is worse than a rule that never fires.
            default => false,
        };
    }

    /** An earlier order by the same customer — not this one, not a merged shell. */
    protected static function orderedBefore(Order $order): bool
    {
        if ((string) $order->customer_ref === '') {
            return false;
        }

        return Order::where('customer_ref', $order->customer_ref)
            ->where('id', '<', $order->id)
            ->where('status', '!=', \Torgash\Checkout\Models\OrderStatus::MERGED)
            ->exists();
    }

    /** @param  list<string>  $states  whether any product this order leaves in one of these */
    protected static function leaves(Order $order, array $states): bool
    {
        $threshold = LowStock::threshold();

        foreach (LowStock::left($order) as $left) {
            if (in_array(LowStock::state($left, $threshold), $states, true)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a line's product is gone or has not got the units (see KINDS['stock']). */
    protected static function someShort(Order $order): bool
    {
        $lines = array_filter((array) $order->items, fn ($item) => is_array($item) && ! empty($item['product_id']));
        $stock = Product::whereIn('id', array_map(fn ($item) => (int) $item['product_id'], $lines))->pluck('stock', 'id');

        foreach ($lines as $item) {
            $left = $stock[(int) $item['product_id']] ?? null;

            if ($left === null || ($order->stock_deducted ? (int) $left < 0 : (int) $left < (int) ($item['qty'] ?? 0))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The categories a condition names, plus everything under them — the
     * promotions module's rule, and the catalogue's own: a category is its
     * subtree. Resolved from the tree's memo, no query per rule.
     *
     * @return list<int>
     */
    protected static function categoryIds(RuleCondition $condition): array
    {
        return app(CategoryTree::class)->descendants($condition->ids());
    }

    /** The order's own value behind a text-shaped kind. */
    protected static function field(string $kind, Order $order): ?string
    {
        return match ($kind) {
            'payment_method' => $order->payment_method_name,
            'shipping_method' => $order->shipping_method_name,
            'country' => $order->country,
            'city' => $order->city,
            default => null,
        };
    }

    /**
     * Category ids per order line, in line order — [] for a line whose product
     * has been deleted or sits in no category. Resolved in one query for the
     * whole order, not per line.
     *
     * @return list<list<int>>
     */
    protected static function orderCategoryLines(Order $order): array
    {
        $productIds = [];
        foreach ((array) $order->items as $item) {
            if (! empty($item['product_id'])) {
                $productIds[(int) $item['product_id']] = true;
            }
        }

        if ($productIds === []) {
            return [];
        }

        $byProduct = Product::whereIn('id', array_keys($productIds))
            ->with('categories:id')
            ->get()
            ->mapWithKeys(fn (Product $p) => [$p->id => $p->categories->pluck('id')->map('intval')->all()]);

        $lines = [];
        foreach ((array) $order->items as $item) {
            $lines[] = $byProduct[(int) ($item['product_id'] ?? 0)] ?? [];
        }

        return $lines;
    }

    /** Every distinct category id anything in the order belongs to. @return list<int> */
    protected static function orderCategoryIds(Order $order): array
    {
        return array_values(array_unique(array_merge([], ...self::orderCategoryLines($order))));
    }

    /**
     * @param  list<list<int>>  $lines
     * @param  list<int>  $wanted
     */
    protected static function everyLineInCategories(array $lines, array $wanted): bool
    {
        if ($lines === []) {
            return false;
        }

        foreach ($lines as $categories) {
            if (! array_intersect($categories, $wanted)) {
                return false;
            }
        }

        return true;
    }

    /**
     * One condition in plain words — "Order total — is at least 500.00" — so a
     * rule that "did not fire" is visibly a rule with requirements.
     *
     * @param  array<int, string>  $categoryNames  id => label, for a
     *     product_category row; a plain count is shown for any id not in it.
     */
    public static function describe(RuleCondition $condition, array $categoryNames = []): string
    {
        $kind = __(self::KIND_LABELS[$condition->kind] ?? $condition->kind);
        $operator = __(self::KINDS[$condition->kind][$condition->operator] ?? $condition->operator);

        $value = match ($condition->kind) {
            'status', 'country' => implode(', ', $condition->list()),
            'payment_state' => implode(', ', array_map(
                fn (string $state) => PaymentState::label($state),
                $condition->list(),
            )),
            // describe() is read on the rules screen and nowhere else, so the
            // amount follows the admin's own "show amounts in" — format_price()
            // would follow the storefront session the admin shares with the shop.
            'total' => admin_price($condition->amount()),
            'product_category' => self::nameList($condition->ids(), $categoryNames),
            'payment_method', 'shipping_method', 'city' => $condition->text(),
            'coupon' => $condition->operator === 'coupon_is' ? $condition->text() : '',
            'source' => implode(', ', array_map(fn (string $source) => \Torgash\Checkout\Orders\OrderSource::label($source), $condition->list())),
            default => '',
        };

        return trim("{$kind} — {$operator} {$value}");
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, string>  $names
     */
    protected static function nameList(array $ids, array $names): string
    {
        if ($ids === []) {
            return '';
        }

        $known = array_filter(array_map(fn (int $id) => $names[$id] ?? null, $ids));

        return $known !== []
            ? implode(', ', $known)
            : __(':count selected', ['count' => count($ids)]);
    }
}
