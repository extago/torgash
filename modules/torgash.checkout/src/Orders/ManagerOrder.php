<?php

namespace Torgash\Checkout\Orders;

use App\Models\Storefront;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Torgash\Cart\CartManager;
use Torgash\Cart\Models\Cart;
use Torgash\Checkout\CouponManager;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Quote;

/**
 * An order a manager places from the admin, on a customer's behalf (PLAN
 * §63.8). The same one place makes it — PlaceOrder — fed by a throwaway
 * basket the way a return's exchange feeds it (Torgash\Rma\Exchange): the
 * customer's own basket is never touched, and nothing about the manager
 * (their account, the storefront they are looking at, the currency they
 * browsed in) leaks onto the row.
 *
 * What the manager typed for a line's price is applied AFTER placement
 * through the same recalculation the order editor uses: the order is first
 * placed at the list price, so stock, the gift of a promotion and the tax
 * snapshot all happen exactly as for a customer, then the lines are repriced
 * and the money recounted. An order with no goods is refused here — the
 * profit of a service is counted off a service product, never off a bare sum.
 */
final class ManagerOrder
{
    public function __construct(
        protected PlaceOrder $orders,
        protected OrderTotals $totals,
    ) {}

    /**
     * The lines as typed: product, quantity and the unit price in major
     * units, validated before any basket exists.
     *
     * @return list<array{product_id: int, qty: int, unit_price: int}>
     */
    public function lines(Request $request): array
    {
        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:m_catalog_products,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_price' => ['nullable', 'string', 'max:20'],
        ], [
            'lines.required' => __('An order needs at least one product — for a service, a service product that keeps no stock.'),
        ]);

        $lines = [];
        foreach ($validated['lines'] as $line) {
            $id = (int) $line['product_id'];
            $lines[$id] = [
                'product_id' => $id,
                'qty' => ($lines[$id]['qty'] ?? 0) + (int) $line['qty'],
                'unit_price' => self::minorUnits((string) ($line['unit_price'] ?? '')),
            ];
        }

        return array_values($lines);
    }

    /**
     * A basket of its own holding the lines, on the customer's behalf: what a
     * manager puts on an order is their decision, not held to what a customer
     * could put in a basket. Deleted by whoever asked for it (see forget()).
     *
     * @param  list<array{product_id: int, qty: int}>  $lines
     */
    public function cartFor(array $lines, ?int $storefrontId): CartManager
    {
        $row = Cart::create([
            'token' => Cart::newToken(),
            'user_id' => null,
            'storefront_id' => $storefrontId,
            'items' => [],
            'last_active_at' => now(),
        ]);

        $cart = (new CartManager)->useToken($row->token)->onBehalf();

        foreach ($lines as $line) {
            $cart->add($line['product_id'], $line['qty']);
        }

        if ($cart->items()->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => __('None of these products can be ordered right now.'),
            ]);
        }

        return $cart;
    }

    public function forget(CartManager $cart): void
    {
        Cart::where('token', $cart->token())->delete();
    }

    /**
     * Where the order goes, from what the manager typed — never the admin's
     * session. A town picked from the registry names the place (what zones
     * and carriers match on) and its country; a typed one leaves the country
     * and the postal code to do the matching.
     */
    public function location(Request $request): Location
    {
        $placeId = ((int) $request->input('place_id')) ?: null;
        $postal = trim((string) $request->input('postal_code', '')) ?: null;

        if ($placeId !== null) {
            return Location::forPlace($placeId, $postal);
        }

        return new Location(
            country: strtoupper(trim((string) $request->input('country', ''))) ?: null,
            postal: $postal,
        );
    }

    /** The deliveries the shop offers this basket to that address, priced now. */
    public function quote(CartManager $cart, Location $location, int|string|null $selected = null): Quote
    {
        $quote = hook_filter('checkout.delivery.quote', null, [
            'cart' => $cart,
            'location' => $location,
            'selected' => $selected,
        ]);

        return $quote instanceof Quote ? $quote : new Quote(
            location: $location,
            zone: null,
            options: collect(),
            selected: null,
            subtotal: $cart->total(),
            discount: 0,
        );
    }

    /** What PlaceOrder asks of the form, for this basket and this delivery. */
    public function rules(Quote $quote, int|string|null $shippingMethodId, CartManager $cart): array
    {
        // Paid in the shop's own currency: the methods that can take it,
        // not the ones for whatever the admin was last browsing in.
        return $this->orders->rules($quote, $shippingMethodId === null ? null : (string) $shippingMethodId, $cart->needsDelivery(), shop_currency());
    }

    /**
     * @param  array<string, mixed>  $validated  what rules() asked for
     * @param  list<array{product_id: int, qty: int, unit_price: int}>  $lines
     */
    public function place(
        array $validated,
        CartManager $cart,
        Location $location,
        Quote $quote,
        ?User $customer,
        Storefront $storefront,
        array $lines,
        User $manager,
    ): Order {
        if ($this->orders->shippingIsActive() && $cart->needsDelivery() && $quote->options->isEmpty()) {
            throw ValidationException::withMessages([
                'shipping_method_id' => __('Nothing can be delivered to that address right now: give a delivery method a rate that covers it, or choose one that needs no address.'),
            ]);
        }

        // A town picked from the registry knows its country; the form's own
        // country box may have been left on "select", and the row keeps both.
        if (empty($validated['country']) && $location->country) {
            $validated['country'] = $location->country;
        }

        $order = $this->orders->place(
            validated: $validated,
            cart: $cart,
            coupons: app(CouponManager::class),
            location: $location,
            quote: $quote,
            as: [
                // The customer's, or nobody's: a guest order has no account,
                // and customer_ref pairs it with the next one by its contacts.
                'user_id' => $customer?->id,
                'storefront_id' => $storefront->id,
                'locale' => content_base_locale(),
                // The shop's own books: a manager never prices through the
                // exchange rate of whatever they were last browsing in.
                'charge_currency' => null,
            ],
        );

        $this->reprice($order, $lines);

        OrderLog::record($order, 'Created from the admin by :manager', ['manager' => $manager->name]);

        return $order->fresh();
    }

    /**
     * The manager's prices onto the plain lines of what they ordered — a
     * configured product's parts and a promotion's gift keep theirs. Zero is
     * a price too (a bonus, a sample); an empty box is the list price.
     *
     * @param  list<array{product_id: int, qty: int, unit_price: int}>  $lines
     */
    protected function reprice(Order $order, array $lines): void
    {
        $typed = collect($lines)->keyBy('product_id');
        $items = $order->items;
        $changed = false;

        foreach ($items as $index => $item) {
            $line = $typed->get((int) ($item['product_id'] ?? 0));
            if (! $line || $line['unit_price'] === null || ($item['parent_key'] ?? null) !== null || ($item['options'] ?? []) !== []) {
                continue;
            }
            if ((int) $item['unit_price'] === $line['unit_price']) {
                continue;
            }
            $items[$index]['unit_price'] = $line['unit_price'];
            $items[$index]['line_total'] = (int) $item['qty'] * $line['unit_price'];
            $changed = true;
        }

        if ($changed) {
            $this->totals->recalculate($order, $items);
        }
    }

    /** "12,50" or "12.50" → 1250; an empty box → null, "the list price". */
    public static function minorUnits(string $value): ?int
    {
        $value = trim($value);

        return $value === '' ? null : max(0, (int) round((float) str_replace([' ', ','], ['', '.'], $value) * 100));
    }
}
