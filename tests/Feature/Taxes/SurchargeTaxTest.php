<?php

namespace Tests\Feature\Taxes;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\OrderTotals;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Taxes\Models\Tax;
use Torgash\Taxes\TaxCalculator;
use Torgash\Taxes\TaxResolver;

/**
 * A payment method's surcharge carries the taxes named on the method (PLAN
 * §66) — charged on the surcharge alone and
 * folded into the same tax's line, in the basket and in the order's snapshot.
 */
class SurchargeTaxTest extends TestCase
{
    protected PaymentMethod $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        Product::create(['sku' => 'ST-1', 'name' => ['en' => 'Chair'], 'slug' => 'st-chair', 'price' => 1000_00, 'stock' => 5, 'status' => 'active']);

        $this->invoice = PaymentMethod::create([
            'name' => ['en' => 'Invoice'], 'enabled' => true, 'position' => -1,
            'surcharge_percent' => 10_00, 'surcharge_title' => ['en' => 'Invoice fee'],
        ]);
    }

    public function test_an_added_tax_is_charged_on_the_surcharge_too(): void
    {
        $tax = $this->tax('st-sales', 10, includes: false);
        $this->taxMethod($tax);

        $order = $this->place();

        // 1000 of goods + 100 of tax, 100 of surcharge + 10 of ITS tax.
        $this->assertSame(100_00, $order->payment_surcharge);
        $this->assertSame([110_00], DB::table('m_taxes_order_taxes')->where('order_id', $order->id)->pluck('amount')->map(fn ($a) => (int) $a)->all(), 'one line per tax');
        $this->assertSame(1000_00 + 100_00 + 110_00 + $order->shipping_cost, $order->total);
        $this->assertSame($order->total, app(OrderTotals::class)->total($order));
    }

    public function test_an_included_tax_is_disclosed_inside_the_surcharge(): void
    {
        $tax = $this->tax('st-vat', 20, includes: true);
        $this->taxMethod($tax);

        $order = $this->place();

        $this->assertSame(1000_00 + 100_00 + $order->shipping_cost, $order->total, 'an included tax is not charged again');
        $this->assertSame(166_67 + 16_67, (int) DB::table('m_taxes_order_taxes')->where('order_id', $order->id)->value('amount'));
    }

    public function test_a_method_naming_no_tax_charges_none_on_its_surcharge(): void
    {
        $this->tax('st-sales', 10, includes: false);

        $order = $this->place();

        $this->assertSame(100_00, (int) DB::table('m_taxes_order_taxes')->where('order_id', $order->id)->value('amount'), 'the goods only');
    }

    public function test_the_checkout_shows_what_the_order_will_be_charged(): void
    {
        $tax = $this->tax('st-sales', 10, includes: false);
        $this->taxMethod($tax);

        $this->post('http://localhost/cart/add/st-chair', ['qty' => 1])->assertOk();
        $this->get('http://localhost/checkout')->assertOk()->assertSee(format_price(110_00, exact: true));
    }

    public function test_an_edit_writes_the_surcharge_tax_again(): void
    {
        $tax = $this->tax('st-sales', 10, includes: false);
        $this->taxMethod($tax);
        $order = $this->place();

        $items = $order->items;
        $items[0]['qty'] = 2;
        $items[0]['line_total'] = 2000_00;
        app(OrderTotals::class)->recalculate($order, $items);
        $order->refresh();

        $this->assertSame(200_00, $order->payment_surcharge);
        $this->assertSame(220_00, (int) DB::table('m_taxes_order_taxes')->where('order_id', $order->id)->value('amount'));
        $this->assertSame(2000_00 + 200_00 + 220_00 + $order->shipping_cost, $order->total);
    }

    public function test_the_method_form_names_its_taxes(): void
    {
        $tax = $this->tax('st-sales', 10, includes: false);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('http://localhost/admin/payments/methods/'.$this->invoice->id.'/edit')->assertOk()
            ->assertSee(__('Taxes on the surcharge'));

        $this->actingAs($admin)->put('http://localhost/admin/payments/methods/'.$this->invoice->id, [
            'name' => 'Invoice', 'enabled' => '1', 'surcharge_percent' => '10',
            'surcharge_taxes_shown' => '1', 'surcharge_taxes' => [$tax->id],
        ])->assertSessionHasNoErrors();
        $this->assertSame([$tax->id], DB::table('m_taxes_payment_method_tax')->where('payment_method_id', $this->invoice->id)->pluck('tax_id')->map(fn ($id) => (int) $id)->all());

        // Unticked — gone; a request without the field (the list's switch) keeps them.
        $this->actingAs($admin)->put('http://localhost/admin/payments/methods/'.$this->invoice->id, [
            'name' => 'Invoice', 'enabled' => '1', 'surcharge_taxes_shown' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('m_taxes_payment_method_tax')->where('payment_method_id', $this->invoice->id)->count());
    }

    protected function place(): Order
    {
        $this->post('http://localhost/cart/add/st-chair', ['qty' => 1])->assertOk();
        $this->post('http://localhost/checkout', [
            'customer_name' => 'Buyer', 'email' => 'st@example.test', 'address_line' => '1 Street', 'city' => 'Town',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => $this->invoice->id,
        ])->assertSessionHasNoErrors();

        return Order::latest('id')->firstOrFail();
    }

    protected function taxMethod(Tax $tax): void
    {
        DB::table('m_taxes_payment_method_tax')->insert(['payment_method_id' => $this->invoice->id, 'tax_id' => $tax->id]);
        $this->app->forgetInstance(TaxResolver::class);
    }

    protected function tax(string $code, float $percent, bool $includes): Tax
    {
        $tax = Tax::create([
            'code' => $code, 'name' => ['en' => $code], 'rate_type' => Tax::PERCENT, 'rate_percent' => $percent,
            'price_includes_tax' => $includes, 'applies_by_default' => true, 'applies_to_shipping' => false,
            'enabled' => true, 'position' => 50,
        ]);

        $this->app->forgetInstance(TaxResolver::class);
        $this->app->forgetInstance(TaxCalculator::class);

        return $tax;
    }
}
