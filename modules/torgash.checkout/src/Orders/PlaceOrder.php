<?php

namespace Torgash\Checkout\Orders;

use App\Core\Support\Currencies;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Torgash\Cart\CartManager;
use Torgash\Checkout\CouponManager;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Quote;
use Torgash\Checkout\Totals;

/**
 * Turning a cart into an order — once, for every way of asking.
 *
 * This was the body of CheckoutController::store(), and it stayed there quite
 * happily while a form was the only way to place an order. It no longer is: the
 * shop API completes a cart too, and a second implementation would be a second
 * shop. Every rule below — which delivery options may be chosen, which payment
 * methods this currency allows, when the stock moves, which status a
 * gateway-backed order starts in, what is frozen onto the row — is one of the
 * answers this project has already argued out. None of them may exist twice.
 *
 * The split is: callers gather the answers (a form request, a JSON body) and
 * decide where the customer goes afterwards; this decides what an order IS.
 */
class PlaceOrder
{
    public function __construct(
        protected Totals $totals,
        protected OrderStock $stock,
        protected PaymentCurrency $paymentCurrency,
        protected CustomerRef $customerRef,
    ) {}

    /**
     * Whether this email belongs to an account and the one ordering is not
     * signed in to it, while the shop asks such a guest to sign in
     * (`existing_account`, PLAN §44.2/1). Here, not in the form, because the
     * shop API places orders through this class too — a guest order to
     * somebody else's address is the same mistake over JSON.
     */
    public function emailNeedsSignIn(string $email): bool
    {
        if (setting('torgash.checkout', 'existing_account', 'sign_in') !== 'sign_in' || trim($email) === '') {
            return false;
        }

        $user = auth()->user();

        if ($user && strcasecmp((string) $user->email, $email) === 0) {
            return false;
        }

        return \App\Models\User::whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->exists()
            && ! $user;
    }

    /**
     * What a placement must supply, given what is actually on offer.
     *
     * Shared so a JSON caller cannot order something the form would have
     * refused: a delivery option with no rate in the winning zone, a payment
     * method this currency cannot be charged in, an address for a delivery that
     * needs one.
     *
     * @return array<string, mixed>
     */
    public function rules(Quote $quote, ?string $shippingMethodId, bool $needsDelivery = true, ?string $currency = null): array
    {
        // An order that is collected has no street to be taken to. Asked of the
        // option actually chosen, never a shop-wide setting. An order of goods
        // that are not sent anywhere (PLAN §40.10/2) has neither a street nor a
        // town nor a delivery to choose.
        $needsAddress = $needsDelivery && ($quote->option($shippingMethodId)?->needs_address ?? true);
        $shippingActive = $needsDelivery && $this->shippingIsActive();
        $postal = (string) setting('torgash.checkout', 'postal_code', 'optional');

        return [
            // A company is asked only where the shop takes such orders; its
            // name is what makes the order a company's (see place()).
            ...($this->takesCompanies() ? [
                'buyer_type' => ['nullable', Rule::in(['person', 'company'])],
                'company_name' => 'nullable|required_if:buyer_type,company|string|max:190',
                'company_tax_id' => 'nullable|string|max:40',
            ] : []),
            // A parcel needs somebody to hand it to; an order taken by phone
            // needs only the number (see Order::customerLabel()).
            'customer_name' => ($needsAddress ? 'required' : 'nullable|required_without:phone').'|string|max:190',
            // Which contact an order must carry is the shop's (`order_contact`,
            // email by default): a shop taking orders by phone alone takes them
            // with a number and nothing else. A phone that must do the job is
            // one a person can ring — read by its country's rules.
            'email' => array_values(array_filter([
                $this->contactRequired('email') ? 'required' : 'nullable',
                $this->orderContact() === 'either' ? 'required_without:phone' : null,
                'email', 'max:190',
                function (string $attribute, $value, $fail) {
                    if ($this->emailNeedsSignIn((string) $value)) {
                        $fail(__('An account already uses this email. Sign in to order with it — we can send a code to it.'));
                    }
                },
            ])),
            'phone' => array_values(array_filter([
                $this->contactRequired('phone') ? 'required' : 'nullable',
                $this->orderContact() === 'either' ? 'required_without:email' : null,
                'string', 'max:40',
                $this->orderContact() === 'email' ? null : function (string $attribute, $value, $fail) {
                    if (filled($value) && ! \App\Core\Support\Phones::parse((string) $value, request()->input('country') ?: \App\Core\Support\Phones::defaultCountry())) {
                        $fail(__('This does not look like a phone number. Type it with the country code, like :example.', ['example' => \App\Core\Support\Phones::callingCode(\App\Core\Support\Phones::defaultCountry()).' …']));
                    }
                },
            ])),
            'address_line' => ($needsAddress ? 'required' : 'nullable').'|string|max:255',
            'city' => ($needsDelivery ? 'required' : 'nullable').'|string|max:120',
            'country' => 'nullable|string|size:2',
            'postal_code' => ($postal === 'required' && $needsDelivery ? 'required' : 'nullable').'|string|max:20',
            'notes' => 'nullable|string|max:1000',
            'shipping_method_id' => $shippingActive ? ['required', Rule::in($quote->offerIds())] : ['nullable'],
            'payment_method_id' => ['required', Rule::in($this->paymentMethods($currency)->modelKeys())],
        ];
    }

    /** What an order must carry to be placed: `email`, `phone`, `either` or `both`. */
    public function orderContact(): string
    {
        // Signed in, the account already says who is ordering — and it may be
        // an account without an email (PLAN §44, 3b): any one contact will do.
        if (auth()->check()) {
            return 'either';
        }

        $mode = (string) setting('torgash.checkout', 'order_contact', 'email');

        return in_array($mode, ['email', 'phone', 'either', 'both'], true) ? $mode : 'email';
    }

    public function contactRequired(string $kind): bool
    {
        $mode = $this->orderContact();

        return $mode === 'both' || $mode === $kind;
    }

    /** Whether the shop takes orders for a company (setting `business_orders`). */
    public function takesCompanies(): bool
    {
        return (bool) setting('torgash.checkout', 'business_orders', false);
    }

    /**
     * Is torgash.shipping here to say what delivery costs?
     *
     * Asked of the route table rather than of the module list: what actually
     * matters is whether anything answers the quote, and a disabled module has
     * registered no routes. One answer, because a caller getting it wrong would
     * either demand a delivery no one can offer or let an order be placed
     * without choosing one.
     */
    public function shippingIsActive(): bool
    {
        return Route::has('checkout.quote');
    }

    /**
     * The payment methods that can take money for an order in the currency this
     * visitor is shopping in — either directly, or by charging in one of their
     * own, which the checkout screen says out loud.
     *
     * @return Collection<int, PaymentMethod>
     */
    public function paymentMethods(?string $currency = null): Collection
    {
        // The currency the ORDER is quoted in: the visitor's own on the
        // storefront; the shop's books for a manager's order, whatever the
        // admin was last browsing in.
        $quoted = $currency ?? current_currency();
        $currencies = app(Currencies::class);

        return PaymentMethod::with('media')->where('enabled', true)->orderBy('position')->get()
            ->filter(function (PaymentMethod $method) use ($quoted, $currencies) {
                $charge = $method->chargeCurrencyFor($quoted);

                return $charge !== null
                    && ($charge === $quoted || (float) ($currencies->find($charge)?->rate ?? 0) > 0);
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $validated  the fields rules() asked for
     * @param  array<string, mixed>  $shippingFields  a provider option's extras
     * @param  array<string, mixed>  $as  who this order is FOR, when that is not
     *                                    whoever is making the request. Everything about identity is otherwise
     *                                    read from the ambient request — the logged-in user, the current
     *                                    storefront, the locale, the currency being browsed in — which is
     *                                    right for a customer placing their own order and wrong the moment
     *                                    somebody places one on their behalf: a manager creating the exchange
     *                                    order for a return would end up owning it, in the admin's storefront,
     *                                    priced in whatever currency the admin was last looking at. Keys:
     *                                    `user_id`, `storefront_id`, `locale`, `charge_currency` +
     *                                    `charge_rate`. Absent keys keep the ambient answer — except what
     *                                    is written down about the browser (ClientFacts), which is not
     *                                    taken at all once `user_id` names somebody else: that request is
     *                                    the manager's.
     */
    public function place(
        array $validated,
        CartManager $cart,
        CouponManager $coupons,
        Location $location,
        Quote $quote,
        array $shippingFields = [],
        bool $saveAddress = false,
        array $as = [],
    ): Order {
        // A basket filled before a product was closed to this customer's group
        // (PLAN §46) is not placed with it: the basket still holds it, the
        // order may not. A manager placing on somebody's behalf decides that.
        if ($as === []) {
            $assortment = app(\Torgash\Catalog\Support\Assortment::class);
            foreach ($cart->items() as $item) {
                if ($item->product && ! $assortment->productOpen($item->product)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'cart' => __(':product is not available to your account — take it out of the basket.', ['product' => $item->product->t('name')]),
                    ]);
                }
            }
        }

        $appliedCoupon = $coupons->current();
        $option = $quote->option($validated['shipping_method_id'] ?? null);
        $paymentMethod = PaymentMethod::findOrFail($validated['payment_method_id']);

        // A provider-backed option freezes its own snapshot onto the order — the
        // branch, the service type, the promised date — validating its extra
        // fields on the way. The shipping module owns that; with it absent, null.
        $shippingDetails = hook_filter('checkout.delivery.snapshot', null, [
            'option' => $option,
            'location' => $location,
            'fields' => $shippingFields,
        ]);

        // Ids of rows, not order columns: Order::create() below must see only
        // address and contact fields.
        unset($validated['shipping_method_id'], $validated['payment_method_id']);

        // A private person's order carries no company, whatever the hidden
        // fields of the other tab still held: company_name IS the answer to
        // "whose order is this", so a stale one would make it a company's.
        if (($validated['buyer_type'] ?? 'person') !== 'company') {
            unset($validated['company_name'], $validated['company_tax_id']);
        }
        unset($validated['buyer_type']);
        // A postal code the shop does not ask for is not written either.
        if (setting('torgash.checkout', 'postal_code', 'optional') === 'hidden') {
            unset($validated['postal_code']);
        }

        // One cart line can be several order lines: what is INSIDE a
        // configured product and is itself a product gets a line of its own,
        // because a warehouse, 1C and a return all deal in goods (PLAN §33.3).
        // A plain product is one line, exactly as before.
        $items = OrderLines::fromBasket($cart->items());
        // What the customer chose — measured against the shelf below. A line
        // a module appends (a promotion's free gift) is the shop's to give and
        // is not a reason to refuse the order.
        $basketLines = $items;

        // A module contributing lines that were never in the cart — a
        // promotion's free-gift product, chiefly. Filtered rather than
        // asked-and-trusted: an appended line still goes through the same
        // stock deduction as everything else below, so it has to look like
        // one (product_id + qty at minimum).
        $items = array_values(array_filter(
            hook_filter('checkout.order.items', $items, ['cart' => $cart, 'quote' => $quote, 'user' => auth()->user()]),
            fn ($item) => is_array($item) && isset($item['product_id'], $item['qty']),
        ));

        $shippingCost = $option->cost ?? 0;
        $extraTotal = $this->totals->added($this->totals->extra($cart, $quote, (string) $paymentMethod->id));
        $total = max(0, $quote->subtotal - $quote->discount) + $shippingCost + $extraTotal;

        // What will be paid before any gateway is asked — a gift card on the
        // basket (PLAN §40.4). Known before the status is chosen: an order a
        // card pays in full has nothing to wait for at a bank's page.
        $prepaid = $this->totals->prepaid($cart, $total, $shippingCost);
        $status = $this->placementStatus($paymentMethod, $total - $prepaid);
        // Part now, the rest on delivery — frozen here, and only for a method
        // that takes money online: a deposit is what a BANK is asked for.
        $deposit = $total - $prepaid > 0 && (bool) hook_filter('checkout.payment_method.online', false, ['method' => $paymentMethod])
            ? $paymentMethod->depositFor($total - $prepaid)
            : null;
        $deductsStock = $status->inventory_action === 'decrease';

        // The identity half, resolved once so the row below reads the same way
        // whoever is placing it.
        $userId = array_key_exists('user_id', $as) ? $as['user_id'] : auth()->id();
        $charge = array_key_exists('charge_currency', $as)
            ? array_filter([
                'charge_currency' => $as['charge_currency'],
                'charge_rate' => $as['charge_rate'] ?? null,
            ], fn ($v) => $v !== null)
            : $this->chargeCurrency();

        $order = DB::transaction(function () use (
            $validated, $quote, $shippingCost, $option, $shippingDetails, $paymentMethod,
            $items, $basketLines, $status, $deductsStock, $appliedCoupon, $total, $prepaid, $deposit, $location, $as, $userId, $charge, $coupons, $cart,
        ) {
            // The shelf, asked again with its rows locked: the basket may have
            // waited while the last unit went to somebody else. A manager
            // placing on somebody's behalf decides that, as with the assortment.
            if ($as === [] && ($refusal = $this->stock->refusal($basketLines))) {
                throw \Illuminate\Validation\ValidationException::withMessages(['cart' => $refusal]);
            }

            $order = Order::create($validated + [
                'storefront_id' => $as['storefront_id'] ?? current_storefront()?->id,
                // The settlement, not just its name: this is what lets the
                // order's delivery be re-quoted later (zones match on it, and so
                // does the carrier). Null with no locations registry.
                'place_id' => $location->placeId,
                'user_id' => $userId,
                // Who the buyer is when there is no account to point at. Frozen
                // onto the row rather than derived on the way out, so that
                // changing a phone number later does not rename a counterparty
                // some other system has already recorded.
                'customer_ref' => $this->customerRef->for($validated + ['user_id' => $userId]),
                // What the shop chose to know about the browser it was placed
                // from — the address above all, since "five orders, five names,
                // one address" cannot be asked later of a row that never wrote
                // it down. Nothing for an order placed on somebody's behalf.
                ...app(ClientFacts::class)->capture(array_key_exists('user_id', $as) ? null : request()),
                'currency_code' => shop_currency(),
                // What the customer was quoted in when that is not the shop's
                // own, at the rate they were quoted at. Frozen: an invoice
                // reprinted next month shows the number they agreed to.
                ...$charge,
                // ...and, when the method they picked cannot take that currency,
                // what the bank will actually be asked for.
                ...$this->paymentCurrency->columns(
                    $paymentMethod,
                    $charge['charge_currency'] ?? shop_currency(),
                ),
                'locale' => $as['locale'] ?? app()->getLocale(),
                'subtotal' => $quote->subtotal,
                'coupon_code' => $appliedCoupon?->code,
                'discount_amount' => $quote->discount,
                // After the coupon, from other modules (bonus points): frozen
                // as money — see the migration that added it.
                'other_discounts' => $coupons->otherDiscounts() ?: null,
                // The coupon's TERMS, so an edit of the order recomputes its
                // discount instead of keeping a sum for goods no longer there;
                // `excluded` is what the coupon did not reach in the basket
                // (a kit's goods) and stays out, and `lines` is what it covers,
                // so goods added to the order later are not. See OrderDiscounts.
                'coupon_terms' => $appliedCoupon
                    ? OrderDiscounts::termsOf($appliedCoupon, max(0, $quote->subtotal - $coupons->base()), $items)
                    : null,
                'shipping_cost' => $shippingCost,
                // The label carries the variant a provider resolved to ("Nova
                // Poshta — to branch"); null when there is no delivery at all.
                'shipping_method_name' => $option->label ?? null,
                'shipping_details' => $shippingDetails,
                // The name is the record, the id is the join — see the migration
                // that added the id.
                'payment_method_name' => $paymentMethod->t('name'),
                'payment_method_id' => $paymentMethod->id,
                'total' => $total,
                'deposit_amount' => $deposit,
                'status' => $status->code,
                'stock_deducted' => $deductsStock,
                'items' => $items,
            ]);

            if ($deductsStock) {
                // The same OrderStock the admin's status change and item editor
                // use: it splits the take across warehouses, records which one
                // gave up how much, and prefers one that could send it all.
                $order->update(['items' => $this->stock->deductAll($order->items, $order)]);
            }

            // Taken, not just counted: one conditional UPDATE, so two orders
            // racing for the last use of a coupon cannot both get it — the
            // second waits on the row, sees the limit reached, and its order
            // rolls back (audit 2026-10-15).
            if ($appliedCoupon) {
                $taken = $appliedCoupon->newQuery()->whereKey($appliedCoupon->getKey())
                    ->where(fn ($q) => $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'))
                    ->increment('used_count');

                if ($taken === 0) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'coupon' => __('The coupon :code has just been used up. Place the order without it or try another one.', ['code' => $appliedCoupon->code]),
                    ]);
                }
            }

            // Inside the transaction, for whoever must take something for this
            // order or refuse it — points spent from a balance that moved a
            // second ago. Throwing rolls the whole order back.
            hook_action('checkout.order.placing', ['order' => $order, 'cart' => $cart]);

            if ($prepaid > 0) {
                $this->prepay($order, $cart, $prepaid);
            }

            return $order;
        });

        // Its name: the next of the shop's sequence, in the shop's format
        // (OrderNumbers) — never the row's id.
        $order->update(['order_number' => app(OrderNumbers::class)->next()]);

        if ($saveAddress && auth()->check()) {
            $addresses = auth()->user()->addresses();
            $addresses->create([
                'full_name' => $validated['customer_name'],
                'phone' => $validated['phone'] ?? null,
                'address_line' => $validated['address_line'] ?? null,
                'city' => $validated['city'],
                'postal_code' => $validated['postal_code'] ?? null,
                'is_default' => $addresses->count() === 0,
            ]);
        }

        hook_action('checkout.order_placed', ['order' => $order]);

        $cart->clear();

        return $order;
    }

    /**
     * The status a new order starts in — and therefore whether it takes the
     * goods out of the warehouse straight away.
     *
     * A method that takes money online does not: the customer is about to be
     * sent to a bank page they may simply close (§53).
     */
    public function placementStatus(PaymentMethod $method, int $due = 1): OrderStatus
    {
        // Nothing left for the bank (a gift card paid it all) is nothing to wait for.
        $online = $due > 0 && (bool) hook_filter('checkout.payment_method.online', false, ['method' => $method]);

        return ($online ? OrderStatus::awaitingPayment() : null) ?? OrderStatus::default();
    }

    /**
     * Taking what was promised in advance — inside the order's transaction, so
     * a card is never spent on an order that did not happen and an order is
     * never placed on a card somebody else emptied a second ago.
     *
     * Whoever holds the money answers `checkout.order.prepay` with what it
     * actually took (`[{label, amount}]`), locking its own rows as it does. Less
     * than was shown in the summary is a refusal: the customer agreed to pay
     * the rest of a different number.
     */
    protected function prepay(Order $order, CartManager $cart, int $expected): void
    {
        $taken = array_values(array_filter(
            (array) hook_filter('checkout.order.prepay', [], ['order' => $order, 'cart' => $cart, 'amount' => $expected]),
            fn ($part) => is_array($part) && (int) ($part['amount'] ?? 0) > 0,
        ));
        $sum = array_sum(array_map(fn ($part) => (int) $part['amount'], $taken));

        if ($sum < $expected) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'prepaid' => __('The balance of your gift card has changed. Please check the order again.'),
            ]);
        }

        $order->update(Settlement::afterPrepaid($order, $sum));

        foreach ($taken as $part) {
            OrderLog::record($order, 'Paid in advance: :what (:amount)', [
                'what' => (string) ($part['label'] ?? ''),
                'amount' => format_price((int) $part['amount'], $order->currency_code),
            ], by: null);
        }
    }

    /**
     * The currency the customer is shopping in, when it is not the shop's own.
     *
     * @return array<string, mixed>
     */
    protected function chargeCurrency(): array
    {
        $chosen = current_currency();

        if ($chosen === shop_currency()) {
            return [];
        }

        $rate = app(Currencies::class)->find($chosen)?->rate;

        // A currency with no usable rate is not something to charge in: the
        // order stays in the shop's own, which is always safe to quote.
        return $rate > 0 ? ['charge_currency' => $chosen, 'charge_rate' => $rate] : [];
    }
}
