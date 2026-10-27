<?php

namespace Torgash\Payments;

use App\Core\Storefronts\StorefrontRoutes;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Payments\Admin\MethodGatewaySection;
use Torgash\Payments\Http\CallbackController;
use Torgash\Payments\Jobs\ReconcilePayments;
use Torgash\Payments\Models\Binding;
use Torgash\Payments\Models\Transaction;

class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Gateways::class);
        $this->app->singleton(PaymentApplier::class);
        $this->app->singleton(Ledger::class);
        $this->app->singleton(Api\OrderPayments::class);
        \App\Core\Support\PerRequest::forget($this->app, Api\OrderPayments::class);
    }

    public function boot(): void
    {
        $this->routes();
        $this->methodForm();
        $this->orderScreen();
        $this->refunds();
        $this->afterCheckout();
        $this->confirmationPage();
        $this->schedule();

        // Every gateway writes a row when it makes an invoice; that row is
        // where a payment begins (Ledger::started), so no gateway has to know
        // payments are units of their own.
        Transaction::created(fn (Transaction $transaction) => app(Ledger::class)->started($transaction));

        // A merged order's payments are the order it went into (Ledger::of
        // follows `merged_into_id`), so its state is read from all of them.
        hook_listen('checkout.order.merged', fn (array $context) => app(Ledger::class)->resettle($context['into']));

        // The order's payments in the API's answer: a page of orders is read
        // in one query when the API announces it, never a query per order.
        hook_listen('api.resource.listed', fn (array $context) => app(Api\OrderPayments::class)->prime(collect($context['items'] ?? [])));
        hook_listen('api.order.schema', function (array $schema) {
            $schema['payment']['of']['payments'] = ['type' => 'array', 'description' => 'Each payment at a gateway on its own — a deposit and its rest are two, and so are the payments of an order merged into this one (`order_id` then says which). `held` is what it holds now, a hold included; `requested` what it was made for. Minor units of `currency`.', 'of' => [
                'id' => 'integer', 'gateway' => 'string', 'external_id' => 'string|null', 'state' => 'string',
                'requested' => 'integer', 'held' => 'integer', 'order_id' => 'integer|null', 'created_at' => 'string|null',
            ]];

            return $schema;
        });

        hook_listen('api.order.serialize', function (array $data, array $context) {
            $order = $context['order'] ?? null;

            if ($order instanceof Order) {
                $data['payment']['payments'] = app(Api\OrderPayments::class)->for($order);
            }

            return $data;
        });

        // What must be true about taking money, for whoever is watching. The
        // filter is never fired when `torgash.guard` is absent, so this is a
        // statement about our own data rather than a dependency on that module.
        hook_listen('guard.invariants', fn (array $all) => $all
            + (new Invariants\PaymentInvariants)->register());

        Route::middleware(['web', 'auth', 'can:payments.manage'])
            ->prefix('admin/payments')
            ->name('admin.payments.')
            ->group(dirname(__DIR__).'/routes-admin.php');
    }

    /**
     * The callback is registered OUTSIDE `StorefrontRoutes::group()` and outside
     * the `web` group, deliberately: no locale prefixes (a gateway stored our
     * URL months ago and knows nothing about /ru/), no CSRF token (there is no
     * form and no browser), no session (a machine has no cookies). Adding `web`
     * here would reject every callback with a 419 that looks, from the gateway's
     * side, exactly like the shop being down.
     *
     * `pay` is the customer's own route and does live on the storefront.
     */
    protected function routes(): void
    {
        Route::post('/payment/{gateway}/callback', [CallbackController::class, 'handle'])->name('payments.callback');

        // Some gateways only ever GET. Same handler; the signature check is what
        // decides anything, not the verb.
        Route::get('/payment/{gateway}/callback', [CallbackController::class, 'handle']);

        // Signed, not session-guarded: the link has to survive the customer
        // closing the tab and opening the order email tomorrow, and an order
        // number is guessable (TG-000123). A signature does both — it works
        // without a session and cannot be typed by somebody who was not given
        // it.
        //
        // Through StorefrontRoutes, unlike the callback above: this one IS a
        // page a person is on, so it exists in every locale shape. Registered
        // bare it had only the default-locale shape, and a Russian customer
        // pressing Pay came back to the English confirmation page — and, when
        // the payment did not start, to an English error as well. The
        // signature is over the whole URL, so a link generated before this
        // (bare, in an already-sent email) keeps working: that shape is still
        // registered.
        StorefrontRoutes::group(function () {
            Route::middleware('signed')
                ->get('/payment/{order:order_number}/pay', [CallbackController::class, 'pay'])
                ->name('payments.pay');
        });
    }

    /**
     * The gateway lives inside checkout's payment-method form: one screen for
     * "set up payments", which is how a merchant thinks about it. The module
     * split stays in the code and stays out of the UI.
     */
    protected function methodForm(): void
    {
        hook_listen('checkout.payment_method.extra', fn (string $value, array $context) => $value.app(MethodGatewaySection::class)->render($context['method']));

        // The log belongs to the payment screen, not to the sidebar: a menu
        // entry of its own read as a second place to configure payments, which
        // is exactly the confusion the merged form was meant to end.
        hook_listen('checkout.payment_methods.actions', fn (string $value) => $value.view('torgash.payments::partials.log-link')->render());

        // Which gateway takes the money for each method on the list — one
        // query for the whole list, answered as id => html beside the name.
        hook_listen('checkout.payment_methods.notes', function (array $value, array $context) {
            $options = app(Gateways::class)->options();
            $bindings = Binding::whereIn('payment_method_id', collect($context['methods'])->pluck('id'))->get();

            foreach ($bindings as $binding) {
                $value[$binding->payment_method_id] = ($value[$binding->payment_method_id] ?? '').view('torgash.payments::partials.method-note', [
                    'gatewayLabel' => $options[$binding->gateway] ?? $binding->gateway,
                    'gatewayKnown' => isset($options[$binding->gateway]),
                    'testMode' => (bool) $binding->test_mode,
                ])->render();
            }

            return $value;
        });

        hook_listen('checkout.payment_method.saved', function (array $context) {
            app(MethodGatewaySection::class)->save($context['method'], $context['request']);
        });

        // "Does this method take money online?" — asked by checkout before it
        // creates an order, to decide whether the goods come out of the
        // warehouse now or when the money arrives. Only this module can answer
        // it: a method takes money online exactly when a gateway is wired to
        // it, and checkout knows nothing about gateways.
        hook_listen('checkout.payment_method.online', fn (bool $value, array $context) => $value
            || Binding::where('payment_method_id', $context['method']->id)->exists());
    }

    /**
     * The gateway's own buttons on the order screen, hung off the extension
     * point checkout provides beside the payment state — drawn from what the
     * gateway says it can do, so a Capture button never appears for a gateway
     * that cannot capture.
     */
    protected function orderScreen(): void
    {
        hook_listen('checkout.order.payment_extra', function (string $value, array $context) {
            /** @var Order $order */
            $order = $context['order'];
            $found = app(Gateways::class)->forOrder($order);

            if (! $found) {
                return $value;
            }

            // Each payment with its own buttons (PaymentActions::available):
            // a deposit and its rest are two invoices, and capturing,
            // releasing or refunding is always about one of them. A payment
            // nobody paid is shown only while it is the latest — a customer
            // who opened the bank's page three times left three of them.
            $payments = app(Ledger::class)->of($order);
            $latest = $payments->last();
            $payments = $payments->filter(fn ($payment) => ! $payment->isOpen() || $payment->is($latest) || $payment->amount > 0);
            $actions = app(PaymentActions::class);

            return $value.view('torgash.payments::partials.order-actions', [
                'order' => $order,
                'binding' => $found['binding'],
                'payments' => $payments->map(fn ($payment) => ['payment' => $payment, 'actions' => $actions->available($payment)])->values(),
                // Re-asking the bank is about the order as a whole when it
                // has no payment yet to ask about.
                'refresh' => $payments->isEmpty() && in_array('refresh', $found['gateway']->capabilities(), true),
                'transactions' => Transaction::where('order_id', $order->id)->latest('id')->limit(5)->get(),
            ])->render();
        });
    }

    /**
     * "Give this money back" — asked by checkout, answered here because only a
     * gateway module knows which of its calls does it.
     *
     * Checkout's `Refund` is where the act lives and where the manual fallback
     * is; what it cannot decide is whether the money should be TRANSFERRED or
     * simply LET GO. A hold has not been taken: the funds are ring-fenced on
     * the customer's card and releasing the authorization gives them back at no
     * cost to anybody, while sending a transfer for the same amount would move
     * real money out of the shop and leave the hold to expire in the customer's
     * favour — the shop pays twice. That is the owner's Monobank case, and the
     * only place that can tell the two apart is the gateway, because for some
     * acquirers it is the same endpoint and for others it is not.
     *
     * Answering with a summary means "handled": the applier has already moved
     * the payment state, and checkout must not also stamp it. Answering with
     * null means "not mine", and checkout records the refund by hand.
     */
    protected function refunds(): void
    {
        hook_listen('checkout.order.refund', function (?string $value, array $context) {
            if ($value !== null) {
                return $value;
            }

            /** @var Order $order */
            $order = $context['order'];
            $actions = app(PaymentActions::class);
            $left = (int) ($context['amount'] ?? 0);
            $said = [];

            // An order no payment is recorded for — its money was marked by
            // hand, or it was paid before payments were kept one by one — is
            // asked about as a whole, the way it always was.
            if (app(Ledger::class)->of($order)->isEmpty()) {
                return $this->refundWholeOrder($order, $context);
            }

            // Payment by payment (PLAN §40.10/4): holds first, released rather
            // than transferred — paying back money that never left the card
            // costs the shop twice — then what was taken, newest first. Each
            // is asked for no more than it holds. A gateway whose one call
            // does both simply does not declare `void` and gets `refund`.
            foreach (app(Ledger::class)->refundOrder($order) as $payment) {
                if ($left <= 0) {
                    break;
                }

                $available = $actions->available($payment);
                $capability = $payment->isHold() && in_array('void', $available, true) ? 'void' : 'refund';

                if (! in_array($capability, $available, true)) {
                    continue;
                }

                $part = min($left, $payment->held());
                // A whole payment is asked for as a whole — a release takes
                // no amount, and "all of it" is what a partial amount equal to
                // the payment would mean anyway.
                $event = $actions->act($payment, $capability, $part < $payment->held() ? $part : null, $context['source'] ?? null);

                if (! $event) {
                    break;
                }

                $left -= $part;
                $said[] = $event->summary ?: $event->state;
            }

            // Nothing reached a gateway: not ours, and checkout records it by
            // hand. Part of it did: what went back is already on the order,
            // and the rest is said, not invented.
            if ($said === []) {
                return null;
            }

            if ($left > 0) {
                $said[] = __(':amount could not be returned through the gateway — see the payment log.', [
                    'amount' => format_price($left, $order->currency_code),
                ]);
            }

            return implode(' ', $said);
        });
    }

    /** The order's gateway asked about the order itself (no payment rows). */
    protected function refundWholeOrder(Order $order, array $context): ?string
    {
        $found = app(Gateways::class)->forOrder($order);

        if (! $found) {
            return null;
        }

        $capabilities = $found['gateway']->capabilities();
        $capability = ($context['hold'] ?? false) && in_array('void', $capabilities, true) ? 'void' : 'refund';

        if (! in_array($capability, $capabilities, true)) {
            return null;
        }

        $event = $found['gateway']->act($capability, $order, $found['binding']->gatewayConfig(), [
            'amount' => $context['amount'] ?? null,
            'reason' => $context['source'] ?? null,
        ]);

        if (! $event) {
            return null;
        }

        app(PaymentApplier::class)->apply($found['binding']->gateway, $order, $event);

        return $event->summary ?: $event->state;
    }

    /**
     * An order paid online must not end at the confirmation page: the customer
     * has to be sent to the gateway. Checkout asks where to go through a filter,
     * so it needs to know nothing about payments — and a shop with this module
     * uninstalled keeps the old behaviour exactly.
     */
    protected function afterCheckout(): void
    {
        hook_listen('checkout.after_place_redirect', function (?string $url, array $context) {
            /** @var Order $order */
            $order = $context['order'];

            // Through the Starter, like the Pay link: a gateway that refuses
            // here used to throw straight out of checkout — a 500 on an order
            // that was already committed and paid for by nobody.
            return app(Starter::class)->start($order) ?? $url;
        });
    }

    /**
     * "Pay for it" on the confirmation page, while the order is still unpaid.
     * It re-starts the payment rather than reusing an old link, because the
     * invoice the customer walked away from has probably expired.
     */
    protected function confirmationPage(): void
    {
        hook_listen('checkout.confirmation.payment', function (string $value, array $context) {
            /** @var Order $order */
            $order = $context['order'];

            // PARTIALLY_PAID too: a gift card paid part of it and the bank's
            // part is still to come.
            $payable = in_array($order->payment_state, [PaymentState::PENDING, PaymentState::PARTIALLY_PAID], true)
                && $order->gatewayPaid() <= 0
                && $order->gatewayDue() > 0
                && app(Gateways::class)->forOrder($order) !== null;

            // A failed attempt still renders — otherwise the one case where the
            // gateway has gone missing would say nothing at all, which is the
            // silence this block exists to end.
            if (! $payable && ! session()->has('payment_error')) {
                return $value;
            }

            return $value.view('torgash.payments::partials.pay-button', [
                'order' => $order,
                'payable' => $payable,
            ])->render();
        });
    }

    /** Evidence has a shelf life, but a long one — see the module setting. */
    protected function schedule(): void
    {
        hook_listen('core.schedule', function (array $context) {
            /** @var Schedule $schedule */
            $schedule = $context['schedule'];

            $schedule->call(function () {
                $days = max(1, (int) setting('torgash.payments', 'keep_transactions_days'));

                Transaction::where('created_at', '<', now()->subDays($days))->delete();
            })->daily()->name('payments:prune-transactions')->withoutOverlapping();

            // A lost callback must not mean an order that stays unpaid for ever
            // while the money is in the account. startIfIdle() so a slow pass is
            // never stacked on top of itself; nothing pending, no task at all.
            $schedule->call(fn () => ReconcilePayments::anyPending() ? ReconcilePayments::startIfIdle() : null)
                ->everyFifteenMinutes()
                ->name('payments:reconcile')
                ->withoutOverlapping();
        });
    }
}
