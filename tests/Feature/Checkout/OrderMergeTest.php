<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Fixtures\Payments\FakeGateway;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Models\OrderMerge;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\MergeOrders;
use Torgash\Checkout\Orders\OrderStatusChanger;
use Torgash\Checkout\Orders\OrderTotals;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Checkout\Orders\RecordPayment;
use Torgash\Checkout\Orders\Refund;
use Torgash\Payments\Models\Binding;
use Torgash\Payments\Models\Payment;

/**
 * "The customer ordered twice and wants one parcel" (PLAN §40.10/4): B's
 * lines join A with their discounts and their stock, the money paid for B is
 * A's, B stays a closed shell — and all of it can be undone until anything
 * happens to either order.
 */
class OrderMergeTest extends TestCase
{
    protected User $admin;

    protected User $customer;

    protected Product $sofa;

    protected Product $chair;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->admin = User::role('admin')->firstOrFail();
        $this->customer = User::factory()->create();
        $this->sofa = Product::create(['sku' => 'MG-SOFA', 'name' => ['en' => 'Sofa'], 'slug' => 'mg-sofa', 'price' => 800_00, 'stock' => 10, 'status' => 'active']);
        $this->chair = Product::create(['sku' => 'MG-CHAIR', 'name' => ['en' => 'Chair'], 'slug' => 'mg-chair', 'price' => 100_00, 'stock' => 10, 'status' => 'active']);
    }

    public function test_the_lines_and_the_money_of_b_become_as(): void
    {
        $a = $this->order([$this->line($this->sofa, 1)], ['shipping_cost' => 50_00]);
        $b = $this->order([$this->line($this->chair, 2), $this->line($this->sofa, 1)], ['shipping_cost' => 50_00]);
        app(RecordPayment::class)->record($b, PaymentState::PAID);

        app(MergeOrders::class)->merge($a, $b, $this->admin);

        $a->refresh();
        $b->refresh();

        // B's sofa line shares A's key, so it is renamed; the chair keeps its own.
        $keys = array_column($a->items, 'key');
        $this->assertCount(3, $keys);
        $this->assertCount(3, array_unique($keys));
        $this->assertSame([$b->order_number, $b->order_number], array_values(array_filter(array_column($a->items, 'merged_from'))));

        // One parcel: A's delivery stays, B's is dropped. B (1000 + 50
        // delivery) was paid in full; that money now counts towards A.
        $this->assertSame(1800_00, $a->subtotal);
        $this->assertSame(50_00, $a->shipping_cost);
        $this->assertSame(1850_00, $a->total);
        $this->assertSame(1050_00, $a->paid_total);
        $this->assertSame(PaymentState::PARTIALLY_PAID, $a->payment_state);

        $this->assertSame(OrderStatus::MERGED, $b->status);
        $this->assertSame($a->id, $b->merged_into_id);
        $this->assertSame([], $b->items);
        $this->assertSame(0, $b->total);
        $this->assertSame(0, $b->paid_total);
        $this->assertTrue(OrderLog::where('order_id', $a->id)->where('description', 'Order :number merged into this one')->exists());
    }

    public function test_the_deposit_example_leaves_the_rest_to_pay(): void
    {
        // The owner's case: A of 1000 with 20 % paid online, B of 500 unpaid.
        $method = $this->fakeGateway();
        $a = $this->order([$this->line($this->sofa, 1, 1000_00)], ['deposit_amount' => 200_00, 'payment_method_name' => $method->t('name')]);
        $b = $this->order([$this->line($this->chair, 5)], ['payment_method_name' => $method->t('name')]);
        $this->notify($a, ['status' => 'success', 'invoiceId' => 'inv-dep', 'amount' => 200_00, 'messageId' => 'd-1']);

        app(MergeOrders::class)->merge($a, $b, $this->admin);
        $a->refresh();

        $this->assertSame(1500_00, $a->total);
        $this->assertSame(200_00, $a->paid_total);
        $this->assertSame(PaymentState::PARTIALLY_PAID, $a->payment_state);
        $this->assertSame(1300_00, $a->chargeNow(), 'the Pay button asks for the rest');
    }

    public function test_bs_payment_stays_bs_at_the_bank_and_is_refunded_through_b(): void
    {
        $method = $this->fakeGateway();
        $a = $this->order([$this->line($this->sofa, 1)], ['payment_method_name' => $method->t('name')]);
        $b = $this->order([$this->line($this->chair, 1)], ['payment_method_name' => $method->t('name')]);
        $this->notify($b, ['status' => 'success', 'invoiceId' => 'inv-b', 'amount' => 100_00, 'messageId' => 'b-1']);

        app(MergeOrders::class)->merge($a, $b, $this->admin);
        $this->assertSame(100_00, $a->fresh()->paid_total);

        // A late message about B's invoice lands on A, where the money is.
        $this->notify($b, ['status' => 'success', 'invoiceId' => 'inv-b', 'amount' => 100_00, 'messageId' => 'b-2']);
        $this->assertSame(100_00, $a->fresh()->paid_total);
        $this->assertSame(0, $b->fresh()->paid_total);

        FakeGateway::$acted = [];
        app(Refund::class)->refund($a->fresh(), 100_00);

        $this->assertSame('inv-b', FakeGateway::$acted[0]['params']['external_id']);
        $this->assertSame(PaymentState::REFUNDED, Payment::where('external_id', 'inv-b')->value('state'));
        $this->assertSame(0, $a->fresh()->paid_total);
    }

    public function test_bs_coupon_travels_as_terms_on_its_own_lines(): void
    {
        $a = $this->order([$this->line($this->sofa, 1)], ['coupon_code' => 'SOFA5', 'coupon_terms' => ['type' => 'percent', 'value' => 5, 'min_subtotal' => null, 'excluded' => 0, 'lines' => [(string) $this->sofa->id => 1]]]);
        $b = $this->order([$this->line($this->chair, 2)], ['coupon_code' => 'CHAIR10', 'coupon_terms' => ['type' => 'percent', 'value' => 10, 'min_subtotal' => null, 'excluded' => 0, 'lines' => [(string) $this->chair->id => 2]]]);

        app(MergeOrders::class)->merge($a, $b, $this->admin);
        $a->refresh();

        $this->assertSame('SOFA5', $a->coupon_code);
        $this->assertSame(40_00 + 20_00, $a->discount_amount);
        $this->assertStringContainsString('CHAIR10', collect($a->discountLines())->pluck('label')->implode(' '));

        // One chair fewer: half of B's coupon goes with it.
        $items = $a->items;
        foreach ($items as $i => $item) {
            if ($item['product_id'] === $this->chair->id) {
                $items[$i]['qty'] = 1;
                $items[$i]['line_total'] = 100_00;
            }
        }
        app(OrderTotals::class)->recalculate($a, $items);
        $this->assertSame(40_00 + 10_00, $a->fresh()->discount_amount);
    }

    public function test_bs_stock_is_taken_once_when_only_a_held_it(): void
    {
        $a = $this->order([$this->line($this->sofa, 1)]);
        $b = $this->order([$this->line($this->chair, 3)], ['status' => OrderStatus::AWAITING_PAYMENT, 'stock_deducted' => false]);
        $before = $this->chair->fresh()->stock;

        $merge = app(MergeOrders::class)->merge($a, $b, $this->admin);
        $this->assertSame($before - 3, $this->chair->fresh()->stock, 'B joined an order that holds stock');

        app(MergeOrders::class)->undo($merge, $this->admin);
        $this->assertSame($before, $this->chair->fresh()->stock, 'and gave it back when split off');
    }

    public function test_undo_puts_both_orders_back_exactly(): void
    {
        $a = $this->order([$this->line($this->sofa, 1)], ['shipping_cost' => 50_00]);
        $b = $this->order([$this->line($this->sofa, 1), $this->line($this->chair, 1)], ['shipping_cost' => 30_00]);
        $aBefore = $a->fresh()->only(['items', 'subtotal', 'total', 'paid_total', 'status', 'shipping_cost']);
        $bBefore = $b->fresh()->only(['items', 'subtotal', 'total', 'paid_total', 'status', 'shipping_cost']);

        $merge = app(MergeOrders::class)->merge($a, $b, $this->admin);

        $this->actingAs($this->admin)
            ->post('http://localhost/admin/orders/'.$a->order_number.'/merge/'.$merge->id.'/undo')
            ->assertRedirect();

        $this->assertSame($aBefore, $a->fresh()->only(array_keys($aBefore)));
        $this->assertSame($bBefore, $b->fresh()->only(array_keys($bBefore)));
        $this->assertNull($b->fresh()->merged_into_id);
        $this->assertNotNull($merge->fresh()->undone_at);
    }

    public function test_anything_happening_after_the_merge_is_the_point_of_no_return(): void
    {
        $a = $this->order([$this->line($this->sofa, 1)]);
        $b = $this->order([$this->line($this->chair, 1)]);
        $merge = app(MergeOrders::class)->merge($a, $b, $this->admin);

        $this->assertSame([], app(MergeOrders::class)->undoRefusals($merge));

        app(OrderStatusChanger::class)->change($a->fresh(), OrderStatus::findOrFail('processing'));
        OrderLog::record($a, 'Status changed');

        $this->assertNotSame([], app(MergeOrders::class)->undoRefusals($merge));
        $this->expectException(RuntimeException::class);
        app(MergeOrders::class)->undo($merge);
    }

    public function test_a_merged_order_is_closed(): void
    {
        $a = $this->order([$this->line($this->sofa, 1)]);
        $b = $this->order([$this->line($this->chair, 1)]);
        app(MergeOrders::class)->merge($a, $b, $this->admin);
        $b->refresh();

        try {
            app(OrderStatusChanger::class)->change($b, OrderStatus::findOrFail('open'));
            $this->fail('a merged order moved to another status');
        } catch (RuntimeException) {
        }

        $this->actingAs($this->admin)
            ->patch('http://localhost/admin/orders/'.$b->order_number.'/status', ['status' => 'open'])
            ->assertStatus(422);

        try {
            $b->update(['shipping_cost' => 10_00]);
            $this->fail('a merged order was edited');
        } catch (RuntimeException) {
        }

        $this->assertSame(OrderStatus::MERGED, $b->fresh()->status);
        $this->assertNotContains(OrderStatus::MERGED, OrderStatus::choosable()->pluck('code')->all());
    }

    public function test_what_cannot_be_merged_says_why(): void
    {
        $merging = app(MergeOrders::class);
        $a = $this->order([$this->line($this->sofa, 1)]);

        $stranger = $this->order([$this->line($this->chair, 1)], ['user_id' => User::factory()->create()->id]);
        $this->assertContains(__('The orders belong to different customers.'), $merging->refusals($a, $stranger));

        $cancelled = $this->order([$this->line($this->chair, 1)], ['status' => 'cancelled', 'stock_deducted' => false]);
        $this->assertNotSame([], $merging->refusals($a, $cancelled));

        $this->assertNotSame([], $merging->refusals($a, $a));

        $b = $this->order([$this->line($this->chair, 1)]);
        $merging->merge($a, $b, $this->admin);
        $c = $this->order([$this->line($this->chair, 1)]);
        $this->assertNotSame([], $merging->refusals($c, $b->fresh()), 'B is already merged');
    }

    public function test_a_third_order_joins_the_first_but_the_first_goes_nowhere(): void
    {
        $merging = app(MergeOrders::class);
        $a = $this->order([$this->line($this->sofa, 1)]);
        $b = $this->order([$this->line($this->chair, 1)]);
        $c = $this->order([$this->line($this->chair, 2)]);
        $merging->merge($a, $b, $this->admin);

        // C into A, which already holds B: one level, allowed.
        $this->assertSame([], $merging->refusals($a->fresh(), $c));

        // A (holding B) into another order: a chain — refused, with the way
        // that works named.
        $other = $this->order([$this->line($this->sofa, 1)]);
        $why = $merging->refusals($other, $a->fresh());
        $this->assertContains(__('Other orders have been merged into :number — merge the other way round, into :number.', ['number' => $a->order_number]), $why);

        $merging->merge($a->fresh(), $c, $this->admin);
        $this->assertSame(800_00 + 100_00 + 200_00, $a->fresh()->subtotal);
        $this->assertCount(3, $a->fresh()->items);
    }

    public function test_a_return_stands_in_the_way_only_of_the_order_poured_in(): void
    {
        $merging = app(MergeOrders::class);
        $a = $this->order([$this->line($this->sofa, 1)]);
        $b = $this->order([$this->line($this->chair, 1)]);
        $enquiry = DB::table('m_enquiries_enquiries')->insertGetId(['kind' => 'return', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('m_rma_returns')->insert(['enquiry_id' => $enquiry, 'order_id' => $a->id, 'resolution' => 'refund', 'state' => 'requested', 'lines' => '[]', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame([], $merging->refusals($a, $b), 'a return on the order that receives is not in the way');
        $this->assertContains(__('Order :number has a return.', ['number' => $a->order_number]), $merging->refusals($b, $a));
    }

    public function test_bs_promotions_follow_its_lines(): void
    {
        $a = $this->order([$this->line($this->sofa, 1)]);
        $b = $this->order([$this->line($this->sofa, 1)]);
        DB::table('m_promotions_order_promotions')->insert([
            'order_id' => $b->id, 'promotion_id' => null, 'name' => 'Sale', 'amount' => -80_00,
            'target' => 'goods', 'shares' => json_encode([(string) $this->sofa->id => ['a' => 80_00, 'q' => 1]]),
        ]);
        app(OrderTotals::class)->recalculate($b->fresh());

        app(MergeOrders::class)->merge($a, $b, $this->admin);

        $row = DB::table('m_promotions_order_promotions')->where('order_id', $a->id)->first();
        $renamed = collect($a->fresh()->items)->firstWhere('merged_from', $b->order_number)['key'];
        $this->assertSame([$renamed], array_keys(json_decode($row->shares, true)));
        $this->assertSame(-80_00, (int) $row->amount);
        $this->assertSame(1600_00 - 80_00, $a->fresh()->total);
    }

    public function test_the_screen_previews_merges_and_undoes(): void
    {
        $a = $this->order([$this->line($this->sofa, 1)]);
        $b = $this->order([$this->line($this->chair, 1)]);

        // The menu item opens the window the page really has (the modal
        // component prefixes its id — a mismatch is a dead button, measured).
        $page = $this->actingAs($this->admin)->get('http://localhost/admin/orders/'.$a->order_number)->assertOk()->getContent();
        $this->assertStringContainsString('id="modal-order-merge"', $page);
        $this->assertStringContainsString("getElementById('modal-order-merge').showModal()", $page);

        $this->actingAs($this->admin)
            ->get('http://localhost/admin/orders/'.$a->order_number.'/merge?number='.$b->order_number)
            ->assertOk()
            ->assertSee(__('Merge :number into this order', ['number' => $b->order_number]));

        $this->post('http://localhost/admin/orders/'.$a->order_number.'/merge', ['from' => $b->order_number])
            ->assertRedirect('http://localhost/admin/orders/'.$a->order_number);
        $this->assertSame($a->id, $b->fresh()->merged_into_id);

        $this->get('http://localhost/admin/orders/'.$a->order_number)->assertOk()->assertSee(__('Undo the merge'));
        $this->get('http://localhost/admin/orders/'.$b->order_number)->assertOk()->assertSee($a->order_number);
    }

    // ------------------------------------------------------------ helpers

    protected function line(Product $product, int $qty, ?int $price = null): array
    {
        $price ??= (int) $product->price;

        return ['key' => (string) $product->id, 'product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->t('name'), 'qty' => $qty, 'unit_price' => $price, 'line_total' => $price * $qty];
    }

    protected function order(array $items, array $attributes = []): Order
    {
        $subtotal = array_sum(array_column($items, 'line_total'));
        $order = Order::create($attributes + [
            'order_number' => 'TG-MG'.random_int(10000, 99999),
            'user_id' => $this->customer->id,
            'customer_name' => 'Buyer', 'email' => 'buyer@example.test',
            'address_line' => '1 Street', 'city' => 'Town',
            'currency_code' => shop_currency(), 'status' => 'open', 'stock_deducted' => true,
            'subtotal' => $subtotal, 'shipping_cost' => 0, 'discount_amount' => 0, 'total' => $subtotal,
            'items' => $items,
        ]);

        return app(OrderTotals::class)->recalculate($order->fresh())->fresh();
    }

    protected function fakeGateway(): PaymentMethod
    {
        FakeGateway::reset();
        hook_listen('payments.gateways', fn (array $g) => $g + ['fake' => FakeGateway::class]);
        $method = PaymentMethod::where('enabled', true)->orderBy('id')->firstOrFail();
        Binding::create(['payment_method_id' => $method->id, 'gateway' => 'fake', 'config' => ['token' => 'sekret'], 'test_mode' => true]);

        return $method;
    }

    protected function notify(Order $order, array $body): void
    {
        $body['reference'] = $order->id;
        $json = json_encode($body);

        $this->call('POST', 'http://localhost/payment/fake/callback', [], [], [],
            ['HTTP_X-Signature' => hash_hmac('sha256', $json, 'sekret'), 'CONTENT_TYPE' => 'application/json'],
            $json,
        )->assertOk();
    }
}
