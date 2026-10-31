<?php

namespace Torgash\Checkout\Orders;

use Torgash\Catalog\Models\Product;

/**
 * The shop's minimum order (setting `min_order_amount`, in the shop's
 * currency; 0 — none), answered in ONE place: the basket's button, the
 * product page's note, the checkout and PlaceOrder all ask this class, so the
 * sum the note promises is the sum the order is measured by.
 *
 * Measured on the GOODS — the basket's lines at their prices (CartManager::
 * total(), which is a Quote's `subtotal`), before a coupon and without
 * delivery. A coupon that takes an order under the line is not a reason to
 * refuse it after the customer was told they were over, and delivery is not
 * known until the address is.
 *
 * Enforced on what a CUSTOMER places — the checkout, the shop API, one click
 * when the shop says so (`min_order_one_click`); an order a manager places,
 * or an exchange for a return, is not held to it (PlaceOrder's `$as`).
 */
class MinimumOrder
{
    /** The minimum in minor units of the shop's books; 0 when there is none. */
    public function amount(): int
    {
        return max(0, (int) round((float) setting('torgash.checkout', 'min_order_amount', 0) * 100));
    }

    /** How much is missing from goods worth `$goods`; 0 when nothing is. */
    public function shortfall(int $goods): int
    {
        $minimum = $this->amount();

        return $minimum > 0 ? max(0, $minimum - $goods) : 0;
    }

    /** What the customer is told while the goods are under the minimum, else null. */
    public function refusal(int $goods): ?string
    {
        $missing = $this->shortfall($goods);

        return $missing > 0
            ? __('The minimum order is :amount. Add goods worth :missing more.', [
                'amount' => format_price($this->amount()),
                'missing' => format_price($missing),
            ])
            : null;
    }

    /**
     * How the minimum meets buying in one click (`min_order_one_click`):
     * `hide_cheaper` — it applies, and a product page whose product alone is
     * under it has no button; `hide` — it applies, and no product page has
     * one while there is a minimum; `exempt` — it does not apply. A row saved
     * when the setting was a tick reads as `hide_cheaper` / `exempt`.
     */
    public function oneClickMode(): string
    {
        $mode = setting('torgash.checkout', 'min_order_one_click', 'hide_cheaper');

        return match (true) {
            in_array($mode, ['hide_cheaper', 'hide', 'exempt'], true) => $mode,
            $mode === false, $mode === 0, $mode === '0', $mode === '' => 'exempt',
            default => 'hide_cheaper',
        };
    }

    /** Whether buying in one click is held to the minimum as well. */
    public function coversOneClick(): bool
    {
        return $this->oneClickMode() !== 'exempt';
    }

    /**
     * Whether a product's page may offer "Buy in one click": a button that
     * takes a phone number for an order the shop then refuses is worse than
     * none. Measured on the product's own price for one — the quantity box
     * can raise it, but the button is drawn before anybody touches it.
     */
    public function offersOneClickFor(Product $product): bool
    {
        if ($this->amount() === 0) {
            return true;
        }

        return match ($this->oneClickMode()) {
            'exempt' => true,
            'hide' => false,
            default => $product->effectivePrice() >= $this->amount(),
        };
    }
}
