<?php

namespace Tests\Feature\Checkout;

use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\Payments\FakeGateway;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Payments\Models\Binding;
use Torgash\Payments\Models\Transaction;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * An order nobody has paid yet does not hold the last unit of anything.
 *
 * Before this, stock came off the shelf the moment an order was placed, because
 * the shop's default status decreases inventory. With an online gateway that is
 * wrong in the ordinary case: the customer is sent to a bank page they may
 * simply close, and the abandoned order goes on holding goods until a human
 * notices and cancels it.
 *
 * The fix is the shape older engines use (
 * every order is created in a status that does NOT hold stock and is moved out
 * of it immediately — straight to Open for a method with no payment script,
 * and only on the gateway's word for one with). No reservation table and no
 * timeout: an unpaid order blocks nothing because it never took anything.
 *
 * The trade-off is the same coin, and it is deliberate: while the customer is
 * on the bank's page, somebody else may buy the last unit. That case has its
 * own test below — the money is kept and the order goes to Backordered, rather
 * than the warehouse quietly going negative.
 */
class UnpaidStockTest extends TestCase
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

        hook_listen('payments.gateways', fn (array $g) => $g + ['fake' => FakeGateway::class]);

        $this->product = Product::create([
            'sku' => 'STK-1',
            'name' => ['en' => 'Last one in stock'],
            'slug' => 'last-one-in-stock',
            'price' => 50_00,
            'stock' => 1,
            'status' => 'active',
        ]);

        $this->online = PaymentMethod::create([
            'name' => ['en' => 'Card online'],
            'enabled' => true,
            'position' => 90,
        ]);

        $this->offline = PaymentMethod::create([
            'name' => ['en' => 'Cash to the courier'],
            'enabled' => true,
            'position' => 91,
        ]);

        Binding::create([
            'payment_method_id' => $this->online->id,
            'gateway' => 'fake',
            'config' => ['token' => $this->token],
            'test_mode' => true,
        ]);
    }

    /** The whole point: an order waiting for money holds nothing. */
    public function test_an_order_paid_online_takes_no_stock_until_it_is_paid(): void
    {
        $order = $this->place($this->online);

        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->status);
        $this->assertFalse($order->stock_deducted);
        $this->assertSame(1, $this->product->fresh()->stock);
    }

    /** And a method that takes no money online behaves exactly as it always did. */
    public function test_an_order_paid_offline_takes_its_stock_at_once(): void
    {
        $order = $this->place($this->offline);

        $this->assertSame(OrderStatus::default()->code, $order->status);
        $this->assertTrue($order->stock_deducted);
        $this->assertSame(0, $this->product->fresh()->stock);
    }

    /** The money arriving is what makes the order real — and takes the goods. */
    public function test_the_payment_arriving_moves_the_order_on_and_takes_the_stock(): void
    {
        $order = $this->place($this->online);

        $this->pay($order);

        $order->refresh();

        $this->assertSame(PaymentState::PAID, $order->payment_state);
        $this->assertSame(OrderStatus::default()->code, $order->status);
        $this->assertTrue($order->stock_deducted);
        $this->assertSame(0, $this->product->fresh()->stock);
    }

    /** A redelivered callback is one payment, and therefore one deduction. */
    public function test_the_same_callback_twice_takes_the_stock_once(): void
    {
        $order = $this->place($this->online);

        $this->pay($order, 'm-dup');
        $this->pay($order, 'm-dup');

        $this->assertSame(0, $this->product->fresh()->stock);
        $this->assertSame(1, Transaction::where('applied', true)->count());
    }

    /**
     * Somebody else bought the last one while the customer was at the bank.
     *
     * The money is kept — refusing it is not ours to do from a callback — and
     * the order goes where a human will see it, rather than the warehouse going
     * to −1 in silence.
     */
    public function test_a_payment_for_goods_that_have_since_sold_out_goes_to_backordered(): void
    {
        $order = $this->place($this->online);

        // The offline customer who came second gets it, because they are the
        // one who actually completed a purchase.
        $this->place($this->offline);
        $this->assertSame(0, $this->product->fresh()->stock);

        $this->pay($order);

        $order->refresh();

        $this->assertSame(PaymentState::PAID, $order->payment_state);
        $this->assertSame('backordered', $order->status);
        $this->assertFalse($order->stock_deducted);
        $this->assertSame(0, $this->product->fresh()->stock);
    }

    /** A payment for an order somebody has already moved on does not drag it back. */
    public function test_a_payment_does_not_restate_a_status_a_human_has_set(): void
    {
        $order = $this->place($this->online);

        app(\Torgash\Checkout\Orders\OrderStatusChanger::class)
            ->change($order, OrderStatus::findOrFail('processing'));

        $this->pay($order);

        $order->refresh();

        $this->assertSame('processing', $order->status);
        $this->assertSame(PaymentState::PAID, $order->payment_state);
        // Taken once, by the status change — not again by the payment.
        $this->assertSame(0, $this->product->fresh()->stock);
    }

    // ---------------------------------------------------------------- helpers

    protected function place(PaymentMethod $method): Order
    {
        $this->post('http://localhost/cart/add/last-one-in-stock', ['qty' => 1])->assertOk();

        $this->post('http://localhost/checkout', [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => $method->id,
        ]);

        return Order::latest('id')->firstOrFail();
    }

    protected function pay(Order $order, string $messageId = 'm-1'): void
    {
        $body = ['status' => 'success', 'amount' => $order->total, 'messageId' => $messageId, 'reference' => $order->id];
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
