<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Payments\Charge;
use Torgash\Payments\Models\Binding;
use Torgash\Payments\Models\Transaction;
use Torgash\Payments\PaymentFailed;
use Torgash\Stripe\Client;
use Torgash\Stripe\StripeGateway;

/**
 * Stripe Checkout against the published contract. Every webhook here is
 * signed the way Stripe signs one — HMAC-SHA256 over "{t}.{raw body}" under
 * the endpoint's whsec_ secret — and posted through the real route.
 */
class StripeGatewayTest extends TestCase
{
    protected const SECRET_KEY = 'sk_test_123';

    protected const WEBHOOK_SECRET = 'whsec_test_secret';

    protected Product $product;

    protected PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        hook_listen('payments.gateways', fn (array $g) => $g + ['stripe' => StripeGateway::class]);

        $this->product = Product::create([
            'sku' => 'STR-1',
            'name' => ['en' => 'Stripe widget'],
            'slug' => 'stripe-widget',
            'price' => 50_00,
            'stock' => 10,
            'status' => 'active',
        ]);

        $this->method = PaymentMethod::where('enabled', true)->orderBy('id')->firstOrFail();

        Binding::create([
            'payment_method_id' => $this->method->id,
            'gateway' => 'stripe',
            'config' => ['secret_key' => self::SECRET_KEY, 'webhook_secret' => self::WEBHOOK_SECRET],
            'test_mode' => true,
        ]);
    }

    // ------------------------------------------------------------ the session

    public function test_a_checkout_session_names_the_order_in_both_metadata_and_pins_the_version(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])]);

        $order = $this->order();
        $url = app(StripeGateway::class)->start($order, Charge::for($order), $this->config(['hold' => true]));

        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $url);

        Http::assertSent(function ($request) use ($order) {
            $b = $request->data();

            return $request->hasHeader('Authorization', 'Bearer '.self::SECRET_KEY)
                && $request->hasHeader('Stripe-Version', Client::VERSION)
                && $b['mode'] === 'payment'
                && $b['metadata']['order_id'] === (string) $order->id
                // The payment has no id until the customer pays, so it gets
                // the order in its own metadata from the start.
                && $b['payment_intent_data']['metadata']['order_id'] === (string) $order->id
                && $b['payment_intent_data']['capture_method'] === 'manual'
                && $b['line_items'][0]['price_data']['currency'] === 'uah'
                && (int) $b['line_items'][0]['price_data']['unit_amount'] === 100_00;
        });

        $this->assertSame('cs_test_1', Transaction::latest('id')->value('external_id'));
    }

    /** Stripe bills yen in yen: 100_00 hundredths as JPY is a hundredfold charge. */
    public function test_a_currency_not_counted_in_hundredths_is_refused_before_asking(): void
    {
        Http::fake();

        try {
            app(StripeGateway::class)->start($this->order(), new Charge(100_00, 'JPY'), $this->config());
            $this->fail('a JPY charge must be refused');
        } catch (PaymentFailed $e) {
            $this->assertStringContainsString('JPY', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_the_keys_and_the_switch_are_checked_against_each_other(): void
    {
        $gateway = app(StripeGateway::class);

        $this->assertCount(1, $gateway->warnings(['secret_key' => 'sk_test_1', 'webhook_secret' => 'w', 'test_mode' => false]));
        $this->assertCount(1, $gateway->warnings(['secret_key' => 'sk_live_1', 'webhook_secret' => 'w', 'test_mode' => true]));
        $this->assertCount(1, $gateway->warnings(['secret_key' => 'sk_live_1', 'webhook_secret' => '', 'test_mode' => false]));
        $this->assertSame([], $gateway->warnings(['secret_key' => 'sk_live_1', 'webhook_secret' => 'w', 'test_mode' => false]));
    }

    // ----------------------------------------------------------- the webhook

    public function test_the_signature_is_checked_as_stripe_describes(): void
    {
        $payload = '{"id":"evt_1"}';
        $now = 1_700_000_000;
        $good = hash_hmac('sha256', $now.'.'.$payload, 'whsec_x');

        $this->assertTrue(Client::verifyWebhook($payload, "t={$now},v1={$good}", 'whsec_x', now: $now));
        // A rolled secret signs twice; either v1 will do.
        $this->assertTrue(Client::verifyWebhook($payload, "t={$now},v1=deadbeef,v1={$good}", 'whsec_x', now: $now));
        // v0 is the test scheme and must never be accepted — that is the downgrade.
        $this->assertFalse(Client::verifyWebhook($payload, "t={$now},v0={$good}", 'whsec_x', now: $now));
        // Six minutes later it is a replay.
        $this->assertFalse(Client::verifyWebhook($payload, "t={$now},v1={$good}", 'whsec_x', now: $now + 360));
        $this->assertFalse(Client::verifyWebhook($payload.' ', "t={$now},v1={$good}", 'whsec_x', now: $now));
    }

    public function test_a_completed_session_pays_the_order(): void
    {
        $order = $this->order();

        $this->webhook('checkout.session.completed', [
            'id' => 'cs_test_1', 'object' => 'checkout.session', 'payment_status' => 'paid',
            'amount_total' => 100_00, 'payment_intent' => 'pi_1', 'metadata' => ['order_id' => (string) $order->id],
        ])->assertOk();

        $order->refresh();
        $this->assertSame(PaymentState::PAID, $order->payment_state);
        $this->assertSame(100_00, $order->paid_total);
    }

    public function test_a_webhook_with_a_bad_signature_moves_nothing(): void
    {
        $order = $this->order();
        $body = json_encode($this->event('payment_intent.succeeded', $this->intent($order, 'succeeded')));

        $this->call('POST', 'http://localhost/payment/stripe/callback', [], [], [],
            ['HTTP_Stripe-Signature' => 't='.time().',v1=deadbeef', 'CONTENT_TYPE' => 'application/json'],
            $body,
        )->assertOk();

        $this->assertSame(PaymentState::PENDING, $order->fresh()->payment_state);
    }

    public function test_a_hold_is_authorized_and_a_declined_card_is_not_a_failed_order(): void
    {
        $held = $this->order();
        $this->webhook('payment_intent.amount_capturable_updated', $this->intent($held, 'requires_capture', ['amount_capturable' => 100_00]))->assertOk();
        $this->assertSame(PaymentState::AUTHORIZED, $held->fresh()->payment_state);

        // The customer is still on Stripe's page and may try another card.
        $declined = $this->order();
        $this->webhook('payment_intent.payment_failed', $this->intent($declined, 'requires_payment_method', [
            'last_payment_error' => ['message' => 'Your card was declined.'],
        ]))->assertOk();
        $this->assertSame(PaymentState::PENDING, $declined->fresh()->payment_state);
        $this->assertStringContainsString('declined', Transaction::latest('id')->value('summary'));
    }

    /** A charge carries no metadata; the order is found by the payment it names. */
    public function test_a_refund_arriving_as_a_charge_finds_its_order_and_is_partial(): void
    {
        $order = $this->order();
        $this->webhook('payment_intent.succeeded', $this->intent($order, 'succeeded', ['amount_received' => 100_00]))->assertOk();

        $this->webhook('charge.refunded', [
            'id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_'.$order->id,
            'amount' => 100_00, 'amount_refunded' => 25_00, 'metadata' => [],
        ])->assertOk();

        $order->refresh();
        $this->assertSame(PaymentState::PARTIALLY_REFUNDED, $order->payment_state);
        $this->assertSame(75_00, $order->paid_total);
    }

    public function test_the_same_event_delivered_twice_is_one_payment(): void
    {
        $order = $this->order();
        $intent = $this->intent($order, 'succeeded', ['amount_received' => 100_00]);

        $this->webhook('payment_intent.succeeded', $intent, 'evt_same')->assertOk();
        $this->webhook('payment_intent.succeeded', $intent, 'evt_same')->assertOk();

        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('applied', true)->count());
    }

    // ------------------------------------------------------------ the admin

    /** Releasing a hold is its own call — and lands as money back at zero, not as "expired". */
    public function test_voiding_a_hold_cancels_the_intent_and_leaves_the_order_refunded(): void
    {
        $order = $this->order(['payment_state' => PaymentState::AUTHORIZED, 'paid_total' => 100_00]);
        $this->known($order, 'pi_'.$order->id);

        Http::fake([
            '*/payment_intents/*/cancel' => Http::response(['id' => 'pi_'.$order->id, 'status' => 'canceled']),
            '*/payment_intents/*' => Http::response(['id' => 'pi_'.$order->id, 'status' => 'canceled', 'amount_received' => 0]),
        ]);

        $event = app(StripeGateway::class)->act('void', $order, $this->config());

        $this->assertSame(PaymentState::REFUNDED, $event->state);
        $this->assertSame(0, $event->amount);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/payment_intents/pi_'.$order->id.'/cancel'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/refunds'));
    }

    public function test_a_partial_refund_carries_an_idempotency_key_and_believes_stripe(): void
    {
        $order = $this->order(['payment_state' => PaymentState::PAID, 'paid_total' => 100_00]);
        $this->known($order, 'pi_'.$order->id);

        Http::fake([
            '*/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded']),
            '*/payment_intents/*' => Http::response([
                'id' => 'pi_'.$order->id, 'status' => 'succeeded', 'amount_received' => 100_00,
                'latest_charge' => ['amount_refunded' => 40_00],
            ]),
        ]);

        $event = app(StripeGateway::class)->act('refund', $order, $this->config(), ['amount' => 40_00]);

        $this->assertSame(PaymentState::PARTIALLY_REFUNDED, $event->state);
        $this->assertSame(60_00, $event->amount);
        Http::assertSent(fn ($request) => ! str_ends_with($request->url(), '/refunds')
            || ($request->hasHeader('Idempotency-Key') && (int) $request->data()['amount'] === 40_00));
    }

    public function test_a_refused_capture_is_logged_and_changes_nothing(): void
    {
        $order = $this->order(['payment_state' => PaymentState::AUTHORIZED, 'paid_total' => 100_00]);
        $this->known($order, 'pi_'.$order->id);

        Http::fake(['*' => Http::response(['error' => ['message' => 'This PaymentIntent could not be captured because it has expired.']], 400)]);

        $this->assertNull(app(StripeGateway::class)->act('capture', $order, $this->config()));
        $this->assertStringContainsString('has expired', Transaction::latest('id')->value('summary'));
    }

    /** Capturing and releasing are about a hold: on a paid order they are not offered. */
    public function test_the_hold_buttons_appear_only_on_a_held_order(): void
    {
        $admin = User::role('admin')->firstOrFail();

        $held = $this->order(['payment_state' => PaymentState::AUTHORIZED, 'paid_total' => 100_00]);
        $this->known($held, 'pi_'.$held->id);
        $this->actingAs($admin)->get('http://localhost/admin/orders/'.$held->order_number)
            ->assertOk()
            ->assertSee(__('Release the hold'))
            ->assertSee(route('admin.payments.act', [$held, 'capture']), false);

        $paid = $this->order(['payment_state' => PaymentState::PAID, 'paid_total' => 100_00]);
        $this->known($paid, 'pi_'.$paid->id);
        $this->actingAs($admin)->get('http://localhost/admin/orders/'.$paid->order_number)
            ->assertOk()
            ->assertDontSee(__('Release the hold'))
            ->assertDontSee(route('admin.payments.act', [$paid, 'capture']), false)
            ->assertSee(route('admin.payments.act', [$paid, 'refund']), false);
    }

    // -------------------------------------------------------------- helpers

    /** @param  array<string, mixed>  $extra */
    protected function config(array $extra = []): array
    {
        return $extra + ['secret_key' => self::SECRET_KEY, 'webhook_secret' => self::WEBHOOK_SECRET, 'test_mode' => true];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function intent(Order $order, string $status, array $extra = []): array
    {
        return $extra + [
            'id' => 'pi_'.$order->id,
            'object' => 'payment_intent',
            'status' => $status,
            'amount' => 100_00,
            'amount_received' => $status === 'succeeded' ? 100_00 : 0,
            'metadata' => ['order_id' => (string) $order->id],
        ];
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    protected function event(string $type, array $object, ?string $id = null): array
    {
        return ['id' => $id ?? 'evt_'.bin2hex(random_bytes(6)), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]];
    }

    /** @param  array<string, mixed>  $object */
    protected function webhook(string $type, array $object, ?string $id = null)
    {
        $body = json_encode($this->event($type, $object, $id));
        $t = time();

        return $this->call('POST', 'http://localhost/payment/stripe/callback', [], [], [],
            [
                'HTTP_Stripe-Signature' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$body, self::WEBHOOK_SECRET),
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        );
    }

    /** A payment a webhook has already told us about. */
    protected function known(Order $order, string $intent): void
    {
        Transaction::create([
            'gateway' => 'stripe', 'order_id' => $order->id, 'external_id' => $intent,
            'state' => $order->payment_state, 'amount' => $order->paid_total, 'verified' => true, 'applied' => true, 'created_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'TG-ST'.random_int(10000, 99999),
            'customer_name' => 'Buyer',
            'email' => 'buyer@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'currency_code' => 'UAH',
            'subtotal' => 100_00,
            'shipping_cost' => 0,
            'total' => 100_00,
            'status' => 'new',
            'payment_method_name' => $this->method->t('name'),
            'payment_method_id' => $this->method->id,
            'stock_deducted' => false,
            'items' => [[
                'product_id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => 'Stripe widget',
                'qty' => 2,
                'unit_price' => 50_00,
                'line_total' => 100_00,
            ]],
        ], $overrides))->fresh();
    }
}
