<?php

namespace Torgash\Shipping;

use App\Core\Storefronts\StorefrontRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Checkout\Shipping\LocationStore;
use Torgash\Shipping\Http\Admin\OrderDeliveryController;
use Torgash\Shipping\Http\Admin\OrderShippingController;

/**
 * Delivery as its own module. `checkout` never references anything in here
 * directly — it asks through hooks, so a shop that sells only digital goods
 * uninstalls this module and the checkout loses its delivery step with nothing
 * left dangling (docs/PLAN.md §12.0).
 */
class ShippingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        Route::middleware(['web', 'auth', 'can:shipping.manage'])
            ->prefix('admin/shipping')
            ->name('admin.shipping.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        // What must be true about delivery, for whoever is watching. Nobody
        // fires this filter when `torgash.guard` is absent, so it is a
        // statement about our own data and not a dependency on that module.
        hook_listen('guard.invariants', fn (array $all) => $all
            + (new Invariants\ShippingInvariants)->register());

        // Collection from the shop's own points, as a carrier whose depots are
        // ours. Registered through the same hook a third-party carrier uses —
        // there is no privileged built-in provider — and useful only where
        // something answers shipping.pickup_points with actual points.
        hook_listen('shipping.providers', fn (array $providers) => $providers + [
            Pickup\PickupProvider::KEY => Pickup\PickupProvider::class,
        ]);

        // An order that is collected from a shop comes off THAT shop's shelf.
        // Overrides rather than defers: consolidation is a preference, this is
        // what the customer was promised when they picked the point, and the
        // point was only on the list because it held the whole order.
        hook_listen('catalog.stock.prefer_warehouse', function ($value, array $context) {
            $details = (array) ($context['order']?->shipping_details ?? []);

            return ((int) ($details['pickup_point_id'] ?? 0)) ?: $value;
        });

        // Where to collect it, on the customer's own confirmation page. Only
        // this module's own provider is rendered here: a general "let each
        // carrier describe its snapshot" seam is worth adding when there are
        // two cases for it, and today Nova Poshta's branch is already shown by
        // the carrier's own fields view on the order card.
        hook_listen('checkout.confirmation.delivery', function (string $value, array $context) {
            $details = (array) ($context['order']->shipping_details ?? []);

            if (($details['carrier'] ?? null) !== Pickup\PickupProvider::KEY) {
                return $value;
            }

            return $value.view('torgash.shipping::checkout.pickup-confirmation', [
                'details' => $details,
            ])->render();
        });

        // The delivery estimate under the cart total. Lived on
        // CheckoutServiceProvider until delivery moved out; the cart still knows
        // nothing about either shipping or checkout — same idiom as the coupon
        // box. Not part of the cart total on purpose: until the customer names a
        // town, "shipping" is whatever the catch-all zone charges, and quietly
        // adding a guess to the number they are about to pay is worse than
        // showing it beside it.
        // "Delivery to <your town>" under the product page's buy panel — the
        // same estimate the cart makes, for this one product (ProductEstimate).
        // FIRST under the panel (the one card here that is about this product
        // and this visitor, before the shop's general cards); nothing for a
        // product nobody sends.
        // The visitor's own on a page kept ready for guests (PLAN §61): their
        // town — kept as drawn for whoever chose the same one; a date in it
        // keeps the page only until it moves (the note below says so).
        hook_listen('catalog.product.after_buy_box', function (string $value, array $context) {
            if (! setting('torgash.shipping', 'product_estimate', true)) {
                return $value;
            }

            return personal('torgash.shipping::partials.product-delivery-part', ['product' => $context['product']], reusable: true).$value;
        });

        hook_listen('cart.summary_after_total', function (string $value, array $context) {
            $cart = $context['cart'];

            if ($cart->items()->isEmpty() || ! setting('torgash.shipping', 'cart_estimate', false)) {
                return $value;
            }

            $store = app(LocationStore::class);

            return $value.view('torgash.shipping::partials.cart-delivery', [
                'quote' => app(Quoter::class)->for($cart, $store->current()),
                'town' => $store->town(),
            ])->render();
        });

        // The method's name on an admin screen, in the reader's language when
        // the order's snapshot is only another language of it (see
        // Order::sameWordsFor()).
        hook_listen('checkout.order.shipping_method_name', function (?string $value, array $context) {
            $id = (int) (((array) ($context['order']->shipping_details ?? []))['shipping_method_id'] ?? 0);

            return \Torgash\Checkout\Models\Order::sameWordsFor($value, $id ? \Torgash\Shipping\Models\ShippingMethod::find($id)?->name : null);
        });

        // Every language each method is named in, for the orders list's filter
        // (Torgash\Checkout\Orders\MethodNames).
        hook_listen('checkout.order.shipping_method_translations', fn (array $maps) => [
            ...$maps, ...\Torgash\Shipping\Models\ShippingMethod::query()->pluck('name')->all(),
        ]);

        // The order card's "this delivery still has no price" box. Only for an
        // order that was actually placed that way — the flag is written into
        // the delivery snapshot at checkout and cleared when a human prices it.
        hook_listen('checkout.order.shipping_extra', function (string $value, array $context) {
            /** @var \Torgash\Checkout\Models\Order $order */
            $order = $context['order'];
            $details = (array) ($order->shipping_details ?? []);

            // The loud box first, when there is still no price at all; then the
            // ordinary editor, which is on the card whether or not anything is
            // wrong — changing how a placed order ships is routine work.
            if (! empty($details['pending_quote'])) {
                $value .= view('torgash.shipping::admin.orders.pending-quote', [
                    'order' => $order,
                    'reason' => (string) ($details['quote_reason'] ?? ''),
                ])->render();
            }

            // Re-quoting proper: pick a town, see what is offered there for
            // this order's goods, choose a method and the carrier's own fields
            // (a branch picker) under it.
            $quote = app(OrderDeliveryController::class)->quoteFor($order);
            $value .= view('torgash.shipping::admin.orders.delivery', [
                'order' => $order,
                'quote' => $quote,
            ])->render();

            // The by-hand fallback stays: a destination no zone covers, or a
            // shop with no locations registry, still has to be shippable. It is
            // folded away unless it is the only way (nothing offered here) or
            // is already how this order ships — two open forms that both pick a
            // method read as one form asking twice.
            return $value.view('torgash.shipping::admin.orders.method', [
                'order' => $order,
                'names' => app(OrderShippingController::class)->offeredNames($order),
                'manual' => ! empty($details['manual']),
                'offered' => $quote->options->isNotEmpty(),
            ])->render();
        });

        // The seam checkout calls. Absent module → hook returns the null it was
        // given → checkout renders no delivery step and the order is shipping-free.
        hook_listen('checkout.delivery.quote', function ($carry, array $context) {
            return app(Quoter::class)->for(
                $context['cart'],
                $context['location'],
                $context['selected'] ?? null,
            );
        });

        // Freeze the delivery onto the order being placed: a carrier's own
        // snapshot (branch, service type, promised date) and, for an option
        // with no price yet, WHY it has none — that flag is what puts the order
        // on the "someone must price this" list and what the admin's action
        // clears (PLAN §12.3).
        hook_listen('checkout.delivery.snapshot', function ($carry, array $context) {
            $option = $context['option'] ?? null;

            if (! $option) {
                return null;
            }

            $snapshot = ($option->offer_key ?? null) === null
                ? null
                : app(ShippingProviders::class)->get($option->provider)?->snapshot(
                    $context['location'],
                    $option->offer_key,
                    (array) ($context['fields'] ?? []),
                    $option->method->provider_config ?? [],
                );

            if (! ($option->pending_quote ?? false)) {
                return $snapshot;
            }

            return ($snapshot ?? []) + [
                'pending_quote' => true,
                'quote_reason' => $option->quote_reason,
                'shipping_method_id' => $option->method->id,
            ];
        });
    }
}
