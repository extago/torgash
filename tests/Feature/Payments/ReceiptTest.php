<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Monobank\MonobankGateway;
use Torgash\Payments\Charge;
use Torgash\Payments\Models\Binding;
use Torgash\Payments\Receipt;

/**
 * The lines a bank is told (PLAN §66, stage 5): they add up to the charge to
 * the kopeck, whatever lies between the goods and it.
 */
class ReceiptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_the_surcharge_is_a_line_of_its_own_named_as_the_binding_says(): void
    {
        $order = $this->order([
            'payment_surcharge' => 10_00, 'total' => 110_00,
            'payment_surcharge_terms' => ['percent' => 10_00, 'fixed' => 0, 'base' => 'goods', 'title' => ['en' => 'Invoice fee'], 'shown' => true],
        ]);

        $lines = Receipt::lines($order, Charge::for($order));
        $this->assertSame([['Chair', 2, 50_00, 'goods'], ['Invoice fee', 1, 10_00, 'surcharge']], $this->brief($lines));

        $named = Receipt::lines($order, Charge::for($order), ['receipt_surcharge_name' => 'Bank fee']);
        $this->assertSame('Bank fee', $named[1]['name']);
    }

    public function test_spread_over_the_goods_it_is_split_where_it_does_not_divide(): void
    {
        $order = $this->order([
            'items' => [
                ['sku' => 'A', 'name' => 'A', 'qty' => 3, 'unit_price' => 20_00, 'line_total' => 60_00],
                ['sku' => 'B', 'name' => 'B', 'qty' => 2, 'unit_price' => 20_00, 'line_total' => 40_00],
            ],
            'payment_surcharge' => 10_01, 'total' => 110_01,
        ]);

        $lines = Receipt::lines($order, Charge::for($order), ['receipt_surcharge' => 'spread']);

        $this->assertSame(110_01, array_sum(array_column($lines, 'total')));
        $this->assertSame(['goods'], array_values(array_unique(array_column($lines, 'kind'))));
        foreach ($lines as $line) {
            $this->assertSame($line['price'] * $line['qty'], $line['total']);
        }
        $this->assertSame(5, array_sum(array_column($lines, 'qty')), 'every unit is still sold');
    }

    public function test_a_part_payment_or_a_switch_turned_off_sends_no_lines(): void
    {
        $order = $this->order(['deposit_amount' => 30_00]);
        $this->assertNull(Receipt::lines($order, Charge::for($order)));

        $whole = $this->order();
        $this->assertNull(Receipt::lines($whole, Charge::for($whole), ['receipt_items' => false]));
        $this->assertNotNull(Receipt::lines($whole, Charge::for($whole), []), 'on unless switched off');
    }

    public function test_a_delivery_paid_to_the_carrier_is_no_line(): void
    {
        $order = $this->order(['shipping_cost' => 0, 'shipping_to_carrier' => true, 'shipping_carrier_cost' => 80_00]);

        $this->assertSame(['goods'], array_column(Receipt::lines($order, Charge::for($order)), 'kind'));
    }

    public function test_the_share_always_adds_up(): void
    {
        $this->assertSame([34, 33, 33], Receipt::share(100, [1, 1, 1]));
        $this->assertSame([0, 0], Receipt::share(0, [5, 5]));
        $this->assertSame(1_000_001, array_sum(Receipt::share(1_000_001, [7, 13, 1, 999])));
    }

    public function test_the_method_form_offers_and_keeps_the_settings(): void
    {
        hook_listen('payments.gateways', fn (array $g) => $g + ['monobank' => MonobankGateway::class]);
        $method = PaymentMethod::create(['name' => ['en' => 'Card'], 'enabled' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('http://localhost/admin/payments/methods/'.$method->id.'/edit')->assertOk()
            ->assertSee(__('Payment surcharge in the bank\'s receipt'))->assertSee(__('Spread over the goods'));

        $this->actingAs($admin)->put('http://localhost/admin/payments/methods/'.$method->id, [
            'name' => 'Card', 'enabled' => '1', 'gateway' => 'monobank',
            'gateway_config' => ['monobank' => ['token' => 't', 'receipt_items' => '1', 'receipt_surcharge' => 'spread', 'receipt_surcharge_name' => '']],
        ])->assertSessionHasNoErrors();
        $config = Binding::where('payment_method_id', $method->id)->firstOrFail()->config;
        $this->assertSame([true, 'spread'], [$config['receipt_items'], $config['receipt_surcharge']]);

        $this->actingAs($admin)->put('http://localhost/admin/payments/methods/'.$method->id, [
            'name' => 'Card', 'enabled' => '1', 'gateway' => 'monobank',
            'gateway_config' => ['monobank' => ['receipt_surcharge' => 'nonsense']],
        ])->assertSessionHasNoErrors();
        $config = Binding::where('payment_method_id', $method->id)->firstOrFail()->config;
        $this->assertSame([false, 'line'], [$config['receipt_items'], $config['receipt_surcharge']], 'unticked is off; an unknown choice is the default');
    }

    /** @return list<array{0: string, 1: int, 2: int, 3: string}> */
    protected function brief(?array $lines): array
    {
        return array_map(fn ($l) => [$l['name'], $l['qty'], $l['price'], $l['kind']], (array) $lines);
    }

    protected function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'TG-RC'.random_int(1000, 9999),
            'customer_name' => 'Buyer', 'email' => 'rc@example.test', 'address_line' => '1 Street', 'city' => 'Town',
            'currency_code' => 'UAH', 'subtotal' => 100_00, 'shipping_cost' => 0, 'total' => 100_00,
            'status' => 'new', 'stock_deducted' => false,
            'items' => [['sku' => 'C', 'name' => 'Chair', 'qty' => 2, 'unit_price' => 50_00, 'line_total' => 100_00]],
        ], $overrides))->fresh();
    }
}
