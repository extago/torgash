<?php

namespace Torgash\Checkout\Http;

use App\Core\Support\FormGuard;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Torgash\Cart\CartManager;
use Torgash\Checkout\CouponManager;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\OrderKey;
use Torgash\Checkout\Orders\PlaceOrder;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\LocationStore;
use Torgash\Checkout\Shipping\Quote;
use Torgash\Checkout\Totals;

class CheckoutController extends Controller
{
    /**
     * The checkout's name among the shop's guarded forms — what the CAPTCHA
     * settings tick ("Placing an order"). It takes only what a module adds
     * (FormGuard::checkAdded() says why), and nothing is asked until a shop
     * ticks it.
     */
    public const FORM = 'checkout';

    public function create(CartManager $cart, CouponManager $coupons, LocationStore $store)
    {
        if ($cart->items()->isEmpty()) {
            return redirect()->route('cart.index');
        }

        $prefill = [];
        if ($user = auth()->user()) {
            $address = $user->defaultAddress();
            $prefill = [
                'customer_name' => $address->full_name ?? $user->name,
                'email' => $user->email,
                'phone' => $address->phone ?? null,
                'address_line' => $address->address_line ?? null,
                'city' => $address->city ?? null,
                'postal_code' => $address->postal_code ?? null,
            ];
        }

        return view('torgash.checkout::form', [
            'cart' => $cart,
            // A basket of things that are not sent anywhere skips the step.
            'needsDelivery' => $cart->needsDelivery(),
            'quote' => $this->quote($cart, $coupons, $store->current(), old('shipping_method_id')),
            'appliedCoupon' => $coupons->current(),
            'prefill' => $prefill,
            // The town picked on the cart page outranks the saved address: it
            // is the more recent answer to the same question, and the methods
            // below are already quoted for it.
            'town' => old('city') ?? $store->town() ?? ($prefill['city'] ?? null),
            'postal' => old('postal_code') ?? $store->postal() ?? ($prefill['postal_code'] ?? null),
            'country' => old('country') ?? $store->country(),
            'placeId' => old('place_id') ?? $store->placeId(),
            // Only the ones that can take money for an order in the currency
            // this customer is shopping in — either directly or by charging in
            // their own (which the form says out loud). A method that can do
            // neither is not a choice, and offering it is offering a Pay button
            // that fails at the last step.
            'paymentMethods' => $this->paymentMethods(),
        ]);
    }

    public function store(Request $request, CartManager $cart, CouponManager $coupons, LocationStore $store, PlaceOrder $placer, FormGuard $guard)
    {
        if ($cart->items()->isEmpty()) {
            return redirect()->route('cart.index');
        }

        // A CAPTCHA when the shop asks for one on orders. Before the rest of
        // the form, so a right answer is checked once and KEPT if the order
        // is then refused for something else — a mistyped street does not
        // cost a second sum; it is spent when the order is placed (below).
        if ($refusal = $guard->checkAdded($request, self::FORM)) {
            throw ValidationException::withMessages(['captcha' => $refusal['message']]);
        }

        // The offer the customer was shown, recomputed here rather than trusted
        // from the form: the price and the very availability of a method come
        // from its rate in the winning zone, so a method that has none cannot be
        // ordered even by a hand-built request.
        //
        // Quoted from THIS request, not from the session, because the town in
        // the submitted form is the one being ordered to — a customer who picked
        // Kyiv earlier and then typed another town must not be charged Kyiv's
        // rate. The location is kept: a provider snapshot needs it.
        $location = $store->fromRequest($request);
        $quote = $this->quote($cart, $coupons, $location, $request->input('shipping_method_id'));

        // With the shipping module installed a delivery choice is always
        // required — even when nothing is offered (no zone covers the address),
        // so that address genuinely cannot be ordered to. Only a shop with no
        // shipping module at all skips the field; PlaceOrder decides which.
        $validated = $request->validate(
            $placer->rules($quote, $request->input('shipping_method_id'), $cart->needsDelivery()),
        );

        // The shop may hold an order until its contact is confirmed by a code
        // (PLAN §44) — the widget under the contact fields; asked here again,
        // because a widget is a suggestion and a POST is not.
        $verification = app(\App\Core\Verification\FormVerification::class);
        if ($refusal = $verification->refusal($request, 'checkout', ['phone' => $validated['phone'] ?? null, 'email' => $validated['email'] ?? null], $request->input('country'))) {
            throw ValidationException::withMessages([$verification->kindFor('checkout') ?? 'email' => $refusal]);
        }

        // Everything an order IS lives in PlaceOrder: the shop API completes
        // carts too, and a second implementation would be a second shop. This
        // method gathers the answers and decides where the customer goes next.
        $order = $placer->place(
            $validated,
            $cart,
            $coupons,
            $location,
            $quote,
            (array) $request->input('shipping_fields', []),
            $request->boolean('save_address'),
        );

        $request->session()->put('last_order_number', $order->order_number);
        $guard->accepted($request, self::FORM);

        // Whoever takes the money decides where the customer goes next: an
        // online gateway sends them to its own page, and the confirmation waits
        // for them to come back. Checkout knows nothing about payments, and a
        // shop with no gateway module behaves exactly as before.
        $elsewhere = hook_filter('checkout.after_place_redirect', null, ['order' => $order]);

        return $elsewhere
            ? redirect()->away($elsewhere)
            : redirect()->route('checkout.confirmation', $order->order_number);
    }

    public function confirmation(Request $request, Order $order)
    {
        $this->authorizeCustomerView($request, $order);

        // Poured into another order (PLAN §40.10/4): the goods and the money
        // are there, and the link from yesterday's letter goes there — by that
        // order's own key, since the one in hand opened only this one.
        if ($order->merged_into_id) {
            return redirect()->to($order->mergeRoot()->customerUrl());
        }

        $productIds = collect($order->items ?? [])->pluck('product_id')->filter()->unique()->values();

        return view('torgash.checkout::confirmation', [
            'order' => $order,
            // The owner reads it inside the account, beside its menu; a guest
            // by the signed link, on its own.
            'inAccount' => auth()->check() && $order->user_id === auth()->id(),
            // "Thank you" only on the way out of checkout — the session keeps
            // the number for the payment's return, so it is bounded by time
            // too, or a week-old order would still say thanks.
            'justPlaced' => $request->session()->get('last_order_number') === $order->order_number
                && $order->created_at?->gt(now()->subHours(2)),
            // Every line's picture and page in one query.
            'products' => $productIds->isEmpty() ? collect() : \Torgash\Catalog\Models\Product::query()
                ->whereIn('id', $productIds)->with('media')->get()->keyBy('id'),
            'history' => $this->customerHistory($order),
        ]);
    }

    /**
     * What a customer is told of the order's history: placed, each status it
     * went through, each payment state — read from the order's log, in the
     * reader's language. The rest of the log (a manager's edits, stock, the
     * staff's notes) is the shop's own business.
     *
     * @return list<array{label: string, at: \Illuminate\Support\Carbon}>
     */
    protected function customerHistory(Order $order): array
    {
        return \Torgash\Checkout\Models\OrderLog::query()
            ->where('order_id', $order->id)
            ->where(fn ($q) => $q->whereIn('description', ['Order placed', ':from → :to', 'Status set to :status'])
                ->orWhere('description', 'like', 'Payment: %'))
            ->orderBy('id')
            ->get()
            ->map(function (\Torgash\Checkout\Models\OrderLog $line) {
                $params = (array) ($line->params ?? []);
                $word = fn ($value) => match (true) {
                    is_array($value) && isset($value['__']) => __((string) $value['__']),
                    is_array($value) => (string) pick_translation($value),
                    default => (string) $value,
                };

                $label = match (true) {
                    $line->description === 'Order placed' => __('Placed'),
                    $line->description === 'Status set to :status' => $word($params['status'] ?? ''),
                    $line->description === ':from → :to' => $word($params['to'] ?? ''),
                    default => __('Payment').': '.mb_strtolower($word($params['to'] ?? '')),
                };

                return ['label' => $label, 'at' => $line->created_at];
            })
            ->filter(fn ($entry) => trim($entry['label']) !== '')
            ->values()
            ->all();
    }

    public function invoice(Request $request, Order $order)
    {
        $this->authorizeCustomerView($request, $order);

        if ($order->merged_into_id) {
            return redirect()->to($order->mergeRoot()->invoiceUrl());
        }

        return view('torgash.checkout::invoice', [
            'order' => $order,
            // Signed, so the way back works for the guest who arrived here by
            // signature — an unsigned link would 404 the person who was just
            // reading the page it goes to.
            'backUrl' => $order->customerUrl(),
        ]);
    }

    /**
     * The delivery options for this cart and destination, asked of the shipping
     * module through a hook. With that module absent the hook returns the null
     * it was handed and checkout falls back to an empty Quote — a real object so
     * the summary and Totals need no null-handling, just with nothing to pick
     * and no shipping cost.
     */
    /**
     * Which route the key was made for — the two pages have keys of their own,
     * so a link to the invoice is not a link to the order and back.
     *
     * Read off the matched route rather than passed in, and with the locale
     * prefix dropped: `ru.checkout.invoice` is the Russian shape of
     * `checkout.invoice`, not another page.
     */
    protected function keyRoute(Request $request): string
    {
        $name = (string) $request->route()?->getName();

        return str_contains($name, 'checkout.') ? substr($name, strpos($name, 'checkout.')) : $name;
    }

    protected function quote(CartManager $cart, CouponManager $coupons, Location $location, int|string|null $selected = null): Quote
    {
        $quote = hook_filter('checkout.delivery.quote', null, [
            'cart' => $cart,
            'location' => $location,
            'selected' => $selected,
        ]);

        if ($quote instanceof Quote) {
            return $quote;
        }

        return new Quote(
            location: $location,
            zone: null,
            options: collect(),
            selected: null,
            subtotal: $cart->total(),
            discount: $coupons->discount(),
        );
    }

    /**
     * Which methods may be offered here — asked of PlaceOrder, which is also
     * what validates the choice on submit. One list, one rule: a method shown
     * on the screen is exactly a method the placement will accept.
     *
     * @return Collection<int, PaymentMethod>
     */
    protected function paymentMethods(): Collection
    {
        return app(PlaceOrder::class)->paymentMethods();
    }

    /**
     * Three ways to be allowed to look at an order, and a signature is the
     * third.
     *
     * The first two — it is your account's, or you placed it in this session —
     * are what a guest had until now, and the second lasts exactly as long as a
     * browser stays open. So a guest who closed the tab could never see their
     * own order again: no status, no invoice, and (once returns exist) no way
     * to ask for one. The answer is the idiom the pay-later link already uses:
     * a SIGNED url, which survives into tomorrow's email and cannot be guessed
     * from TG-000123 — the order number is sequential, and that is precisely
     * why the number alone was never allowed to be the key.
     *
     * The key a customer forwards to somebody shows that one order and nothing
     * else — but it does so in every LANGUAGE, which is the whole reason it is
     * checked through OrderKey rather than by hasValidSignature(): a storefront
     * page exists once per locale, and a signature over the whole address dies
     * the moment the language switcher rewrites the path.
     */
    protected function authorizeCustomerView(Request $request, Order $order): void
    {
        $isOwnOrder = auth()->check() && $order->user_id === auth()->id();
        $justPlaced = $request->session()->get('last_order_number') === $order->order_number;

        abort_unless($isOwnOrder || $justPlaced || OrderKey::matches($request, $order, $this->keyRoute($request)), 404);
    }
}
