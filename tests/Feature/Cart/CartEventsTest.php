<?php

namespace Tests\Feature\Cart;

use Tests\TestCase;
use Torgash\Cart\CartEvents;
use Torgash\Cart\CartManager;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Catalog\Seo\ProductsEvent;

/**
 * The basket tells the page WHAT moved (PLAN §73): `torgash:cart-changed`
 * carries the lines added and removed, which is all a counter needs to report
 * "added to the basket" without knowing the basket.
 */
class CartEventsTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $make = Feature::create(['name' => ['en' => 'Make'], 'code' => 'ce_make', 'purpose' => 'brand', 'input_type' => 'select']);
        $acme = FeatureValue::create(['feature_id' => $make->id, 'value' => ['en' => 'Acme'], 'slug' => 'ce-acme']);
        $tools = Category::create(['name' => ['en' => 'Tools'], 'slug' => 'ce-tools', 'status' => 'active']);
        $anvils = Category::create(['name' => ['en' => 'Anvils'], 'slug' => 'ce-anvils', 'status' => 'active', 'parent_id' => $tools->id]);

        $this->product = Product::create([
            'sku' => 'CE-1', 'name' => ['en' => 'Наковальня'], 'slug' => 'ce-anvil',
            'price' => 150_00, 'stock' => 50, 'status' => 'active',
        ]);
        ProductFeature::create(['product_id' => $this->product->id, 'feature_id' => $make->id, 'feature_value_id' => $acme->id]);
        $this->product->categories()->attach($anvils->id);
    }

    public function test_adding_says_what_went_in_at_the_price_it_went_in_at(): void
    {
        $response = $this->withHeader('HX-Request', 'true')
            ->post('http://localhost/cart/add/ce-anvil', ['qty' => 3])->assertOk();

        $header = (string) $response->headers->get('HX-Trigger');
        $this->assertTrue(mb_check_encoding($header, 'ASCII'), 'a header is ASCII: '.$header);
        $this->assertStringNotContainsString('Наковальня', $header, 'the Cyrillic name travels escaped');

        $this->assertSame([
            'added' => [[
                'id' => (string) $this->product->id, 'sku' => 'CE-1', 'name' => 'Наковальня', 'brand' => 'Acme',
                'categories' => ['Tools', 'Anvils'], 'price' => 150, 'quantity' => 3,
            ]],
            'currency' => shop_currency(),
        ], $this->detail($response));
    }

    public function test_a_quantity_changed_moves_only_the_difference_and_the_bin_moves_the_rest(): void
    {
        $key = app(CartManager::class)->add($this->product->id, 2);

        $up = $this->withHeader('HX-Request', 'true')->patch('http://localhost/cart/items/'.$key, ['qty' => 5])->assertOk();
        $this->assertSame(3, $this->detail($up)['added'][0]['quantity']);
        $this->assertArrayNotHasKey('removed', $this->detail($up));

        $down = $this->withHeader('HX-Request', 'true')->patch('http://localhost/cart/items/'.$key, ['qty' => 4])->assertOk();
        $this->assertSame(1, $this->detail($down)['removed'][0]['quantity']);

        $same = $this->withHeader('HX-Request', 'true')->patch('http://localhost/cart/items/'.$key, ['qty' => 4])->assertOk();
        $this->assertSame('torgash:cart-changed', $same->headers->get('HX-Trigger'), 'nothing moved, nothing described');

        $gone = $this->withHeader('HX-Request', 'true')->delete('http://localhost/cart/items/'.$key)->assertOk();
        $this->assertSame(4, $this->detail($gone)['removed'][0]['quantity']);
        $this->assertEquals(150, $this->detail($gone)['removed'][0]['price']);
    }

    /**
     * Emptying a big basket: the header stays inside its budget — fewer
     * words per line, then fewer lines — rather than a 502 from the proxy.
     */
    public function test_emptying_a_big_basket_stays_inside_the_header_budget(): void
    {
        $cart = app(CartManager::class);

        foreach (range(1, 30) as $i) {
            $product = Product::create([
                'sku' => 'CE-BIG-'.$i, 'name' => ['en' => str_repeat('Очень длинное название товара ', 4).$i],
                'slug' => 'ce-big-'.$i, 'price' => 10_00, 'stock' => 5, 'status' => 'active',
            ]);
            $cart->add($product->id, 1);
        }

        $response = $this->withHeader('HX-Request', 'true')->delete('http://localhost/cart')->assertOk();
        $header = (string) $response->headers->get('HX-Trigger');

        $this->assertLessThanOrEqual(ProductsEvent::MAX_BYTES, strlen($header));
        $removed = $this->detail($response)['removed'];
        $this->assertNotEmpty($removed);
        $this->assertLessThanOrEqual(CartEvents::MAX_LINES, count($removed));
        $this->assertSame([], $removed[0]['categories'], 'the long description went first');
        $this->assertSame([], app(CartManager::class)->reload()->raw(), 'and the basket is empty all the same');
    }

    /** @return array<string, mixed> */
    protected function detail($response): array
    {
        $trigger = json_decode((string) $response->headers->get('HX-Trigger'), true);
        $this->assertIsArray($trigger, 'the event carries its detail');

        return $trigger['torgash:cart-changed'];
    }
}
