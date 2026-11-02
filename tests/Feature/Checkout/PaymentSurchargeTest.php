<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\OrderTotals;
use Torgash\Checkout\Orders\PaymentSurcharge;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * A payment method's surcharge (PLAN §66): "a company pays by invoice, +10%",
 * a percent of the goods after every discount — with the delivery when the
 * method says so — plus a fixed amount, frozen onto the order as terms.
 */
class PaymentSurchargeTest extends TestCase
{
    protected PaymentMethod $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        Product::create(['sku' => 'PS-1', 'name' => ['en' => 'Desk'], 'slug' => 'ps-desk', 'price' => 1000_00, 'stock' => 5, 'status' => 'active']);

        $this->invoice = PaymentMethod::create([
            'name' => ['en' => 'Invoice for a company'], 'enabled' => true, 'position' => -1,
            'surcharge_percent' => 10_00, 'surcharge_base' => 'goods',
            'surcharge_title' => ['en' => 'Invoice fee'], 'surcharge_display' => 'percent',
        ]);
    }

    public function test_the_arithmetic(): void
    {
        $terms = ['percent' => 10_00, 'fixed' => 0, 'base' => 'goods', 'title' => null];

        $this->assertSame(100_00, PaymentSurcharge::amount($terms, 1000_00, 50_00));
        $this->assertSame(105_00, PaymentSurcharge::amount(['base' => 'goods_shipping'] + $terms, 1000_00, 50_00));
        $this->assertSame(125_00, PaymentSurcharge::amount(['fixed' => 25_00] + $terms, 1000_00, 50_00), 'a percent and an amount at once');
        $this->assertSame(250, PaymentSurcharge::amount(['percent' => 250] + $terms, 100_00, 0), '2.5%');

        // A promotion's cut is out of the base — of the goods, or of the
        // delivery when that is what it made free.
        $lines = [
            ['code' => 'promotion:1', 'amount' => -200_00, 'included' => false, 'target' => 'goods'],
            ['code' => 'promotion:2', 'amount' => -50_00, 'included' => false, 'target' => 'shipping'],
            ['code' => 'tax:vat', 'amount' => 30_00, 'included' => true],
        ];
        $this->assertSame(80_00, PaymentSurcharge::amount(['base' => 'goods_shipping'] + $terms, 1000_00, 50_00, $lines));

        // Nothing owed is nothing to add a fee to.
        $this->assertSame(0, PaymentSurcharge::amount(['fixed' => 25_00] + $terms, 0, 0));
        $this->assertNull(PaymentSurcharge::termsOf(PaymentMethod::make(['surcharge_percent' => 0, 'surcharge_fixed' => 0])));
    }

    public function test_an_order_carries_the_surcharge_and_its_terms(): void
    {
        $order = $this->place($this->invoice);

        $this->assertSame(100_00, $order->payment_surcharge);
        $this->assertEquals(['percent' => 10_00, 'fixed' => 0, 'base' => 'goods', 'title' => ['en' => 'Invoice fee'], 'shown' => true], $order->payment_surcharge_terms, 'jsonb keeps no key order');
        $this->assertSame($order->total, app(OrderTotals::class)->total($order), 'the stored total is the sum of its lines');

        $line = collect(hook_filter('checkout.order.extra_totals', [], ['order' => $order]))->firstWhere('code', PaymentSurcharge::CODE);
        $this->assertSame(['code' => PaymentSurcharge::CODE, 'label' => 'Invoice fee', 'amount' => 100_00, 'included' => false, 'hidden' => false], $line);

        // The same order paid another way costs a hundred less.
        $cash = PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true, 'position' => 90]);
        $plain = $this->place($cash);
        $this->assertSame(0, $plain->payment_surcharge);
        $this->assertNull($plain->payment_surcharge_terms);
        $this->assertSame($plain->total + 100_00, $order->total);
    }

    public function test_an_edit_recomputes_by_the_terms_the_order_was_placed_under(): void
    {
        $order = $this->place($this->invoice);

        // The method changes afterwards — a placed order does not notice.
        $this->invoice->update(['surcharge_percent' => 50_00]);

        $items = $order->items;
        $items[0]['qty'] = 2;
        $items[0]['line_total'] = 2000_00;
        app(OrderTotals::class)->recalculate($order, $items);

        $this->assertSame(200_00, $order->fresh()->payment_surcharge);
    }

    public function test_the_checkout_shows_the_rate_and_the_line(): void
    {
        $this->post('http://localhost/cart/add/ps-desk', ['qty' => 1])->assertOk();

        // The method ticked first is in the bill from the first drawing.
        $this->get('http://localhost/checkout')->assertOk()
            ->assertSee('Invoice fee')
            ->assertSee('+10%');

        $this->invoice->update(['surcharge_display' => 'amount']);
        $this->get('http://localhost/checkout')->assertOk()->assertSee('+'.format_price(100_00, exact: true));

        $this->invoice->update(['surcharge_display' => 'none']);
        $this->get('http://localhost/checkout')->assertOk()->assertDontSee('+10%');
    }

    public function test_a_cart_promotion_comes_first_and_the_surcharge_is_measured_after_it(): void
    {
        // 20% off the basket — and the surcharge is 10% of what is left.
        \Torgash\Promotions\Models\Promotion::create(['name' => ['en' => 'Twenty off'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true])
            ->tiers()->create(['position' => 0, 'conditions' => ['match' => 'all', 'rules' => []], 'discount_type' => 'percent', 'discount_value' => 20]);

        $order = $this->place($this->invoice);

        $this->assertSame(80_00, $order->payment_surcharge, '10% of 800, not of 1000');
        $this->assertSame($order->total, app(OrderTotals::class)->total($order));

        // ...and again after an edit: the promotion's cut is laid first.
        $items = $order->items;
        $items[0]['qty'] = 2;
        $items[0]['line_total'] = 2000_00;
        app(OrderTotals::class)->recalculate($order, $items);
        $order->refresh();
        $promotionCut = -(int) collect(hook_filter('checkout.order.extra_totals', [], ['order' => $order]))
            ->filter(fn ($line) => str_starts_with($line['code'], 'promotion:'))->sum('amount');
        $this->assertSame(round_money((2000_00 - $promotionCut) * 0.1), $order->payment_surcharge);
    }

    public function test_the_list_and_the_bill_are_told_apart(): void
    {
        // Quiet in the list, named in the bill.
        $this->invoice->update(['surcharge_display' => 'none', 'surcharge_in_totals' => 'line']);
        $this->post('http://localhost/cart/add/ps-desk', ['qty' => 1])->assertOk();
        $this->get('http://localhost/checkout')->assertOk()->assertDontSee('+10%')->assertSee('Invoice fee');

        // Named in the list, inside the total without a line.
        $this->invoice->update(['surcharge_display' => 'percent', 'surcharge_in_totals' => 'hidden']);
        $this->get('http://localhost/checkout')->assertOk()->assertSee('+10%')->assertDontSee('Invoice fee');

        $order = $this->place($this->invoice, addToCart: false);
        $this->assertSame(100_00, $order->payment_surcharge, 'hidden is still charged');
        $this->assertSame($order->total, app(OrderTotals::class)->total($order));
        $this->assertFalse($order->payment_surcharge_terms['shown']);

        $this->get('http://localhost/checkout/'.$order->order_number.'/confirmation')->assertOk()->assertDontSee('Invoice fee');

        // The staff always see it, and that the customer does not.
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get('http://localhost/admin/orders/'.$order->order_number)->assertOk()
            ->assertSee('Invoice fee')->assertSee(__('not shown to the customer'));
    }

    public function test_a_re_quote_draws_the_bill_and_the_amounts_beside_the_methods_again(): void
    {
        $this->invoice->update(['surcharge_display' => 'amount']);
        $this->post('http://localhost/cart/add/ps-desk', ['qty' => 2])->assertOk();
        $hint = '+'.format_price(200_00, exact: true);

        // With a delivery step: its re-quote carries them out of band.
        $this->post('http://localhost/checkout/quote', ['payment_method_id' => $this->invoice->id, 'city' => 'Town'])->assertOk()
            ->assertSee('id="checkout-surcharge-'.$this->invoice->id.'"', false)
            ->assertSee($hint)
            ->assertSee('Invoice fee');

        // Without one, the payment step asks for them itself.
        $this->post('http://localhost/checkout/summary', ['payment_method_id' => $this->invoice->id])->assertOk()
            ->assertSee('id="checkout-summary"', false)
            ->assertSee($hint)
            ->assertSee('Invoice fee');
    }

    public function test_the_admin_form_saves_it_and_a_changed_method_takes_its_own_terms(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->put('http://localhost/admin/payments/methods/'.$this->invoice->id, [
            'name' => 'Invoice for a company', 'enabled' => '1',
            'surcharge_percent' => '2.5', 'surcharge_fixed' => '15', 'surcharge_base' => 'goods_shipping',
            'surcharge_title' => 'Bank fee', 'surcharge_display' => 'amount',
        ])->assertSessionHasNoErrors();

        $fresh = $this->invoice->fresh();
        $this->assertSame([250, 15_00, 'goods_shipping', 'amount'], [$fresh->surcharge_percent, $fresh->surcharge_fixed, $fresh->surcharge_base, $fresh->surcharge_display]);
        $this->assertSame('Bank fee', $fresh->surcharge_title[content_base_locale()] ?? null);
        $this->actingAs($admin)->get('http://localhost/admin/payments/methods/'.$this->invoice->id.'/edit')->assertOk()->assertSee(__('Payment surcharge'));

        // An order placed with cash, moved to the invoice by the manager: the
        // invoice's terms, and the total with them.
        $cash = PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true, 'position' => 90]);
        $order = $this->place($cash);
        $total = $order->total;

        $this->actingAs($admin)->patch('http://localhost/admin/orders/'.$order->order_number.'/payment', ['payment_method_id' => $this->invoice->id])
            ->assertHeader('HX-Refresh', 'true');

        $order->refresh();
        $expected = PaymentSurcharge::amount(PaymentSurcharge::termsOf($fresh), 1000_00, (int) $order->shipping_cost);
        $this->assertSame($expected, $order->payment_surcharge);
        $this->assertSame($total + $expected, $order->total);

        // ...and back: no surcharge, the old total.
        $this->actingAs($admin)->patch('http://localhost/admin/orders/'.$order->order_number.'/payment', ['payment_method_id' => $cash->id]);
        $this->assertSame([0, null, $total], [$order->fresh()->payment_surcharge, $order->fresh()->payment_surcharge_terms, $order->fresh()->total]);
    }

    protected function place(PaymentMethod $method, bool $addToCart = true): Order
    {
        if ($addToCart) {
            $this->post('http://localhost/cart/add/ps-desk', ['qty' => 1])->assertOk();
        }

        $this->post('http://localhost/checkout', [
            'customer_name' => 'Buyer', 'email' => 'buyer@example.test', 'address_line' => '1 Street', 'city' => 'Town',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => $method->id,
        ])->assertSessionHasNoErrors();

        return Order::latest('id')->firstOrFail();
    }
}
