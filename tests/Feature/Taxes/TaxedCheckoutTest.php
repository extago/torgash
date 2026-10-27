<?php

namespace Tests\Feature\Taxes;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\ZoneMatcher;
use Torgash\Taxes\Models\Tax;
use Torgash\Taxes\TaxCalculator;
use Torgash\Taxes\TaxResolver;

/**
 * Tax through the real checkout, because the arithmetic being right is only
 * half of it: the number the customer was shown has to be the number the order
 * is charged, and the order has to remember what it charged after the rates
 * move on.
 */
class TaxedCheckoutTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'TAXCO-1',
            'name' => ['en' => 'Taxed product'],
            'slug' => 'taxed-checkout-product',
            'price' => 100_00,
            'stock' => 10,
            'status' => 'active',
        ]);
    }

    public function test_an_added_tax_grows_the_order_total_and_is_recorded(): void
    {
        $tax = $this->tax('checkout-sales', 10, includes: false);

        $this->place(qty: 2);
        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(200_00, $order->subtotal);
        $this->assertSame(200_00 + 20_00 + $order->shipping_cost, $order->total);

        $row = DB::table('m_taxes_order_taxes')->where('order_id', $order->id)->first();
        $this->assertSame(20_00, (int) $row->amount);
        $this->assertFalse((bool) $row->price_includes_tax);
        $this->assertSame($tax->id, (int) $row->tax_id);
    }

    public function test_an_included_tax_leaves_the_total_alone_and_is_still_recorded(): void
    {
        $this->tax('checkout-vat', 20, includes: true);

        $this->place(qty: 1);
        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(100_00 + $order->shipping_cost, $order->total, 'an included tax must not be charged twice');

        $row = DB::table('m_taxes_order_taxes')->where('order_id', $order->id)->first();
        $this->assertSame(16_67, (int) $row->amount);
        $this->assertTrue((bool) $row->price_includes_tax);
    }

    /**
     * The invoice has to be able to explain an old order, so the snapshot is
     * what is shown — not today's rate applied to yesterday's order.
     */
    public function test_changing_the_rate_afterwards_does_not_change_a_placed_order(): void
    {
        $tax = $this->tax('checkout-sales', 10, includes: false);

        $this->place(qty: 1);
        $order = Order::latest('id')->firstOrFail();
        $totalWhenPlaced = $order->total;

        $tax->update(['rate_percent' => 25]);
        $this->refreshServices();

        $lines = hook_filter('checkout.order.extra_totals', [], ['order' => $order->fresh()]);

        $this->assertSame($totalWhenPlaced, $order->fresh()->total);
        $this->assertSame(10_00, $lines[0]['amount']);
    }

    public function test_the_checkout_screen_shows_the_same_total_the_order_is_charged(): void
    {
        $this->tax('checkout-sales', 10, includes: false);

        $this->post('http://localhost/cart/add/taxed-checkout-product', ['qty' => 2])->assertOk();

        $shown = $this->get('http://localhost/checkout')->assertOk()->getContent();

        $this->post('http://localhost/checkout', $this->form());
        $order = Order::latest('id')->firstOrFail();

        // The screen renders the total per shipping method into a JS map, so
        // asserting the formatted number appears is what "shown" means here.
        // Both sides are normalised first: format_price() joins the amount and
        // the symbol with a non-breaking space, which the page then json-escapes.
        $normalise = fn (string $text) => str_replace(" ", " ", $text);

        $this->assertStringContainsString($normalise(format_price($order->total)), $normalise($shown));
    }

    public function test_a_tax_on_shipping_is_charged_on_the_shipping_too(): void
    {
        $tax = $this->tax('checkout-sales', 10, includes: false);
        $tax->update(['applies_to_shipping' => true]);
        $this->refreshServices();

        // A method that actually costs something, whatever the seeded ones do.
        // The price lives on its rate in a zone, so the method alone is free.
        $method = ShippingMethod::create([
            'name' => ['en' => 'Paid courier'],
            'position' => 99,
            'enabled' => true,
        ]);
        $method->rates()->create([
            'zone_id' => (new ZoneMatcher)->fallback()->id,
            'base_rate' => 30_00,
            'enabled' => true,
        ]);

        $this->place(qty: 1, shippingMethodId: $method->id);
        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(30_00, $order->shipping_cost);
        $this->assertSame(13_00, (int) DB::table('m_taxes_order_taxes')->where('order_id', $order->id)->value('amount'));
        $this->assertSame(100_00 + 30_00 + 13_00, $order->total);
    }

    protected function place(int $qty, ?int $shippingMethodId = null): void
    {
        $this->post('http://localhost/cart/add/taxed-checkout-product', ['qty' => $qty])->assertOk();
        $this->post('http://localhost/checkout', $this->form($shippingMethodId));
    }

    protected function form(?int $shippingMethodId = null): array
    {
        return [
            'customer_name' => 'Taxed Buyer',
            'email' => 'taxed@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => $shippingMethodId ?? ShippingMethod::where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ];
    }

    protected function tax(string $code, float $percent, bool $includes): Tax
    {
        $tax = Tax::create([
            'code' => $code,
            'name' => ['en' => $code],
            'rate_type' => Tax::PERCENT,
            'rate_percent' => $percent,
            'price_includes_tax' => $includes,
            'applies_by_default' => true,
            'applies_to_shipping' => false,
            'enabled' => true,
            'position' => 50,
        ]);

        $this->refreshServices();

        return $tax;
    }

    protected function refreshServices(): void
    {
        $this->app->forgetInstance(TaxResolver::class);
        $this->app->forgetInstance(TaxCalculator::class);
    }
}
