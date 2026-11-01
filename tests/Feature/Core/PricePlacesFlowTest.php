<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Core\Support\PriceScale;
use App\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Api\Models\ApiToken;
use Torgash\Cart\CartManager;
use Torgash\Cart\Lines;
use Torgash\Catalog\Exim\ProductImport;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Rma\Eligibility;

/**
 * A price per unit finer than a cent, end to end (PLAN §65): typed, stored,
 * bought by the thousand, discounted, taxed, returned in parts, sent through
 * the API, imported from a spreadsheet, and moved when the shop changes its
 * mind about the places.
 */
class PricePlacesFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->places(4);
    }

    public function test_a_price_finer_than_a_cent_is_typed_and_kept_in_the_admin(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $product = $this->product('FINE-1', 100);

        $this->actingAs($admin)
            ->patch(route('admin.catalog.products.quick', $product), ['sku' => 'FINE-1', 'price' => '0.012'])
            ->assertOk();

        $this->assertSame(120, (int) $product->fresh()->price);

        // More places than the shop keeps are an error, not a rounding.
        $this->patch(route('admin.catalog.products.quick', $product), ['sku' => 'FINE-1', 'price' => '0.01255']);
        $this->assertSame(120, (int) $product->fresh()->price);
    }

    public function test_a_thousand_pieces_at_a_fraction_of_a_cent_cost_what_they_should(): void
    {
        $product = $this->product('FINE-2', 120); // $0.012

        $this->assertSame(['unit_price' => 120, 'extra_price' => 0, 'extra_total' => 0, 'line_total' => 1200], Lines::money(120, [], 1000));
        $this->assertSame(400, Lines::money(120, [], 333)['line_total'], '0.012 × 333 = 3.996 → 4.00');
        $this->assertSame(1, Lines::money(125, [], 1)['line_total'], '0.0125 × 1 = 0.0125 → 0.01');

        $cart = app(CartManager::class);
        $cart->add($product->id, 1000);
        $line = $cart->items()->first();

        $this->assertSame(120, $line->unit_price);
        $this->assertSame(1200, $line->line_total);
        $this->assertSame(1200, $cart->total());
    }

    public function test_a_percentage_off_is_taken_from_the_price_per_unit(): void
    {
        $product = $this->product('FINE-3', 120);
        $group = CustomerGroup::create(['name' => ['en' => 'Seven'], 'discount_percent' => 7]);
        $user = User::factory()->create(['customer_group_id' => $group->id]);

        // 0.012 − 7% = 0.01116 → 0.0112: the price the card shows is the one
        // the line multiplies, and 1000 of them are 11.20.
        $price = $product->fresh()->effectivePrice($user);
        $this->assertSame(112, $price);
        $this->assertSame(1120, Lines::money($price, [], 1000)['line_total']);
    }

    public function test_returns_in_parts_add_up_to_what_the_line_cost(): void
    {
        $line = ['product_id' => 1, 'qty' => 1000, 'unit_price' => 120, 'line_total' => 1200, 'bought_qty' => 1000, 'taken_qty' => 0];

        $first = Eligibility::share($line, 333);
        $second = Eligibility::share(['taken_qty' => 333] + $line, 333);
        $third = Eligibility::share(['taken_qty' => 666] + $line, 334);

        $this->assertSame(400, $first);
        $this->assertSame(1200, $first + $second + $third, 'no cent lost or made up');

        // A line of three at 1.00 returned one by one is still 1.00 each.
        $plain = ['product_id' => 1, 'qty' => 3, 'line_total' => 100, 'bought_qty' => 3, 'taken_qty' => 0];
        $parts = [Eligibility::share($plain, 1), Eligibility::share(['taken_qty' => 1] + $plain, 1), Eligibility::share(['taken_qty' => 2] + $plain, 1)];
        $this->assertSame(100, array_sum($parts));
    }

    public function test_the_api_speaks_decimal_strings_both_ways(): void
    {
        $admin = User::role('admin')->firstOrFail();
        [, $token] = ApiToken::issue($admin, 'Tests');
        $product = $this->product('FINE-API', 120);
        $call = fn (string $method, string $uri, array $data = []) => $this->json($method, 'http://localhost'.$uri, $data, ['Authorization' => 'Bearer '.$token]);

        $call('GET', '/api/v2/admin/products/FINE-API')
            ->assertOk()
            ->assertJsonPath('data.price', '0.0120')
            ->assertJsonPath('data.currency', shop_currency());

        $call('PATCH', '/api/v2/admin/products/FINE-API', ['price' => '0.0125'])->assertOk()->assertJsonPath('data.price', '0.0125');
        $this->assertSame(125, (int) $product->fresh()->price);

        $call('PATCH', '/api/v2/admin/products/FINE-API', ['price' => '0.01255'])->assertStatus(422);
        $this->assertSame(125, (int) $product->fresh()->price);
    }

    public function test_an_imported_price_keeps_its_places(): void
    {
        $this->assertSame('0.0125', ProductImport::decimal('0,0125'));
        $this->assertSame('0.012', ProductImport::decimal('0,012'));
        $this->assertSame('1299', ProductImport::decimal('1,299'));
        $this->assertSame('15000', ProductImport::decimal('15.000'));
        $this->assertSame('1299.50', ProductImport::decimal('1 299,50'));
        $this->assertSame('1299.5', ProductImport::decimal('1.299,5'));
        $this->assertSame('-3.5', ProductImport::decimal('-3,5'));
        $this->assertNull(ProductImport::decimal('abc'));

        $this->assertSame(125, price_scale()->fromMajor(ProductImport::decimal('0,0125')));
        $this->assertSame('0.0125', ProductImport::written(125));
        $this->assertSame('1290', ProductImport::written(12900000));
    }

    public function test_changing_the_places_moves_every_price_and_no_amount(): void
    {
        $product = $this->product('FINE-MOVE', 199900); // 19.99 at four places
        $product->priceTiers()->create(['customer_group_id' => null, 'min_qty' => 10, 'price' => 150000]);

        $order = Order::create([
            'order_number' => 'TG-PLACES', 'customer_name' => 'Buyer', 'email' => 'buyer@example.test',
            'address_line' => '1 Test Street', 'city' => 'Testville',
            'currency_code' => shop_currency(), 'subtotal' => 3998, 'shipping_cost' => 0, 'total' => 3998,
            'status' => 'open', 'payment_state' => \Torgash\Checkout\Orders\PaymentState::PENDING, 'stock_deducted' => false,
            'items' => [['key' => 'x', 'product_id' => $product->id, 'qty' => 2, 'unit_price' => 199900, 'line_total' => 3998, 'options' => [['label' => 'Wrap', 'qty' => 1, 'amount' => 5000]]]],
        ]);

        $this->places(2);

        $this->assertSame(1999, (int) $product->fresh()->price);
        $this->assertSame(1500, (int) $product->fresh()->priceTiers->first()->price);
        $items = Order::find($order->id)->items;
        $this->assertSame(1999, $items[0]['unit_price']);
        $this->assertSame(3998, $items[0]['line_total'], 'an amount is not a price: it does not move');
        $this->assertSame(50, $items[0]['options'][0]['amount']);

        $this->places(4);

        $this->assertSame(199900, (int) $product->fresh()->price);
        $this->assertSame(199900, Order::find($order->id)->items[0]['unit_price']);
    }

    public function test_fewer_places_are_refused_while_a_product_would_lose_digits(): void
    {
        $this->product('FINE-LOSS', 125);
        $admin = User::role('admin')->firstOrFail();

        $values = [];
        foreach (\App\Core\Settings\CoreSettings::definitions() as $def) {
            if (isset($def['key']) && ! in_array($def['type'] ?? 'text', ['group', 'action', 'link', 'image'], true)) {
                $values[$def['key']] = app(SettingsManager::class)->get('core', $def['key'], $def['default'] ?? null);
            }
        }

        $this->actingAs($admin)
            ->post(route('admin.settings.update', 'core'), [PriceScale::SETTING => '2'] + $values)
            ->assertSessionHasErrors(PriceScale::SETTING);

        $this->assertSame(4, price_scale()->places());
        $this->assertSame(125, (int) DB::table('m_catalog_products')->where('sku', 'FINE-LOSS')->value('price'));
    }

    public function test_the_storefront_prints_the_places_a_price_has(): void
    {
        $product = $this->product('FINE-SHOW', 120);
        $books = app(\App\Core\Support\Currencies::class)->find(shop_currency());

        $this->get('http://localhost/product/'.$product->slug)
            ->assertOk()
            ->assertSee($books->format(0.012, 3), false);
    }

    // ------------------------------------------------------------- helpers

    protected function places(int $places): void
    {
        app(SettingsManager::class)->set('core', PriceScale::SETTING, (string) $places);
    }

    protected function product(string $sku, int $units): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku),
            'price' => $units, 'stock' => 100000, 'status' => 'active',
        ]);
    }
}
