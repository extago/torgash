<?php

namespace Torgash\Shipping\Conditions;

use Torgash\Cart\Contracts\Basket;

/**
 * Everything a condition can ask about the cart in front of it, gathered once.
 *
 * The shipping module owns the QUESTIONS and knows nothing about products: the
 * per-line categories and specs come from whoever owns the catalogue, through
 * the `shipping.cart_lines` hook. That is the same arrangement the exim module
 * has with the catalogue — the hook belongs to the asker, the answer to the
 * module that has the data — and it is what lets delivery keep requiring only
 * `torgash.checkout`.
 *
 * `known` is the honest part. A cart estimate rendered without a cart cannot
 * evaluate anything, and a method whose conditions cannot be checked must not
 * be offered on a guess (see Conditions::pass).
 */
final class CartFacts
{
    public function __construct(
        public readonly bool $known = false,
        /** What the customer actually pays for the goods, after any coupon. */
        public readonly int $subtotal = 0,
        public readonly bool $weightKnown = true,
        public readonly ?int $customerGroupId = null,
        public readonly ?string $couponCode = null,
        /** @var list<list<int>> category ids, one list per cart line */
        public readonly array $categoryLines = [],
        /** @var list<array<string, string>> spec key => value, one map per cart line */
        public readonly array $featureLines = [],
        /**
         * What is in the cart, as product id => quantity.
         *
         * Not a condition's question — no condition counts units of a product —
         * but the planner's: a method that hands the goods over from a shelf
         * has to know whether that shelf holds all of them. It rides here
         * because this is the object the quote already carries from the one
         * place that has the cart, and the ids come from the Basket contract
         * itself rather than from the catalogue's models.
         *
         * @var array<int, int>
         */
        public readonly array $quantities = [],
    ) {}

    public static function forCart(Basket $cart, int $subtotal): self
    {
        // Each line: ['categories' => [ids], 'features' => ['Вес' => '0.65']].
        // No catalogue module, no lines — and then a category condition simply
        // never passes, which is the safe direction.
        $lines = (array) hook_filter('shipping.cart_lines', [], ['cart' => $cart]);

        return new self(
            known: true,
            subtotal: $subtotal,
            weightKnown: $cart->weightIsKnown(),
            customerGroupId: auth()->user()?->customer_group_id,
            couponCode: $cart->couponCode(),
            categoryLines: array_map(
                fn (array $line) => array_values(array_map('intval', (array) ($line['categories'] ?? []))),
                $lines,
            ),
            featureLines: array_map(
                fn (array $line) => array_map('strval', (array) ($line['features'] ?? [])),
                $lines,
            ),
            quantities: $cart->items()->reduce(function (array $carry, object $item) {
                $id = (int) $item->product->id;

                $carry[$id] = ($carry[$id] ?? 0) + (int) $item->qty;

                return $carry;
            }, []),
        );
    }
}
