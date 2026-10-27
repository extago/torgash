<?php

namespace Torgash\Checkout\Orders;

use App\Core\Settings\SettingsManager;
use Torgash\Cart\CartManager;
use Torgash\Cart\Models\Cart;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\CouponManager;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Quote;

/**
 * Buying with a phone number and nothing else.
 *
 * The order is an ORDINARY order and goes through PlaceOrder, exactly as the
 * checkout form and the API do. Older engines often make the opposite choice — one click is
 * a "call request" there, and because a call request is not an order it has to
 * invent an email address for the buyer
 * to place one anyway. We already refused made-up accounts when CustomerRef was
 * written, and an order that is not an order is worse: no stock, no statuses, no
 * currencies, no confirmation.
 *
 * The problem one click actually has is that PlaceOrder requires a delivery
 * option and a payment method that are really on offer, and the customer chose
 * neither. The answer is not to relax that — it is the single place every order
 * is checked, and a hole in it is a hole for the API too. It is to let the shop
 * nominate REAL methods for the case: a "we will agree it when we call you"
 * delivery and payment, which is what a shop taking one-click orders does on
 * the phone anyway. The manager changes them afterwards on the order screen.
 *
 * So one click is: a throwaway cart holding the one product, quoted and placed
 * like any other. The cart is its own row (that is what the token is for) so a
 * visitor's real basket is not touched, emptied or ordered by accident.
 *
 * On the cart page it is the other way round, and on purpose: there the
 * customer asked for THE BASKET, so the basket itself is quoted and placed —
 * and emptied by PlaceOrder, exactly as the full checkout empties it.
 */
class OneClick
{
    public function __construct(protected SettingsManager $settings) {}

    /** Whether the shop has turned it on for a product page AND said which methods it uses. */
    public function isAvailable(): bool
    {
        return (bool) setting('torgash.checkout', 'one_click') && $this->methodsReady() && $this->forThisVisitor();
    }

    /**
     * Who is offered it (`one_click_audience`): everybody, only guests — a shop
     * whose customers with an account are meant to use the full checkout, with
     * their saved addresses — or only those signed in. Asked here, so the
     * button, the cart's button and the POST behind them agree.
     */
    public function forThisVisitor(): bool
    {
        return match ((string) setting('torgash.checkout', 'one_click_audience', 'everyone')) {
            'guests' => ! auth()->check(),
            'signed_in' => auth()->check(),
            default => true,
        };
    }

    /**
     * The same on the CART page, for everything in the basket — a switch of
     * its own (`one_click_cart`), because a shop happy to take one phone by
     * phone may not want a basket of forty lines agreed that way.
     */
    public function isAvailableForCart(): bool
    {
        return (bool) setting('torgash.checkout', 'one_click_cart') && $this->methodsReady() && $this->forThisVisitor();
    }

    /** Both nominated methods exist — without them no order can be placed at all. */
    public function methodsReady(): bool
    {
        return $this->paymentMethod() !== null
            && ($this->shippingMethodId() !== null || ! $this->shippingIsActive());
    }

    /** Every line of the basket passes the product rule below — one that does not keeps the button away. */
    public function offersForCart(CartManager $cart): bool
    {
        $items = $cart->items();

        return $items->isNotEmpty() && $items->every(fn ($item) => $this->offersFor($item->product));
    }

    /**
     * Whether THIS product's page offers the button. Never for what cannot be
     * bought — the order would be refused after the customer typed their
     * number — and, when the shop ticked statuses, only for those: "one click"
     * on a preorder is a promise of a date, and a shop may not want to make it
     * by phone. The status is the EFFECTIVE one (a plain in-stock row with
     * nothing left is out of stock), and "running low" is in stock.
     */
    public function offersFor(Product $product): bool
    {
        if (! $product->isOrderable()) {
            return false;
        }

        $statuses = array_values(array_filter((array) setting('torgash.checkout', 'one_click_statuses', [])));

        if ($statuses === []) {
            return true;
        }

        $key = $product->effectiveAvailabilityKey();

        return in_array($key === 'low_stock' ? 'in_stock' : $key, $statuses, true);
    }

    /** The shipping method the shop nominated, as an id the quote can be asked for. */
    public function shippingMethodId(): ?string
    {
        $id = trim((string) setting('torgash.checkout', 'one_click_shipping_id', ''));

        return $id === '' ? null : $id;
    }

    public function paymentMethod(): ?PaymentMethod
    {
        $id = (int) setting('torgash.checkout', 'one_click_payment_id', 0);

        return $id > 0 ? PaymentMethod::where('id', $id)->where('enabled', true)->first() : null;
    }

    /**
     * Nominate a method — the flag lives on the METHOD's own form, next to the
     * person setting it up, and there is exactly one of each because "the
     * method we use when nobody chose" is a single answer.
     */
    public function nominateShipping(string $methodId, bool $chosen): void
    {
        $current = $this->shippingMethodId();

        if ($chosen) {
            $this->settings->set('torgash.checkout', 'one_click_shipping_id', $methodId);
        } elseif ($current === $methodId) {
            $this->settings->set('torgash.checkout', 'one_click_shipping_id', '');
        }
    }

    public function nominatePayment(int $methodId, bool $chosen): void
    {
        $current = (int) setting('torgash.checkout', 'one_click_payment_id', 0);

        if ($chosen) {
            $this->settings->set('torgash.checkout', 'one_click_payment_id', $methodId);
        } elseif ($current === $methodId) {
            $this->settings->set('torgash.checkout', 'one_click_payment_id', 0);
        }
    }

    /**
     * A cart of its own, holding just this product.
     *
     * Not the visitor's: they clicked one button on one product, and ordering
     * whatever else they had put aside — or emptying it afterwards — is not what
     * they asked for. A cart is a row addressed by a token, so a second one
     * costs one insert and is deleted when the order is placed.
     */
    public function cartFor(int $productId, int $qty, array $config = []): CartManager
    {
        $row = Cart::create([
            'token' => Cart::newToken(),
            'user_id' => auth()->id(),
            'storefront_id' => current_storefront()?->id,
            'items' => [],
            'last_active_at' => now(),
        ]);

        $cart = (new CartManager)->useToken($row->token);

        // WITH the configuration, through the same `cart.line.config` the
        // basket's own button goes through. Without it whatever the visitor
        // had chosen in the buy box was silently dropped — the order was the
        // bare product at the bare price — and a required question was
        // refused even when it HAD been answered. The throwaway row goes with
        // a refusal.
        try {
            $cart->add($productId, max(1, $qty), $config);
        } catch (\Throwable $e) {
            $this->discard($cart);

            throw $e;
        }

        return $cart;
    }

    public function discard(CartManager $cart): void
    {
        Cart::where('token', $cart->token())->delete();
    }

    /**
     * The quote for a cart with no address, and the option the shop nominated.
     *
     * If that option is not among the offers, one click is genuinely impossible
     * here rather than merely awkward: the shop's nominated method has no rate
     * covering a customer whose address nobody knows yet. Refusing is the
     * honest answer, and the admin screen says which method to give a rate to.
     */
    public function quote(CartManager $cart, CouponManager $coupons): Quote
    {
        $quote = hook_filter('checkout.delivery.quote', null, [
            'cart' => $cart,
            'location' => new Location,
            'selected' => $this->shippingMethodId(),
        ]);

        return $quote instanceof Quote ? $quote : new Quote(
            location: new Location,
            zone: null,
            options: collect(),
            selected: null,
            subtotal: $cart->total(),
            discount: $coupons->discount(),
        );
    }

    public function offersNominated(Quote $quote): bool
    {
        return ! $this->shippingIsActive()
            || in_array((string) $this->shippingMethodId(), array_map('strval', $quote->offerIds()), true);
    }

    /** The same test PlaceOrder uses: is there a shipping module at all. */
    protected function shippingIsActive(): bool
    {
        return \Illuminate\Support\Facades\Route::has('checkout.quote');
    }
}
