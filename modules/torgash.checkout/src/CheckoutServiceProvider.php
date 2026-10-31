<?php

namespace Torgash\Checkout;

use App\Core\Content\Placeholders;
use App\Core\Storefronts\StorefrontRoutes;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Orders\ClientFacts;
use Torgash\Checkout\Orders\OneClick;
use Torgash\Checkout\Orders\OrderNumbers;
use Torgash\Checkout\Orders\OrderChanges;
use Torgash\Checkout\Shipping\LocationStore;
use Torgash\Checkout\Support\Statuses;

class CheckoutServiceProvider extends ServiceProvider
{
    /**
     * Per-request, and deliberately so: the collector holds half-finished diffs,
     * which belong to the request that is making them and to nothing else.
     */
    public function register(): void
    {
        $this->app->singleton(OrderChanges::class);
        $this->app->singleton(\Torgash\Checkout\Orders\MergeOrders::class);
        $this->app->singleton(Support\OrderRows::class);
        \App\Core\Support\PerRequest::forget($this->app, Support\OrderRows::class);
        // The statuses a letter names, read again by every queue job.
        \App\Core\Support\PerRequest::flushPerJob($this->app, Statuses::class);

        // The status table, read once per request rather than once per order
        // row on a list. Per-request, like every other memo here: under a
        // worker a static would serve the next visitor a renamed status.
        $this->app->singleton(Statuses::class);
    }

    public function boot(): void
    {
        // Its part of the demo shop (PLAN §55.3), after the catalogue's products.
        hook_listen('core.demo.parts', fn (array $parts) => $parts + [
            'checkout' => ['label' => 'Coupons', 'after' => ['catalog'], 'install' => fn (\App\Core\Demo\DemoPack $pack) => app(Demo\CheckoutDemo::class)->install($pack)],
        ]);

        // What it keeps about a person, for core's personal-data registry (PLAN §49).
        hook_listen('core.personal_data', fn (array $context) => Orders\OrderPersonalData::register($context['registry']));

        // Who bought, for the newsletter's campaigns — asked only when that module is there.
        hook_listen('newsletter.segments', fn (array $kinds) => $kinds + Orders\NewsletterSegments::kinds());

        // The admin's bell: orders still in the status a new order is placed
        // in — nobody has picked them up yet.
        hook_listen('core.admin.notifications', function (array $rows, array $context) {
            if (! ($context['user'] ?? null)?->can('orders.manage')) {
                return $rows;
            }

            $status = \Torgash\Checkout\Models\OrderStatus::default();

            return [...$rows, [
                'key' => 'orders',
                'label' => __('New orders'),
                'count' => \Torgash\Checkout\Models\Order::where('status', $status->code)->count(),
                'url' => route('admin.orders.index', ['status' => [$status->code]]),
                'icon' => 'package',
            ]];
        });

        // A customer's orders on core's customer screens — core asks, this answers.
        app(Admin\CustomerOrders::class)->register();

        // Sales, the latest orders, statuses, best sellers — on core's dashboard.
        app(Admin\DashboardWidgets::class)->register();

        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        // What admin-written content on an order's own page may say about it —
        // the thank-you page's analytics snippet, above all. Registered here
        // because the data is this module's; resolvers are lazy, so a page that
        // uses none of them does no work.
        app(Content\OrderPlaceholders::class)->register(app(Placeholders::class));

        // The public forms this module owns. The checkout takes only what a
        // module adds — a CAPTCHA, when the shop ticks it — never core's
        // stamp, trap or rate limits (FormGuard::checkAdded() says why): a
        // basket is one request away for a bot too, and fake orders hold
        // stock and send letters to strangers.
        hook_listen('core.forms', fn (array $forms) => $forms + [
            Http\OneClickController::FORM => 'Buying in one click',
            Http\CheckoutController::FORM => 'Placing an order',
        ]);

        // The order's letters in core's registry (PLAN §41).
        // Fully qualified: `Mail` here is the Laravel facade's alias.
        hook_listen('core.mail.events', fn (array $events) => $events + \Torgash\Checkout\Mail\CheckoutLetters::events());

        // Signing in by a code from inside the checkout form (PLAN §44).
        hook_listen('core.verification.purposes', fn (array $all) => $all + [
            Http\CheckoutAccountController::PURPOSE => 'Your code to sign in and finish your order',
        ]);
        hook_listen('core.verification.purpose_names', fn (array $all) => $all + [
            Http\CheckoutAccountController::PURPOSE => 'Sign-in at checkout',
        ]);

        // A guest's order can be held until its phone (or, with no SMS, its
        // email) is confirmed by a code — the shop ticks it (core.verify_forms).
        hook_listen('core.verified_forms', fn (array $forms) => $forms + [
            'checkout' => ['label' => 'Checkout', 'kinds' => ['phone', 'email']],
            Http\OneClickController::FORM => ['label' => 'Buying in one click', 'kinds' => ['phone']],
        ]);

        // Checkout, an order's confirmation and its invoice are one customer's
        // business and several of them are behind a key in the address — an
        // indexed one is a private page in a public list.
        hook_listen('core.seo.private_routes', fn (array $names) => [...$names, 'checkout.*']);

        $this->announceOrderChanges();
        $this->oneClick();
        $this->scheduleUpkeep();

        // Asking about your own order, and reading the answer. A soft
        // dependency on torgash.enquiries in both directions — see the class.
        (new Enquiries\OrderEnquiries)->register();

        // Order logging is a listener on the module's own hooks, not code
        // baked into checkout/status-update paths — the usual pattern for an
        // optional order log.
        hook_listen('checkout.order_placed', function (array $context) {
            OrderLog::record($context['order'], 'Order placed', by: null);
        });

        // Feeds Label's "sales" rule/[sales] placeholder (a "Hit" badge) —
        // a plain running total, not order-status-aware (a cancelled order
        // still counted here; reconciling that is a real gap, just not one
        // a bestseller badge needs to be exact about).
        hook_listen('checkout.order_placed', function (array $context) {
            foreach ($context['order']->items ?? [] as $item) {
                if (! empty($item['product_id'])) {
                    Product::whereKey($item['product_id'])->increment('sales_count', (int) ($item['qty'] ?? 1));
                }
            }
        });

        hook_listen('checkout.order_status_changed', function (array $context) {
            // The statuses' own translatable names, so the line reads in the
            // language of whoever opens the order, not of whoever moved it.
            $to = $context['status']->name;
            $from = ($context['from_status'] ?? null)?->name ?? $context['from'] ?? null;

            $from !== null
                ? OrderLog::record($context['order'], ':from → :to', ['from' => $from, 'to' => $to])
                : OrderLog::record($context['order'], 'Status set to :status', ['status' => $to]);
        });

        // Independent third listener on the same hooks — proves the pattern
        // scales to multiple unrelated consumers (log + email) without any
        // of them knowing about each other. Mail::locale() sends in the
        // ORDER's own locale, not whichever locale the current request
        // happens to be in (matters for order_status_changed: the admin
        // changing the status may be browsing in a different language than
        // the customer who placed the order).
        //
        // Both mailables are ShouldQueue, so send() only pushes a job here; the
        // try/catch covers the push itself. Losing the queue must not undo an
        // order that is already committed, or leave an admin unable to change a
        // status — the customer missing an email is the smaller failure, and it
        // is written to the log and to failed_jobs rather than swallowed.
        // Every new order to the shop's own addresses (a director's inbox),
        // one click included — only when the shop wrote an address for it.
        hook_listen('checkout.order_placed', function (array $context) {
            try {
                \App\Core\Mail\Letters::toShop(\Torgash\Checkout\Mail\CheckoutLetters::NEW_ORDER, ['order' => $context['order']]);
            } catch (\Throwable $e) {
                Log::error('Could not queue the new order letter to the shop', ['order_id' => $context['order']->id, 'error' => $e->getMessage()]);
            }
        });

        hook_listen('checkout.order_placed', function (array $context) {
            $order = $context['order'];

            // An order bought in one click has a phone number and no address at
            // all. Nothing to send to is not a failure to report — it is the
            // shop ringing them back instead.
            if (! filled($order->email)) {
                return;
            }

            try {
                \App\Core\Mail\Letters::send(\Torgash\Checkout\Mail\CheckoutLetters::PLACED, $order->email, ['order' => $order], $order->locale ?? 'en');
            } catch (\Throwable $e) {
                Log::error('Could not queue the order confirmation email', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        hook_listen('checkout.order_status_changed', function (array $context) {
            $order = $context['order'];

            if (! filled($order->email)) {
                return;
            }

            try {
                // One letter per status, each with its own words and its own
                // on/off (CheckoutLetters) — a switched-off one is not sent.
                \App\Core\Mail\Letters::send(\Torgash\Checkout\Mail\CheckoutLetters::statusEvent($context['status']->code), $order->email,
                    ['order' => $order, 'status' => $context['status']], $order->locale ?? 'en');
            } catch (\Throwable $e) {
                Log::error('Could not queue the order status email', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        // Cart has zero knowledge of coupons — these two hooks are how
        // checkout extends the cart page without cart depending on it,
        // same idiom as catalog.product.buy_box.
        hook_listen('cart.rows_before_total', function (string $value, array $context) {
            return $value.view('torgash.checkout::partials.coupon-box', [
                'coupon' => app(CouponManager::class)->current(),
                'result' => session('coupon_result'),
            ])->render();
        });

        hook_listen('cart.total', function (int $total, array $context) {
            return max(0, $total - app(CouponManager::class)->discount());
        });

        // Where this visitor says they are. Kept here because the cart is where
        // the question is asked, but it is not a checkout fact: a catalogue
        // module deciding what to show a customer needs the same answer, and
        // asking it through a hook is what keeps it from reaching into this
        // module's session.
        hook_listen('storefront.visitor_place', function ($value) {
            return $value ?? app(LocationStore::class)->placeId();
        });

        // Pages kept ready for guests (PLAN §61): where a visitor said they
        // are is read in personal parts (the header's town) or under a vary
        // that answers for it (the tax rates there); the order they just
        // placed only on its own page. Orders and what hangs on them are on
        // no guest's page.
        page_cache()
            // Nothing reads the town before a page is drawn: a visitor who
            // named one still gives copies.
            ->personal(session: ['checkout_location'], copies: true)
            ->personal(session: ['last_order_number'])
            ->unseen(['m_checkout_orders', 'm_checkout_order_logs', 'm_checkout_order_merges', 'm_checkout_coupons']);

        // ...and the other direction: a visitor who names a town somewhere else
        // — by picking one of the shop's own points, say — has answered the
        // cart's question too. Only when it is still unanswered: overwriting a
        // town the customer typed would quote them delivery to somewhere they
        // never asked for. Unless this IS the customer naming it
        // (`explicit` — the "your town" window): then it simply is the answer.
        hook_listen('storefront.place_chosen', function (array $context) {
            $store = app(LocationStore::class);

            if (($store->placeId() === null || ! empty($context['explicit'])) && ($placeId = (int) ($context['place_id'] ?? 0))) {
                $store->rememberPlace($placeId);
            }
        });

        // The delivery estimate under the cart total moved to
        // Torgash\Shipping\ShippingServiceProvider when delivery became its own
        // module — a digital-goods shop uninstalls it and the estimate is gone.

        $this->registerEximHooks();

        // Answered only when the API module is installed and asks for it — the
        // class referenced inside lives there. Same soft dependency as exim.
        hook_listen('api.resources', fn () => (new Api\CheckoutResources)->register());

        // A page of the API is a page of orders: announced once, so a module
        // printing its own rows about each order reads them for the page in
        // one query (Support\OrderRows), not one per order.
        hook_listen('api.resource.listed', function (array $context) {
            $orders = collect($context['items'] ?? [])->filter(fn ($item) => $item instanceof Order);

            if ($orders->isNotEmpty()) {
                hook_action('checkout.orders.listed', ['orders' => $orders->values()]);
            }
        });
        hook_listen('checkout.order.recalculated', fn (array $context) => app(Support\OrderRows::class)->forget((int) $context['order']->id));

        // What must be true about an order, for whoever is watching. A filter
        // nobody fires when `torgash.guard` is not installed, so this is a
        // statement about our own data rather than a dependency on theirs.
        // An order's history is a log of its own; the guard's trail records the
        // order changing, not the history row that says so again.
        hook_listen('guard.audit.ignored_tables', fn (array $tables) => [...$tables, 'm_checkout_order_logs']);

        hook_listen('guard.invariants', fn (array $all) => $all
            + (new Invariants\CheckoutInvariants)->register());

        Route::middleware(['web', 'auth', 'can:orders.manage'])
            ->prefix('admin/orders')
            ->name('admin.orders.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        Route::middleware(['web', 'auth', 'can:orders.manage'])
            ->prefix('admin/checkout')
            ->name('admin.checkout.')
            ->group(dirname(__DIR__).'/routes-admin-checkout.php');

        // Payment methods answer to /admin/payments, not /admin/checkout: the
        // URL is read by a person, and "checkout" is our module name while
        // "payments" is what they came looking for. The payment log (a separate
        // module) lives beside them under the same address for the same reason
        // — one place, whichever module owns which half.
        Route::middleware(['web', 'auth', 'can:orders.manage'])
            ->prefix('admin/payments')
            ->name('admin.checkout.')
            ->group(dirname(__DIR__).'/routes-admin-payments.php');
    }

    /**
     * Wire the `checkout.order.changed` funnel (see OrderChanges for the why).
     *
     * Two flush points, because there are two ways a request-shaped unit of work
     * ends here: an HTTP request or an artisan command terminates, and a queue
     * worker does neither — it stays up for the next job, so a job that edits an
     * order (an import, a scheduled sync) would sit on its diff until the worker
     * was stopped.
     */
    /**
     * Periodic upkeep, added to the framework scheduler through `core.schedule`
     * (never by patching CoreServiceProvider) — same idiom as cart's abandoned
     * -cart prune and nova-poshta's directory sync.
     *
     * Hourly, not daily: the threshold beneath it is in HOURS, and a daily
     * sweep would turn "cancel after 2 h" into "cancel after up to 26 h".
     * Cheap — one indexed query and a handful of status changes.
     */
    protected function scheduleUpkeep(): void
    {
        hook_listen('core.schedule', function (array $context) {
            /** @var Schedule $schedule */
            $schedule = $context['schedule'];

            $schedule->call(fn () => app(Orders\CancelStaleOrders::class)->run())
                ->hourly()
                ->name('checkout:cancel-stale-orders')
                ->withoutOverlapping();
        });
    }

    /**
     * Buying with a phone number and nothing else.
     *
     * Three listeners and no new screen: the button hangs off the product page
     * through catalog's own hook, and WHICH delivery and payment such an order
     * is placed with is asked on those methods' own forms — where the person
     * setting them up is standing, and the same idiom a gateway and the 1C
     * service id already use.
     */
    protected function oneClick(): void
    {
        // Which statuses may show the button: the ones a product can be BOUGHT
        // in — offering a status that can never be bought would be a tick box
        // that switches nothing on. Filled in here because the manifest cannot
        // know the shop's own statuses.
        // "Next order number" moves the sequence (Orders\OrderNumbers) — once
        // the screen has checked it is above the last one issued, which a
        // change announced after the write could not refuse.
        hook_listen('core.settings.saving', function (array $errors, array $context) {
            if (($context['module'] ?? null) !== 'torgash.checkout' || ! array_key_exists('order_number_next', $context['values'] ?? [])) {
                return $errors;
            }

            $next = (int) $context['values']['order_number_next'];

            if ($next > 0 && $next !== (int) setting('torgash.checkout', 'order_number_next')
                && ($why = app(OrderNumbers::class)->refusal($next)) !== null) {
                $errors['order_number_next'] = $why;
            }

            return $errors;
        });

        hook_listen('core.setting.changed', function (array $context) {
            if (($context['module'] ?? null) === 'torgash.checkout' && ($context['key'] ?? null) === 'order_number_next' && (int) $context['to'] > 0) {
                app(OrderNumbers::class)->startFrom((int) $context['to']);
            }

            return $context;
        });

        hook_listen('core.settings.fields', function (array $fields, array $context) {
            if (($context['module'] ?? null) !== 'torgash.checkout') {
                return $fields;
            }

            return array_map(function (array $field) {
                // What the numbers look like now, and where the count stands.
                if (in_array($field['key'] ?? null, ['order_number_prefix', 'order_number_next'], true)) {
                    $numbers = app(OrderNumbers::class);
                    $last = $numbers->last();
                    $field['help'] = __($field['help']).' '.($last === null
                        ? __('No order has been numbered yet.')
                        : __('The last number issued: :number.', ['number' => $numbers->format($last)]));
                }

                if (($field['key'] ?? null) === 'client_facts') {
                    $field['options'] = collect(app(ClientFacts::class)->all())
                        ->map(fn (array $fact) => $fact['label'])
                        ->all();
                }

                if (($field['key'] ?? null) === 'one_click_statuses') {
                    $statuses = app(\Torgash\Catalog\Stock\AvailabilityStatuses::class);
                    $field['options'] = collect($statuses->all())
                        ->filter(fn (array $status) => $status['orderable'])
                        ->map(fn (array $status, string $key) => $statuses->label($key))
                        ->all();
                }

                return $field;
            }, $fields);
        });

        hook_listen('cart.buy_box.actions', function (string $value, array $context) {
            $oneClick = app(OneClick::class);

            // Resolved inside the listener, never captured at boot: a listener
            // outlives the request under a persistent worker, and a captured
            // service reading settings is a static wearing a different hat.
            if (! $oneClick->isAvailable() || ! $oneClick->offersFor($context['product'])) {
                return $value;
            }

            return $value.view('torgash.checkout::partials.one-click', [
                'product' => $context['product'],
                // The buy box's form: one click orders what was chosen
                // there — the options, the quantity — not the bare product.
                'oneClickBuyForm' => $context['form'] ?? null,
            ])->render();
        });

        // The same, for the whole basket, under the cart page's checkout button.
        hook_listen('cart.summary_actions', function (string $value, array $context) {
            $oneClick = app(OneClick::class);
            $cart = $context['cart'];

            if (! $oneClick->isAvailableForCart() || ! $oneClick->offersForCart($cart)) {
                return $value;
            }

            return $value.view('torgash.checkout::partials.one-click-cart', [
                'oneClickQty' => (int) $cart->items()->sum('qty'),
                'oneClickTotal' => (int) hook_filter('cart.total', $cart->total(), ['cart' => $cart]),
            ])->render();
        });

        hook_listen('checkout.payment_method.extra', function (string $value, array $context) {
            return $value.view('torgash.checkout::partials.method-one-click', [
                'oneClickField' => 'one_click_payment',
                'oneClickChosen' => app(OneClick::class)->paymentMethod()?->id === ($context['method']->id ?? null),
            ])->render();
        });

        hook_listen('checkout.payment_method.saved', function (array $context) {
            app(OneClick::class)->nominatePayment(
                (int) $context['method']->id,
                $context['request']->boolean('one_click_payment'),
            );
        });

        // The delivery half of the same question, on the shipping module's form
        // through the hooks it exposes — its table is not ours to add a column
        // to, so the nomination is our setting, keyed by its method id.
        hook_listen('shipping.method.extra', function (string $value, array $context) {
            return $value.view('torgash.checkout::partials.method-one-click', [
                'oneClickField' => 'one_click_shipping',
                'oneClickChosen' => app(OneClick::class)->shippingMethodId() === (string) ($context['method']->id ?? ''),
            ])->render();
        });

        // ...and the same nomination named on the delivery list.
        hook_listen('shipping.methods.notes', function (array $notes) {
            $id = app(OneClick::class)->shippingMethodId();

            if ($id !== null && $id !== '') {
                $notes[$id] = ($notes[$id] ?? '').'<span class="badge badge-sm badge-primary whitespace-nowrap">'.e(__('one click')).'</span>';
            }

            return $notes;
        });

        hook_listen('shipping.method.saved', function (array $context) {
            app(OneClick::class)->nominateShipping(
                (string) $context['method']->id,
                $context['request']->boolean('one_click_shipping'),
            );
        });
    }

    protected function announceOrderChanges(): void
    {
        Order::updated(fn (Order $order) => $this->app->make(OrderChanges::class)->record($order));
        // A merged order is closed to every writer but the merge itself (PLAN §40.10/4).
        Order::updating(fn (Order $order) => $this->app->make(\Torgash\Checkout\Orders\MergeOrders::class)->guard($order));

        $this->app->terminating(fn () => $this->app->make(OrderChanges::class)->flush());

        Event::listen(
            [JobProcessed::class, JobFailed::class],
            fn () => $this->app->make(OrderChanges::class)->flush(),
        );
    }

    /**
     * torgash.exim never appears in this module's manifest requires — same
     * as catalog's own registerEximHooks(), harmless no-op if exim isn't
     * installed. `items` (line items) is export-only for now: reconstructing
     * an order's cart-like state, stock impact and totals from an imported
     * row is a materially bigger feature than round-tripping scalar fields,
     * deliberately not attempted here.
     */
    protected function registerEximHooks(): void
    {
        hook_listen('exim.entities', fn (array $v) => $v + ['order' => 'Order']);

        hook_listen('exim.query.order', fn () => Order::query());

        hook_listen('exim.match.order', function ($v, array $ctx) {
            // The accounting system's own number first: `order_number` is ours,
            // printed on the invoice, and must not be the key somebody else's
            // database points at. Same order as the catalogue's matching.
            if ($externalId = $ctx['row']['external_id'] ?? null) {
                $linked = Order::where('external_id', $externalId)->first();

                if ($linked) {
                    return ['model' => $linked, 'matchedBy' => 'external_id'];
                }
            }

            $orderNumber = $ctx['row']['order_number'] ?? null;
            if (! $orderNumber) {
                return ['model' => new Order, 'matchedBy' => null];
            }

            $existing = Order::where('order_number', $orderNumber)->first();

            return ['model' => $existing ?? new Order, 'matchedBy' => $existing ? 'order_number' : null];
        });

        hook_listen('exim.schema.order', fn (array $fields) => [...$fields,
            ['key' => 'order_number', 'label' => 'Order number', 'get' => fn (Order $o) => $o->order_number, 'set' => fn (Order $o, $v) => $o->order_number = $v],
            ['key' => 'external_id', 'label' => 'External ID', 'get' => fn (Order $o) => $o->external_id, 'set' => fn (Order $o, $v) => $o->external_id = ($v ?: null)],
            ['key' => 'status', 'label' => 'Status', 'get' => fn (Order $o) => $o->status, 'set' => fn (Order $o, $v) => $o->status = $v],
            ['key' => 'payment_state', 'label' => 'Payment state', 'get' => fn (Order $o) => $o->payment_state, 'set' => fn (Order $o, $v) => $o->payment_state = $v],
            ['key' => 'paid_total', 'label' => 'Paid', 'get' => fn (Order $o) => $o->paid_total, 'set' => fn (Order $o, $v) => $o->paid_total = (int) $v],
            ['key' => 'customer_name', 'label' => 'Customer name', 'get' => fn (Order $o) => $o->customer_name, 'set' => fn (Order $o, $v) => $o->customer_name = $v],
            ['key' => 'company_name', 'label' => 'Company name', 'get' => fn (Order $o) => $o->company_name, 'set' => fn (Order $o, $v) => $o->company_name = ($v ?: null)],
            ['key' => 'company_tax_id', 'label' => 'Tax code', 'get' => fn (Order $o) => $o->company_tax_id, 'set' => fn (Order $o, $v) => $o->company_tax_id = ($v ?: null)],
            ['key' => 'email', 'label' => 'Email', 'get' => fn (Order $o) => $o->email, 'set' => fn (Order $o, $v) => $o->email = $v],
            ['key' => 'phone', 'label' => 'Phone', 'get' => fn (Order $o) => $o->phone, 'set' => fn (Order $o, $v) => $o->phone = $v],
            ['key' => 'address_line', 'label' => 'Address', 'get' => fn (Order $o) => $o->address_line, 'set' => fn (Order $o, $v) => $o->address_line = $v],
            ['key' => 'city', 'label' => 'City', 'get' => fn (Order $o) => $o->city, 'set' => fn (Order $o, $v) => $o->city = $v],
            ['key' => 'country', 'label' => 'Country', 'get' => fn (Order $o) => $o->country, 'set' => fn (Order $o, $v) => $o->country = $v],
            ['key' => 'postal_code', 'label' => 'Postal code', 'get' => fn (Order $o) => $o->postal_code, 'set' => fn (Order $o, $v) => $o->postal_code = $v],
            ['key' => 'notes', 'label' => 'Notes', 'get' => fn (Order $o) => $o->notes, 'set' => fn (Order $o, $v) => $o->notes = $v],
            ['key' => 'currency_code', 'label' => 'Currency', 'get' => fn (Order $o) => $o->currency_code, 'set' => fn (Order $o, $v) => $o->currency_code = $v],
            ['key' => 'coupon_code', 'label' => 'Coupon code', 'get' => fn (Order $o) => $o->coupon_code, 'set' => fn (Order $o, $v) => $o->coupon_code = $v],
            // Raw minor units, same convention as Product's own base_price
            // field — exact round-trip, no float-formatting surprises.
            ['key' => 'subtotal', 'label' => 'Subtotal', 'get' => fn (Order $o) => $o->subtotal, 'set' => fn (Order $o, $v) => $o->subtotal = (int) $v],
            ['key' => 'discount_amount', 'label' => 'Discount', 'get' => fn (Order $o) => (int) $o->discount_amount, 'set' => fn (Order $o, $v) => $o->discount_amount = (int) $v],
            ['key' => 'shipping_cost', 'label' => 'Shipping', 'get' => fn (Order $o) => $o->shipping_cost, 'set' => fn (Order $o, $v) => $o->shipping_cost = (int) $v],
            ['key' => 'total', 'label' => 'Total', 'get' => fn (Order $o) => $o->total, 'set' => fn (Order $o, $v) => $o->total = (int) $v],
            ['key' => 'placed_at', 'label' => 'Placed at', 'get' => fn (Order $o) => $o->created_at?->toDateTimeString(), 'set' => null],
            [
                'key' => 'items', 'label' => 'Items', 'set' => null,
                'get' => fn (Order $o) => collect($o->items ?? [])
                    ->map(fn ($i) => ($i['sku'] ?? '').':'.($i['qty'] ?? 0).':'.($i['unit_price'] ?? 0))
                    ->implode(';'),
            ],
        ]);
    }
}
