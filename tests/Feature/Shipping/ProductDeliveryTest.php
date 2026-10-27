<?php

namespace Tests\Feature\Shipping;

use App\Core\Settings\SettingsManager;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\Models\Zone;

/**
 * "Delivery to <town>" under the product page's buy panel: the methods on
 * offer for that product where the visitor is, with the rate's term and the
 * price the cart would charge — and nothing for a product nobody sends.
 */
class ProductDeliveryTest extends TestCase
{
    public function test_the_page_names_each_method_its_term_and_price(): void
    {
        $courier = ShippingMethod::where('name->en', 'Courier delivery')->firstOrFail();
        $everywhere = Zone::where('name->en', 'Everywhere')->firstOrFail();
        $courier->rates()->where('zone_id', $everywhere->id)->update([
            'base_rate' => 4_50,
            'delivery_time' => json_encode(['en' => '1–2 days']),
        ]);

        $product = $this->product('PD-1');
        $html = $this->get('http://localhost/product/'.$product->slug)->assertOk()->getContent();

        $block = substr($html, strpos($html, 'torgash-product-delivery'));
        $this->assertStringContainsString('Courier delivery', $block);
        $this->assertStringContainsString('1–2 days', $block);
        $this->assertStringContainsString('4.50', $block);

        app(SettingsManager::class)->set('torgash.shipping', 'product_estimate', false);
        $this->assertStringNotContainsString('torgash-product-delivery', $this->get('http://localhost/product/'.$product->slug)->getContent());
    }

    public function test_a_product_nobody_sends_has_no_delivery_block(): void
    {
        $product = $this->product('PD-2', ['needs_delivery' => false]);

        $this->assertStringNotContainsString('torgash-product-delivery', $this->get('http://localhost/product/'.$product->slug)->assertOk()->getContent());
    }

    protected function product(string $sku, array $extra = []): Product
    {
        return Product::create($extra + [
            'sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku),
            'price' => 100_00, 'stock' => 5, 'status' => 'active',
        ]);
    }
}
