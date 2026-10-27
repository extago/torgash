<?php

namespace Torgash\Payments;

use Illuminate\Http\Request;
use Torgash\Checkout\Models\Order;

/**
 * The vendor-specific half of taking money. Everything else — the route, the
 * deduplication, the ordering, the transaction log, the admin buttons — is this
 * module's, so a gateway module stays thin.
 *
 * Shaped against a real one (a monobank add-on for another engine, read in full), which
 * is why the parts look the way they do:
 *
 * - `start()` exists because an order is created BEFORE it is paid. The gateway
 *   makes an invoice and hands back a page to send the customer to; the order
 *   sits in `pending` meanwhile, and the customer may simply close the tab.
 * - `verify()` is separate from `parse()` and is given the raw request, because
 *   a signature is over the RAW BODY. Re-encoding the decoded JSON and signing
 *   that is what the reference add-on does, and any difference in escaping
 *   breaks it.
 * - `parse()` returns the order id from the SIGNED payload where the gateway
 *   provides one. The add-on trusts `order_id` out of the callback URL, so its
 *   signature protects the contents but not the target.
 * - `$config` is the binding's fields plus `test_mode` (Binding::gatewayConfig):
 *   a gateway with a real sandbox switch must honour it.
 * - `act()` covers what an admin does from the order screen. Which of them a
 *   gateway can do is `capabilities()`, and the screen draws exactly those —
 *   a hold that cannot be captured must not offer a Capture button.
 */
interface Gateway
{
    /** Stable key, stored on the binding and used in the callback URL. */
    public static function key(): string;

    /** English source label for the admin picker. */
    public static function label(): string;

    /**
     * Config fields for the binding form: `['name' => ['label' => …, 'type' =>
     * 'text|password|number|checkbox', 'help' => …]]`. Credentials go in as
     * `password` so they are not shoulder-read off the screen.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fields(): array;

    /**
     * The currencies this gateway can actually charge in, or null for "any".
     *
     * A shop's PRICES and its ACQUIRING are two different things: a Ukrainian
     * shop may well list in dollars and be paid in hryvnia, and a gateway that
     * cannot take the currency an order was placed in refuses it at the last
     * step — the one place a customer never comes back from. The module
     * compares this with the currencies the shop actually has enabled and says
     * so on the payment method's own screen, before any order exists.
     *
     * @return list<string>|null ISO codes, uppercase
     */
    public function currencies(): ?array;

    /**
     * What is wrong with this configuration, in the shop owner's words.
     *
     * For the traps only the gateway knows: monobank can hold the money, but
     * only in hryvnia, so "hold" plus a dollar price is a payment method that
     * will fail on every order and say nothing until one is placed. Returned
     * rather than thrown because nothing is wrong yet — this is the form
     * warning the admin while they can still change it.
     *
     * @param  array<string, mixed>  $config  what is currently saved (or typed)
     * @return list<string>
     */
    public function warnings(array $config): array;

    /**
     * Begin a payment. Return where to send the customer, or null when this
     * gateway takes no money online (a manual transfer, cash on delivery).
     *
     * The amount and its currency come as a `Charge`, not off the order: the
     * shop's books and the customer's currency are two different things (a
     * dollar-priced shop taking hryvnia is the ordinary case, not an exotic
     * one), and converting between them is the module's arithmetic, done once.
     * The gateway asks the bank for exactly what it is given.
     *
     * A REFUSAL is a `PaymentFailed` carrying the reason, never a null: the
     * module records it, tells the customer that it did not start and shows the
     * reason to whoever may see it. Returning null for "the bank said no" is
     * what made a refused invoice look like a button that does nothing.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws PaymentFailed when the gateway knows why it will not start
     */
    public function start(Order $order, Charge $charge, array $config): ?string;

    /** Is this callback really from the gateway? Read the RAW body. */
    public function verify(Request $request, array $config): bool;

    /** Translate a verified callback, or null when it says nothing we act on. */
    public function parse(Request $request, array $config): ?PaymentEvent;

    /**
     * What an admin may do from the order screen: any of `refresh`, `capture`,
     * `refund`, `cancel`, `void`.
     *
     * `void` is releasing an AUTHORIZATION without ever taking the money, and
     * it is optional because for many acquirers it is not a separate call —
     * monobank's `invoice/cancel` reverses a hold and refunds a payment through
     * the same endpoint, so it declares `refund` and nothing else. Declare
     * `void` only when your gateway needs a DIFFERENT call for it: checkout
     * asks for a refund saying whether the money is merely held, and a hold
     * must be let go rather than transferred back — paying a customer money
     * that never left their card costs the shop twice.
     *
     * `cancel` is a third thing again: killing an invoice nobody has paid.
     *
     * @return list<string>
     */
    public function capabilities(): array;

    /**
     * Do one of them. Returns the resulting event, so the same code path that
     * applies a callback applies this too.
     *
     * An order may have several payments (a deposit and its rest are two
     * invoices), so `$params` names the one meant: `external_id` — this
     * gateway's own id for it — and `held`, what it holds now in the shop's
     * books. Act on that id when it is given, and measure "is this refund
     * partial" against `held`, never against the order; `amount` absent means
     * the whole payment. The answer is applied to that payment whatever id is
     * written on it. With no `external_id` (an order nothing is recorded for
     * yet) find the payment the old way.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $params
     */
    public function act(string $capability, Order $order, array $config, array $params = []): ?PaymentEvent;
}
