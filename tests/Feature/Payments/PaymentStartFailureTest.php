<?php

namespace Tests\Feature\Payments;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Fixtures\Payments\FakeGateway;
use Tests\TestCase;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Payments\Models\Binding;
use Torgash\Payments\Models\Transaction;
use Torgash\Payments\PaymentFailed;

/**
 * A payment that does not start has to SAY SO.
 *
 * Found by testing monobank against the live API: the bank refused the invoice
 * ("hold payments are allowed only for UAH" — the method had hold on and the
 * order was in dollars), `start()` returned null, and the controller redirected
 * back to the confirmation page with no message. From the customer's side the
 * Pay button did nothing at all, forever, and the only trace was a row in a log
 * they cannot see.
 *
 * So: the customer always learns that it did not start, the reason always
 * reaches the payment log, and the reason reaches the screen only for someone
 * who can act on it.
 */
class PaymentStartFailureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        FakeGateway::reset();

        hook_listen('payments.gateways', fn (array $g) => $g + ['fake' => FakeGateway::class]);

        Binding::create([
            'payment_method_id' => PaymentMethod::where('enabled', true)->orderBy('id')->firstOrFail()->id,
            'gateway' => 'fake',
            'config' => ['token' => 'sekret'],
            'test_mode' => true,
        ]);
    }

    public function test_a_refusal_reaches_the_customer_the_log_and_nothing_else(): void
    {
        FakeGateway::$failWith = 'hold payments are allowed only for UAH';
        $order = $this->order();

        $this->get(URL::signedRoute('payments.pay', $order->order_number))
            ->assertRedirect(route('checkout.confirmation', $order->order_number));

        // The shop owner's copy, in the log, with the gateway's own words.
        $logged = Transaction::where('order_id', $order->id)->latest('id')->firstOrFail();
        $this->assertStringContainsString('hold payments are allowed only for UAH', $logged->summary);
        $this->assertFalse($logged->applied);

        // Nothing moved: a refused start is not a payment state.
        $this->assertSame(PaymentState::PENDING, $order->refresh()->payment_state);
    }

    public function test_the_confirmation_page_says_it_did_not_start(): void
    {
        FakeGateway::$failWith = 'the bank is asleep';
        $order = $this->order();

        $this->followingRedirects()
            ->get(URL::signedRoute('payments.pay', $order->order_number))
            ->assertOk()
            ->assertSee('We could not start the payment.', false)
            // The Pay button stays: "try again" has to be possible.
            ->assertSee('This order is not paid yet.');
    }

    /**
     * A gateway's own words are about the shop's setup, not about the customer.
     * They are shown on a debug machine (this suite runs as one) and in the log;
     * a live shop shows the customer only the sentence above.
     */
    public function test_the_reason_is_shown_only_where_it_may_be(): void
    {
        FakeGateway::$failWith = 'hold payments are allowed only for UAH';
        $order = $this->order();

        config(['app.debug' => true]);
        $this->followingRedirects()
            ->get(URL::signedRoute('payments.pay', $order->order_number))
            ->assertSee('hold payments are allowed only for UAH');

        config(['app.debug' => false]);
        $this->followingRedirects()
            ->get(URL::signedRoute('payments.pay', $order->order_number))
            ->assertDontSee('hold payments are allowed only for UAH')
            ->assertSee('We could not start the payment.', false);
    }

    /** A gateway that hands back nothing and says nothing is still an answer. */
    public function test_a_silent_gateway_is_recorded_too(): void
    {
        FakeGateway::$pageUrl = null;
        $order = $this->order();

        $this->get(URL::signedRoute('payments.pay', $order->order_number))->assertRedirect();

        $this->assertStringContainsString(
            'Payment not started',
            Transaction::where('order_id', $order->id)->latest('id')->firstOrFail()->summary,
        );
    }

    /**
     * The pay link is a page a person is on, so it exists per locale. It used
     * to be registered bare only, and a Russian customer pressing Pay came back
     * to the English confirmation page — which is how the whole thing was
     * noticed.
     */
    public function test_paying_from_a_russian_page_comes_back_to_a_russian_page(): void
    {
        FakeGateway::$failWith = 'nope';
        $order = $this->order();

        // The /ru shape of the same signed link, as the picker on a Russian
        // page would generate it.
        $this->get(URL::signedRoute('ru.payments.pay', $order->order_number))
            ->assertRedirect(route('ru.checkout.confirmation', $order->order_number));
    }

    protected function order(): Order
    {
        return Order::create([
            'order_number' => 'TG-PF'.random_int(1000, 9999),
            'customer_name' => 'Buyer',
            'email' => 'buyer@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'currency_code' => 'USD',
            'subtotal' => 100_00,
            'shipping_cost' => 0,
            'total' => 100_00,
            'status' => 'new',
            'payment_method_name' => PaymentMethod::where('enabled', true)->orderBy('id')->firstOrFail()->t('name'),
            'payment_state' => PaymentState::PENDING,
            'items' => [],
        ]);
    }
}
