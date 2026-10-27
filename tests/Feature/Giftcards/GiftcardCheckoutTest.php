<?php

namespace Tests\Feature\Giftcards;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Fixtures\Payments\FakeGateway;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Checkout\Orders\Refund;
use Torgash\Giftcards\Issuer;
use Torgash\Giftcards\Ledger;
use Torgash\Giftcards\Models\Card;
use Torgash\Payments\Models\Binding;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * A gift card is money paid in advance (PLAN В§40.4): it pays part or all of an
 * order beside the ordinary method, the bank is asked only for the rest, and
 * whatever goes back goes onto the card first.
 */
class GiftcardCheckoutTest extends TestCase
{
    protected string $token = 'sekret';

    protected Product $product;

    protected PaymentMethod $online;

    protected PaymentMethod $offline;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        FakeGateway::reset();
        RateLimiter::clear('giftcards:127.0.0.1');

        hook_listen('payments.gateways', fn (array $g) => $g + ['fake' => FakeGateway::class]);

        $this->product = Product::create([
            'sku' => 'GC-GOODS',
            'name' => ['en' => 'A kettle'],
            'slug' => 'a-kettle',
            'price' => 1000_00,
            'stock' => 10,
            'status' => 'active',
        ]);

        $this->online = PaymentMethod::create(['name' => ['en' => 'Card online'], 'enabled' => true, 'position' => 90]);
        $this->offline = PaymentMethod::create(['name' => ['en' => 'Cash to the courier'], 'enabled' => true, 'position' => 91]);

        Binding::create([
            'payment_method_id' => $this->online->id,
            'gateway' => 'fake',
            'config' => ['token' => $this->token],
            'test_mode' => true,
        ]);
    }

    public function test_a_code_is_found_in_any_spelling_and_the_balance_is_its_history(): void
    {
        [$card, $code] = app(Issuer::class)->issue(500_00);

        $this->assertSame(500_00, $card->balance);
        $this->assertSame(500_00, Ledger::sum($card->id));
        $this->assertSame($card->id, Card::findByCode(strtolower(implode(' - ', str_split($code, 4))))?->id);
        $this->assertNull(Card::findByCode('NOPE'));
    }

    public function test_a_card_in_the_coupon_box_pays_part_of_an_order_and_the_rest_is_owed(): void
    {
        [$card, $code] = app(Issuer::class)->issue(300_00);

        $this->addToCart();
        $this->post('http://localhost/cart/coupon', ['code' => $code])->assertOk();

        $order = $this->place($this->offline);

        $this->assertSame(300_00, $order->prepaid_total);
        $this->assertSame(300_00, $order->paid_total);
        $this->assertSame(PaymentState::PARTIALLY_PAID, $order->payment_state);
        $this->assertSame($order->total - 300_00, $order->gatewayDue());
        $this->assertSame(0, $card->fresh()->balance);
        $this->assertSame(OrderStatus::default()->code, $order->status);
        $this->assertSame([['label' => 'Gift card '.$card->masked(), 'amount' => 300_00]], $order->prepaidLines());
    }

    public function test_a_card_that_covers_everything_skips_the_bank_and_the_wait(): void
    {
        [$card, $code] = app(Issuer::class)->issue(5000_00);

        $this->addToCart();
        $this->post('http://localhost/cart/coupon', ['code' => $code]);

        $order = $this->place($this->online);

        $this->assertSame(PaymentState::PAID, $order->payment_state);
        $this->assertSame($order->total, $order->paid_total);
        $this->assertSame(OrderStatus::default()->code, $order->status);
        $this->assertTrue($order->stock_deducted);
        $this->assertSame([], FakeGateway::$started, 'a bank page for nothing would refuse the customer');
        $this->assertSame(5000_00 - $order->total, $card->fresh()->balance);
    }

    public function test_the_bank_is_asked_only_for_what_the_card_did_not_pay(): void
    {
        [, $code] = app(Issuer::class)->issue(400_00);

        $this->addToCart();
        $this->post('http://localhost/cart/coupon', ['code' => $code]);

        $order = $this->place($this->online);

        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->status);
        $this->assertSame($order->total - 400_00, FakeGateway::$started[0]['amount']);

        $this->pay($order, $order->total - 400_00);
        $order->refresh();

        $this->assertSame(PaymentState::PAID, $order->payment_state);
        $this->assertSame($order->total, $order->paid_total);
        $this->assertSame(400_00, $order->prepaid_total);
        $this->assertSame(0, $order->balance());
    }

    public function test_a_failed_bank_payment_leaves_the_card_money_on_the_order(): void
    {
        [, $code] = app(Issuer::class)->issue(400_00);

        $this->addToCart();
        $this->post('http://localhost/cart/coupon', ['code' => $code]);
        $order = $this->place($this->online);

        $this->pay($order, $order->total - 400_00, 'failure');
        $order->refresh();

        $this->assertSame(PaymentState::PARTIALLY_PAID, $order->payment_state);
        $this->assertSame(400_00, $order->paid_total);
    }

    public function test_the_customer_can_keep_part_of_a_card_for_next_time(): void
    {
        [$card, $code] = app(Issuer::class)->issue(800_00);

        $this->addToCart();
        $this->post('http://localhost/cart/coupon', ['code' => $code]);
        $this->post('http://localhost/cart/giftcards/'.$card->id.'/limit', ['limit' => '250', 'in' => 'checkout']);

        $order = $this->place($this->offline);

        $this->assertSame(250_00, $order->prepaid_total);
        $this->assertSame(550_00, $card->fresh()->balance);
    }

    public function test_a_refund_goes_back_onto_the_card_first(): void
    {
        [$card, $code] = app(Issuer::class)->issue(300_00);

        $this->addToCart();
        $this->post('http://localhost/cart/coupon', ['code' => $code]);
        $order = $this->place($this->offline);
        app(\Torgash\Checkout\Orders\RecordPayment::class)->record($order, PaymentState::PAID);
        $order->refresh();
        $this->assertSame($order->total, $order->paid_total);

        $result = app(Refund::class)->refund($order, 500_00);
        $order->refresh();

        $this->assertTrue($result->done);
        $this->assertSame(300_00, $card->fresh()->balance, 'the card first, up to what it paid');
        $this->assertSame(0, $order->prepaid_total);
        $this->assertSame($order->total - 500_00, $order->paid_total);
        $this->assertSame(PaymentState::PARTIALLY_REFUNDED, $order->payment_state);
    }

    public function test_a_cancelled_order_gives_the_card_its_money_back(): void
    {
        [$card, $code] = app(Issuer::class)->issue(300_00);

        $this->addToCart();
        $this->post('http://localhost/cart/coupon', ['code' => $code]);
        $order = $this->place($this->offline);

        app(\Torgash\Checkout\Orders\OrderStatusChanger::class)->change($order, OrderStatus::findOrFail('cancelled'));
        $order->refresh();

        $this->assertSame(300_00, $card->fresh()->balance);
        $this->assertSame(0, $order->prepaid_total);
        $this->assertSame(0, $order->paid_total);
    }

    public function test_waiting_for_stock_is_not_a_cancellation(): void
    {
        [$card, $code] = app(Issuer::class)->issue(300_00);

        $this->addToCart();
        $this->post('http://localhost/cart/coupon', ['code' => $code]);
        $order = $this->place($this->offline);

        // `backordered` returns stock too — and a paid order waiting for a
        // delivery must keep its payment.
        app(\Torgash\Checkout\Orders\OrderStatusChanger::class)->change($order, OrderStatus::findOrFail('backordered'));

        $this->assertSame(0, $card->fresh()->balance);
        $this->assertSame(300_00, $order->fresh()->prepaid_total);
    }

    public function test_a_card_for_one_category_pays_only_for_its_goods(): void
    {
        $tools = Category::create(['name' => ['en' => 'Tools'], 'slug' => 'gc-tools']);
        $drill = Product::create(['sku' => 'GC-DRILL', 'name' => ['en' => 'Drill'], 'slug' => 'gc-drill', 'price' => 200_00, 'stock' => 5, 'status' => 'active']);
        $drill->categories()->attach($tools->id);

        [, $code] = app(Issuer::class)->issue(5000_00, ['rules' => ['categories' => [$tools->id], 'shipping' => false]]);

        $this->addToCart();
        $this->post('http://localhost/cart/add/gc-drill', ['qty' => 1])->assertOk();
        $this->post('http://localhost/cart/coupon', ['code' => $code]);

        $order = $this->place($this->offline);

        $this->assertSame(200_00, $order->prepaid_total);
    }

    public function test_a_card_owned_by_another_account_is_refused(): void
    {
        $owner = \App\Models\User::factory()->create();
        [, $code] = app(Issuer::class)->issue(100_00, ['owner_user_id' => $owner->id]);

        $this->addToCart();
        $this->post('http://localhost/cart/coupon', ['code' => $code]);

        $this->assertSame([], app(\Torgash\Giftcards\Wallet::class)->applied(app(\Torgash\Cart\CartManager::class)));
    }

    public function test_a_printed_card_is_not_money_until_it_is_activated(): void
    {
        $cards = app(Issuer::class)->batch(1, 100_00, 'till');
        $code = $cards[0]->code();

        $this->addToCart();
        $this->post('http://localhost/cart/coupon', ['code' => $code]);
        $this->assertSame([], app(\Torgash\Giftcards\Wallet::class)->applied(app(\Torgash\Cart\CartManager::class)));

        $cards[0]->forceFill(['status' => Card::ACTIVE])->save();
        $this->post('http://localhost/cart/coupon', ['code' => $code]);
        $this->assertCount(1, app(\Torgash\Giftcards\Wallet::class)->applied(app(\Torgash\Cart\CartManager::class)));
    }

    public function test_guessing_codes_is_stopped_after_a_few_tries(): void
    {
        $this->addToCart();

        for ($i = 0; $i < \Torgash\Giftcards\Wallet::TRIES; $i++) {
            $this->post('http://localhost/cart/coupon', ['code' => 'AAAA-BBBB-CCCC-DDD'.chr(65 + ($i % 20 === 0 ? 7 : 2))]);
        }

        [, $code] = app(Issuer::class)->issue(100_00);
        $this->post('http://localhost/cart/coupon', ['code' => $code]);

        $this->assertSame([], app(\Torgash\Giftcards\Wallet::class)->applied(app(\Torgash\Cart\CartManager::class)), 'even a right code waits');
    }

    public function test_the_cards_invariants_hold_after_real_orders(): void
    {
        [, $code] = app(Issuer::class)->issue(300_00);

        $this->addToCart();
        $this->post('http://localhost/cart/coupon', ['code' => $code]);
        $this->place($this->offline);

        foreach ((new \Torgash\Giftcards\Invariants\GiftcardInvariants)->register() as $key => $invariant) {
            $this->assertSame([], ($invariant['check'])(100), $key);
        }
    }

    // ---------------------------------------------------------------- helpers

    protected function addToCart(): void
    {
        $this->post('http://localhost/cart/add/a-kettle', ['qty' => 1])->assertOk();
    }

    protected function place(PaymentMethod $method): Order
    {
        $this->post('http://localhost/checkout', [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => $method->id,
        ])->assertSessionHasNoErrors();

        return Order::latest('id')->firstOrFail();
    }

    protected function pay(Order $order, int $amount, string $status = 'success'): void
    {
        $body = ['status' => $status, 'amount' => $amount, 'messageId' => 'm-'.$status, 'reference' => $order->id];
        $json = json_encode($body);

        $this->call(
            'POST',
            'http://localhost/payment/fake/callback',
            [], [], [],
            ['HTTP_X-Signature' => hash_hmac('sha256', $json, $this->token), 'CONTENT_TYPE' => 'application/json'],
            $json,
        )->assertOk();
    }
}
