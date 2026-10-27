<?php

namespace Torgash\Shipping\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Orders\OrderBasket;
use Torgash\Checkout\Orders\OrderTotals;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;
use Torgash\Checkout\Shipping\Quote;
use Torgash\Shipping\Conditions\CartFacts;
use Torgash\Shipping\ShippingOptions;

/**
 * Re-quoting a placed order's delivery: a different town, a different method,
 * a different Nova Poshta branch.
 *
 * This is the same machinery checkout runs, pointed at an order instead of a
 * cart — Torgash\Cart\Contracts\Basket is what makes that literal rather than a
 * copy. The order supplies the money (what the customer is actually being
 * charged, coupon included) and the catalogue supplies the weight and volume,
 * so zones, rate bands, carrier prices and method conditions all answer exactly
 * as they did at checkout.
 *
 * A carrier's own field block (the branch picker) is rendered here by including
 * the view the provider names, and read back through the same
 * `checkout.delivery.snapshot` hook checkout uses. No carrier module knows this
 * screen exists.
 */
class OrderDeliveryController extends Controller
{
    public function __construct(protected ShippingOptions $options, protected OrderTotals $totals) {}

    /** The offered-methods block, re-rendered whenever the destination changes. */
    public function methods(Request $request, Order $order)
    {
        return view('torgash.shipping::admin.orders.delivery-methods', [
            'order' => $order,
            'quote' => $this->quote($request, $order),
        ]);
    }

    public function apply(Request $request, Order $order)
    {
        $validated = $request->validate([
            'address_line' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'place_id' => ['nullable', 'integer'],
            'shipping_method_id' => ['required', 'string'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $quote = $this->quote($request, $order);
        $option = $quote->option($validated['shipping_method_id']);

        // Recomputed here rather than trusted from the form, exactly as
        // CheckoutController does: whether a method is offered at all is a
        // property of the winning zone, so a hand-built request cannot order a
        // method that is not on offer to this address.
        if (! $option) {
            return $this->refuse($order, $quote, __('That delivery method is not offered for this address.'));
        }

        // The carrier freezes what it needs (branch, service type, promised
        // date) and validates its own fields on the way — a missing branch
        // comes back as a message on this block rather than as a 422 the
        // screen cannot show.
        try {
            $details = hook_filter('checkout.delivery.snapshot', null, [
                'option' => $option,
                'location' => $quote->location,
                'fields' => (array) $request->input('shipping_fields', []),
            ]);
        } catch (ValidationException $e) {
            return $this->refuse($order, $quote, $e->getMessage());
        }

        $wasMethod = $order->shipping_method_name;

        // An admin may still overrule the quoted price; leaving the field empty
        // keeps whatever the quote said.
        $cost = ($validated['shipping_cost'] ?? '') === ''
            ? ($option->cost ?? 0)
            : (int) round((float) str_replace(',', '.', (string) $validated['shipping_cost']) * 100);

        DB::transaction(function () use ($order, $validated, $option, $details, $cost, $quote, $wasMethod) {
            // Address parts are written only when the screen sent them, so a
            // partial request can never blank an address it was not editing.
            foreach (['address_line', 'city', 'country'] as $field) {
                if (($validated[$field] ?? '') !== '') {
                    $order->{$field} = $validated[$field];
                }
            }

            $order->postal_code = ($validated['postal_code'] ?? '') ?: null;
            $order->place_id = $quote->location->placeId;
            $order->shipping_method_name = $option->label;
            $order->shipping_cost = $cost;
            $order->shipping_details = $details ?? [];
            $order->save();

            $this->totals->recalculate($order);

            OrderLog::record($order, 'Delivery re-quoted: :from → :to (:cost), :city', [
                'from' => $wasMethod ?? '—',
                'to' => $option->label,
                'cost' => format_price($cost, $order->currency_code),
                'city' => (string) $order->city,
            ]);
        });

        // The order total, the address block and the history all changed, and
        // they live in three different cards. Rather than swapping each one
        // from here — which would mean this module rendering checkout's own
        // partials — the card is simply reloaded.
        return response()->noContent()->withHeaders([
            'HX-Redirect' => route('admin.orders.show', $order),
        ]);
    }

    /**
     * Refuse without losing the screen: the block comes back saying why.
     *
     * Answered 200, not 422, deliberately — htmx does not swap a response it
     * considers an error, and the refusal IS the screen the admin needs to
     * see. Nothing was written; the message says so.
     */
    protected function refuse(Order $order, Quote $quote, string $message)
    {
        return view('torgash.shipping::admin.orders.delivery-methods', [
            'order' => $order,
            'quote' => $quote,
            'error' => $message,
        ]);
    }

    /**
     * The order's own destination, quoted — what the card shows before anybody
     * touches the picker. Public because the order card renders this block
     * through a hook and has no request of its own to quote from.
     */
    public function quoteFor(Order $order): Quote
    {
        return $this->quote(new Request, $order);
    }

    /**
     * What this order can be delivered as, to wherever the screen currently
     * says it is going.
     *
     * The destination comes from the request when the admin is editing and
     * falls back to what the order already holds, so the first render shows the
     * order's own address rather than an empty picker.
     */
    protected function quote(Request $request, Order $order): Quote
    {
        $this->prefillCarrierFields($request, $order);

        $location = $this->location($request, $order);
        $basket = new OrderBasket($order);
        $payable = max(0, (int) $order->subtotal - (int) $order->discount_amount);

        $zone = $this->options->zone($location);
        $options = $this->options
            ->inZone($zone, Package::forCart($basket, $payable), $location, CartFacts::forCart($basket, $payable))
            ->keyBy(fn (object $option) => (string) $option->id);

        $selected = $request->filled('shipping_method_id')
            ? $options->get((string) $request->input('shipping_method_id'))
            : null;

        return new Quote(
            location: $location,
            zone: $zone,
            options: $options,
            // Falling back to the first offer is what makes the block show a
            // price the moment the town changes: the method the order was
            // placed with may not be offered where it is now going.
            selected: $selected ?? $this->matchingStoredOption($options, $order) ?? $options->first(),
            subtotal: (int) $order->subtotal,
            discount: (int) $order->discount_amount,
        );
    }

    /**
     * Show the branch the order was actually placed with, first time in.
     *
     * A carrier renders its field block already filled in from what the FORM is
     * carrying — that is how a re-quote keeps the customer's choice selected
     * (NovaPoshtaProvider::chosen reads `shipping_fields.warehouse_ref` off the
     * current request). On the order card there is no form yet, so the order's
     * own frozen snapshot is put where the carrier looks. Nothing is invented:
     * the carrier still re-validates that the branch is on offer here, and drops
     * it if the town or the variant has changed.
     */
    protected function prefillCarrierFields(Request $request, Order $order): void
    {
        if ($request->has('shipping_fields')) {
            return;
        }

        $frozen = array_filter(
            (array) ($order->shipping_details ?? []),
            fn ($key) => in_array($key, ['warehouse_ref', 'address'], true),
            ARRAY_FILTER_USE_KEY,
        );

        if ($frozen !== []) {
            request()->merge(['shipping_fields' => $frozen]);
        }
    }

    protected function location(Request $request, Order $order): Location
    {
        $placeId = (int) ($request->input('place_id') ?: $order->place_id);
        $postal = $request->has('postal_code') ? $request->input('postal_code') : $order->postal_code;

        if ($placeId > 0) {
            return Location::forPlace($placeId, $postal ?: null);
        }

        return new Location(
            country: $request->input('country') ?: $order->country,
            postal: $postal ?: null,
        );
    }

    /**
     * The option the order is already on, found by the name it was sold under.
     * Ids are not comparable across quotes — a method's variants are keyed by
     * offer, and the order stores the label, not the id — so the name is what
     * there is. Missing it only means the picker starts on the first option.
     *
     * @param  \Illuminate\Support\Collection<string, object>  $options
     */
    protected function matchingStoredOption($options, Order $order): ?object
    {
        return $order->shipping_method_name === null
            ? null
            : $options->first(fn (object $option) => $option->label === $order->shipping_method_name);
    }
}
