<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\Payments\FakeGateway;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Checkout\Orders\RecordPayment;
use Torgash\Payments\Models\Binding;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * "200 now, the rest to the courier" (PLAN §40.10/1): a payment method may take
 * only part of an order online. The bank is asked for that part alone, its
 * arrival sends the order to work as partly paid, and the rest is collected on
 * delivery.
 */
class DepositTest extends TestCase
{
    protected string $token = 'sekret';

    protected PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        FakeGateway::reset();
        hook_listen('payments.gateways', fn (array $g) => $g + ['fake' => FakeGateway::class]);

        Product::create(['sku' => 'DP-1', 'name' => ['en' => 'Sofa'], 'slug' => 'dp-sofa', 'price' => 1000_00, 'stock' => 3, 'status' => 'active']);

        $this->method = PaymentMethod::create([
            'name' => ['en' => 'Deposit online'], 'enabled' => true, 'position' => -1,
            'deposit_type' => 'fixed', 'deposit_value' => 200_00,
        ]);
        Binding::create(['payment_method_id' => $this->method->id, 'gateway' => 'fake', 'config' => ['token' => $this->token], 'test_mode' => true]);
    }

    public function test_the_bank_is_asked_for_the_deposit_alone(): void
    {
        $order = $this->place();

        $this->assertSame(200_00, $order->deposit_amount);
        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->status);
        $this->assertSame(200_00, FakeGateway::$started[0]['amount']);
        $this->assertSame(200_00, $order->chargeNow());
        $this->assertSame($order->total - 200_00, $order->payOnDelivery());
    }

    public function test_the_deposit_arriving_sends_the_order_to_work_as_partly_paid(): void
    {
        $order = $this->place();
        $this->pay($order, 200_00);
        $order->refresh();

        $this->assertSame(PaymentState::PARTIALLY_PAID, $order->payment_state);
        $this->assertSame(200_00, $order->paid_total);
        $this->assertSame(OrderStatus::default()->code, $order->status);
        $this->assertTrue($order->stock_deducted);
        $this->assertSame($order->total - 200_00, $order->payOnDelivery());
        $this->assertSame($order->total - 200_00, $order->balance());

        // Nothing more is asked online: the rest is the courier's.
        $this->get('http://localhost/checkout/'.$order->order_number.'/confirmation')->assertOk()->assertDontSee(__('This order is not paid yet.'));

        // The courier brought it: the manager marks it paid.
        app(RecordPayment::class)->record($order, PaymentState::PAID);
        $this->assertSame(PaymentState::PAID, $order->fresh()->payment_state);
        $this->assertSame(0, $order->fresh()->balance());
    }

    public function test_a_percent_deposit_and_an_order_smaller_than_the_deposit(): void
    {
        $this->method->update(['deposit_type' => 'percent', 'deposit_value' => 30]);
        $this->assertSame(300_00, $this->method->depositFor(1000_00));

        $this->method->update(['deposit_type' => 'fixed', 'deposit_value' => 5000_00]);
        $this->assertNull($this->method->depositFor(1000_00), 'a deposit bigger than the order is the order');
    }

    public function test_a_method_without_a_gateway_takes_no_deposit(): void
    {
        $cash = PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true, 'position' => 91, 'deposit_type' => 'fixed', 'deposit_value' => 100_00]);

        $order = $this->place($cash);

        $this->assertNull($order->deposit_amount);
    }

    public function test_the_split_is_printed_on_the_confirmation_and_in_the_summary(): void
    {
        $this->post('http://localhost/cart/add/dp-sofa', ['qty' => 1]);
        $this->get('http://localhost/checkout')->assertOk()->assertSee(__('To pay online now'))->assertSee(__('To pay on delivery'));

        $order = $this->place(addToCart: false);
        $this->get('http://localhost/checkout/'.$order->order_number.'/confirmation')->assertOk()
            ->assertSee(__('To pay online now'))->assertSee(__('To pay on delivery'));
    }

    public function test_the_admin_form_saves_a_deposit(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->put('http://localhost/admin/payments/methods/'.$this->method->id, [
            'name' => 'Deposit online', 'enabled' => '1', 'deposit_type' => 'percent', 'deposit_value' => '25',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['percent', 25], [$this->method->fresh()->deposit_type, $this->method->fresh()->deposit_value]);
        $this->actingAs($admin)->get('http://localhost/admin/payments/methods/'.$this->method->id.'/edit')->assertOk()->assertSee(__('Prepayment'));
    }

    protected function place(?PaymentMethod $method = null, bool $addToCart = true): Order
    {
        if ($addToCart) {
            $this->post('http://localhost/cart/add/dp-sofa', ['qty' => 1])->assertOk();
        }

        $this->post('http://localhost/checkout', [
            'customer_name' => 'Buyer', 'email' => 'buyer@example.test', 'address_line' => '1 Street', 'city' => 'Town',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => ($method ?? $this->method)->id,
        ])->assertSessionHasNoErrors();

        return Order::latest('id')->firstOrFail();
    }

    protected function pay(Order $order, int $amount): void
    {
        $json = json_encode(['status' => 'success', 'amount' => $amount, 'messageId' => 'm-dep', 'reference' => $order->id]);

        $this->call('POST', 'http://localhost/payment/fake/callback', [], [], [],
            ['HTTP_X-Signature' => hash_hmac('sha256', $json, $this->token), 'CONTENT_TYPE' => 'application/json'], $json)->assertOk();
    }
}
