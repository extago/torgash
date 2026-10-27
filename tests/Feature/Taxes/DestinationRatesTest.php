<?php

namespace Tests\Feature\Taxes;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Shipping\Location;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Taxes\Destinations;
use Torgash\Taxes\Models\Tax;
use Torgash\Taxes\Models\TaxRate;
use Torgash\Taxes\TaxCalculator;
use Torgash\Taxes\TaxResolver;

/**
 * Rates by destination: where the goods go decides what a tax charges, the
 * narrowest row naming the address wins, and nothing named means the tax's
 * own rate — or nothing at all for a tax charged only where it is listed.
 */
class DestinationRatesTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'DEST-1',
            'name' => ['en' => 'Destination product'],
            'slug' => 'destination-product',
            'price' => 100_00,
            'stock' => 10,
            'status' => 'active',
        ]);
    }

    public function test_the_narrowest_row_naming_the_address_wins(): void
    {
        $tax = $this->tax('dest-sales', 5, includes: false);
        $this->rate($tax, 'country', 'US', 6);
        $this->rate($tax, 'state', 'US:CA', 7.25);
        $this->rate($tax, 'postal', '94*', 8.5);
        $this->rate($tax, 'postal', '94105', 8.625);

        $this->assertSame(5_00, $this->charged(null));
        $this->assertSame(5_00, $this->charged(new Location(country: 'DE')), 'no row names Germany: the tax\'s own rate');
        $this->assertSame(6_00, $this->charged(new Location(country: 'US', state: 'NY')));
        $this->assertSame(7_25, $this->charged(new Location(country: 'US', state: 'CA', postal: '90001')));
        $this->assertSame(8_50, $this->charged(new Location(country: 'US', state: 'CA', postal: '94110')));
        $this->assertSame(8_63, $this->charged(new Location(country: 'US', state: 'CA', postal: '94105')));
    }

    /** "12" is a region of Ukraine and of Russia; a row names one of them. */
    public function test_a_region_row_counts_only_inside_its_own_country(): void
    {
        $tax = $this->tax('dest-region', 10, includes: false);
        $this->rate($tax, 'state', 'UA:12', 20);

        $this->assertSame(20_00, $this->charged(new Location(country: 'UA', state: '12')));
        $this->assertSame(10_00, $this->charged(new Location(country: 'RU', state: '12')));
    }

    /** Not charged is no line; a 0% rate is a line that says 0%. */
    public function test_not_charged_is_a_different_answer_from_zero(): void
    {
        $tax = $this->tax('dest-vat', 20, includes: true);
        $this->rate($tax, 'country', 'PL', 0);
        $this->rate($tax, 'country', 'US', null);

        $calculator = app(TaxCalculator::class);
        $line = [['product_id' => $this->product->id, 'unit_price' => 120_00, 'qty' => 1]];

        $poland = $calculator->forCart($line, where: new Location(country: 'PL'));
        $this->assertCount(1, $poland);
        $this->assertSame(0, reset($poland)->amount);
        $this->assertSame('dest-vat 0%', reset($poland)->label());

        $this->assertSame([], $calculator->forCart($line, where: new Location(country: 'US')));

        $home = $calculator->forCart($line, where: new Location(country: 'UA'));
        $this->assertSame(20_00, reset($home)->amount, 'an included tax: 120 holds 20 of VAT, and the price does not move');
    }

    public function test_a_tax_charged_only_where_listed_is_charged_nowhere_else_and_not_before_an_address(): void
    {
        $tax = $this->tax('dest-nexus', 5, includes: false);
        $tax->update(['only_listed' => true]);
        $this->rate($tax, 'state', 'US:WA', 6.5);
        $this->refreshServices();

        $this->assertSame(6_50, $this->charged(new Location(country: 'US', state: 'WA')));
        $this->assertSame(0, $this->charged(new Location(country: 'US', state: 'OR')));
        $this->assertSame(0, $this->charged(null));
    }

    public function test_a_postal_pattern_ignores_spaces_and_case(): void
    {
        $this->assertTrue(Destinations::postalMatches('SW1A*', 'sw1a 1aa'));
        $this->assertTrue(Destinations::postalMatches('98?78', '98178'));
        $this->assertFalse(Destinations::postalMatches('98?78', '981178'));
        $this->assertFalse(Destinations::postalMatches('98*', '1980'));
    }

    /** The name carries the rate that applies HERE, not the tax's own. */
    public function test_a_rate_in_the_name_is_swapped_for_the_rate_charged(): void
    {
        $tax = $this->tax('dest-named', 20, includes: true);
        $tax->update(['name' => ['en' => 'VAT 20%']]);
        $this->rate($tax, 'country', 'PL', 23);
        $this->refreshServices();

        $placed = app(Destinations::class)->at($tax->fresh(), new Location(country: 'PL'));

        $this->assertSame('VAT 23%', $placed->label());
        $this->assertSame('VAT 20%', $tax->fresh()->label());
    }

    /** Through the real checkout: the country typed in the form is where tax is owed. */
    public function test_checkout_charges_the_rate_of_the_address_and_the_order_remembers_it(): void
    {
        $tax = $this->tax('dest-checkout', 10, includes: false);
        $this->rate($tax, 'country', 'DE', 19);

        $this->post('http://localhost/cart/add/destination-product', ['qty' => 1])->assertOk();
        $this->post('http://localhost/checkout', $this->form(['country' => 'DE']));

        $order = Order::latest('id')->firstOrFail();
        $row = DB::table('m_taxes_order_taxes')->where('order_id', $order->id)->first();

        $this->assertSame('DE', $order->country);
        $this->assertSame(19_00, (int) $row->amount);
        $this->assertEquals(19, (float) $row->rate_percent, 'the snapshot keeps the rate actually charged');
        $this->assertSame(100_00 + 19_00 + $order->shipping_cost, $order->total);
    }

    /**
     * An admin editing an order re-snapshots its tax — at the customer's
     * address, read off the order, not at wherever the admin's session is.
     */
    public function test_an_edited_order_is_retaxed_at_its_own_destination(): void
    {
        $tax = $this->tax('dest-edit', 10, includes: false);
        $this->rate($tax, 'country', 'DE', 19);

        $this->post('http://localhost/cart/add/destination-product', ['qty' => 1])->assertOk();
        $this->post('http://localhost/checkout', $this->form(['country' => 'DE']));
        $order = Order::latest('id')->firstOrFail();

        $this->actingAs(User::role('admin')->firstOrFail())
            ->patch('http://localhost/admin/orders/'.$order->order_number.'/items/'.$this->product->id, [
                'qty' => 2,
                'unit_price' => '100.00',
            ])
            ->assertOk();

        $this->assertSame(38_00, (int) DB::table('m_taxes_order_taxes')->where('order_id', $order->id)->value('amount'));
    }

    public function test_the_form_saves_rates_and_skips_empty_and_repeated_rows(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/taxes', [
                'code' => 'dest-form',
                'name' => 'Sales tax',
                'rate_type' => Tax::PERCENT,
                'rate_percent' => 5,
                'enabled' => 1,
                'only_listed' => 1,
                'rates' => [
                    ['kind' => 'state', 'state' => 'us:CA', 'charged' => '1', 'rate' => '7.25'],
                    ['kind' => 'postal', 'postal' => ' 94 105 ', 'charged' => '0', 'rate' => '9'],
                    ['kind' => 'country', 'country' => '', 'charged' => '1', 'rate' => '3'],
                    ['kind' => 'state', 'state' => 'US:CA', 'charged' => '1', 'rate' => '1'],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $tax = Tax::where('code', 'dest-form')->firstOrFail();
        $rows = $tax->rates()->get();

        $this->assertTrue($tax->only_listed);
        $this->assertCount(2, $rows);
        $this->assertSame(['state', 'US:CA', 2], [$rows[0]->kind, $rows[0]->value, $rows[0]->level]);
        $this->assertEquals(7.25, $rows[0]->rate_percent);
        $this->assertSame(['postal', '94105', 4, false], [$rows[1]->kind, $rows[1]->value, $rows[1]->level, $rows[1]->charged]);
        $this->assertNull($rows[1]->rate_percent);

        $this->get('http://localhost/admin/taxes/'.$tax->id.'/edit')->assertOk()->assertSee('94105');
        $this->get('http://localhost/admin/taxes')->assertOk();
    }

    protected function charged(?Location $where): int
    {
        $lines = app(TaxCalculator::class)->forCart(
            [['product_id' => $this->product->id, 'unit_price' => 100_00, 'qty' => 1]],
            where: $where,
        );

        return array_sum(array_map(fn ($line) => $line->amount, $lines));
    }

    protected function rate(Tax $tax, string $kind, string $value, ?float $percent): void
    {
        TaxRate::create([
            'tax_id' => $tax->id,
            'kind' => $kind,
            'value' => $value,
            'charged' => $percent !== null,
            'rate_percent' => $percent,
        ]);

        $this->refreshServices();
    }

    protected function form(array $extra = []): array
    {
        return $extra + [
            'customer_name' => 'Destination Buyer',
            'email' => 'dest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => ShippingMethod::where('enabled', true)->value('id'),
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
        $this->app->forgetInstance(Destinations::class);
    }
}
