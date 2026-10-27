<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Orders\PaymentState;

/**
 * Where the money is, kept apart from where the order is.
 *
 * The whole point of the column is that the two axes move independently, so
 * that is what these check: a paid order can sit in any fulfilment status, the
 * list can be asked "what is unpaid" without knowing which hand-made statuses a
 * shop invented, and an amount that no longer matches the order's total is
 * visible rather than silently reconciled.
 */
class OrderPaymentStateTest extends TestCase
{
    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();

        $this->product = Product::create([
            'sku' => 'PAY-1',
            'name' => ['en' => 'Payable widget'],
            'slug' => 'payable-widget',
            'price' => 50_00,
            'stock' => 10,
            'status' => 'active',
        ]);
    }

    public function test_a_new_order_is_awaiting_payment(): void
    {
        $this->assertSame(PaymentState::PENDING, $this->order()->payment_state);
        $this->assertSame(0, $this->order()->paid_total);
    }

    public function test_an_order_can_be_marked_paid_by_hand_and_defaults_to_the_whole_total(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)
            ->patch($this->url($order), ['payment_state' => PaymentState::PAID, 'paid_total' => ''])
            ->assertOk();

        $order->refresh();

        $this->assertSame(PaymentState::PAID, $order->payment_state);
        $this->assertSame(100_00, $order->paid_total);
        $this->assertSame(0, $order->balance());
        $this->assertNotNull($order->paid_at);
        $this->assertTrue($order->logs()->where('description', 'like', 'Payment:%')->exists());
    }

    public function test_an_underpayment_is_recorded_as_what_arrived(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)
            ->patch($this->url($order), ['payment_state' => PaymentState::PAID, 'paid_total' => '60.00'])
            ->assertOk();

        $order->refresh();

        $this->assertSame(60_00, $order->paid_total);
        $this->assertSame(40_00, $order->balance());
    }

    public function test_a_state_that_holds_no_money_clears_the_amount_and_the_clock(): void
    {
        $order = $this->order(['payment_state' => PaymentState::PAID, 'paid_total' => 100_00, 'paid_at' => now()]);

        $this->actingAs($this->admin)
            ->patch($this->url($order), ['payment_state' => PaymentState::REFUNDED])
            ->assertOk();

        $order->refresh();

        $this->assertSame(0, $order->paid_total);
        $this->assertNull($order->paid_at);
    }

    public function test_editing_a_paid_order_leaves_the_payment_where_it_was(): void
    {
        $order = $this->order(['payment_state' => PaymentState::PAID, 'paid_total' => 100_00, 'paid_at' => now()]);

        // Dropping a line changes what the order costs. What was actually paid
        // is a fact about the past and must not be quietly rewritten to match.
        $this->actingAs($this->admin)
            ->patch('http://localhost/admin/orders/'.$order->order_number.'/items/'.$this->product->id, [
                'qty' => 1, 'unit_price' => '50.00',
            ])
            ->assertOk();

        $order->refresh();

        $this->assertSame(50_00, $order->total);
        $this->assertSame(100_00, $order->paid_total);
        $this->assertSame(-50_00, $order->balance(), 'the shop is now holding more than the order costs');
        $this->assertSame(PaymentState::PAID, $order->payment_state);
    }

    public function test_payment_and_fulfilment_are_independent_and_both_filter_the_list(): void
    {
        $shipped = OrderStatus::where('code', '!=', 'new')->value('code');

        $paidAndShipped = $this->order(['status' => $shipped, 'payment_state' => PaymentState::PAID, 'paid_total' => 100_00]);
        $unpaidAndShipped = $this->order(['status' => $shipped, 'email' => 'debtor@example.test']);
        $paidAndNew = $this->order(['payment_state' => PaymentState::PAID, 'paid_total' => 100_00, 'email' => 'prepaid@example.test']);

        // The combination no single-axis status can express.
        $this->assertFiltered(['status' => [$shipped], 'payment_state' => ['pending']], [$unpaidAndShipped]);
        $this->assertFiltered(['payment_state' => ['paid']], [$paidAndShipped, $paidAndNew]);
    }

    public function test_the_order_card_says_where_the_money_is_and_what_is_missing(): void
    {
        $order = $this->order(['payment_state' => PaymentState::PAID, 'paid_total' => 60_00, 'paid_at' => now()]);

        $this->actingAs($this->admin)
            ->get('http://localhost/admin/orders/'.$order->order_number)
            ->assertOk()
            ->assertSee(__('Payment state'))
            ->assertSee(__('Still owed'));
    }

    public function test_an_unknown_payment_state_is_refused(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)
            ->patch($this->url($order), ['payment_state' => 'nearly_paid'])
            ->assertSessionHasErrors('payment_state');

        $this->assertSame(PaymentState::PENDING, $order->fresh()->payment_state);
    }

    // ---------------------------------------------------------------- helpers

    /** @param  array<string, mixed>  $overrides */
    protected function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'TG-PAY'.random_int(1000, 9999),
            'customer_name' => 'Buyer',
            'email' => 'buyer@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'currency_code' => 'USD',
            'subtotal' => 100_00,
            'shipping_cost' => 0,
            'total' => 100_00,
            'status' => 'new',
            'stock_deducted' => false,
            'items' => [[
                'product_id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->t('name'),
                'qty' => 2,
                'unit_price' => 50_00,
                'line_total' => 100_00,
            ]],
        ], $overrides));
    }

    protected function url(Order $order): string
    {
        return 'http://localhost/admin/orders/'.$order->order_number.'/payment-state';
    }

    /** @param  list<Order>  $expected */
    protected function assertFiltered(array $query, array $expected): void
    {
        $response = $this->actingAs($this->admin)
            ->get('http://localhost/admin/orders?'.http_build_query($query))
            ->assertOk();

        $shown = collect($expected)->pluck('order_number');
        foreach (Order::pluck('order_number') as $number) {
            $shown->contains($number)
                ? $response->assertSee($number)
                : $response->assertDontSee($number);
        }
    }
}
