<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;

/**
 * The small buy card at the end of the product page's right column: drawn
 * while the setting is on, with a button that drives the buy panel's own form
 * (no second set of controls), and a word instead of a button for a product
 * that cannot be bought.
 */
class StickyBuyCardTest extends TestCase
{
    public function test_the_card_follows_the_setting_and_drives_the_panels_form(): void
    {
        $product = $this->product('SBC-1', ['stock' => 5]);

        $html = $this->get('http://localhost/product/'.$product->slug)->assertOk()->getContent();
        $this->assertStringContainsString('torgash-buy-mini', $html);
        $this->assertStringContainsString('id="product-buy-panel"', $html);
        $this->assertStringContainsString('form.requestSubmit()', $html);

        app(SettingsManager::class)->set('torgash.catalog', 'sticky_buy_card', false);
        $without = $this->get('http://localhost/product/'.$product->slug)->getContent();
        $this->assertStringNotContainsString('torgash-buy-mini', $without);
        $this->assertSame(substr_count($without, '<input'), substr_count($html, '<input'), 'the card adds no field of its own');
    }

    public function test_a_product_that_cannot_be_bought_gets_no_button(): void
    {
        $product = $this->product('SBC-2', ['stock' => 0, 'availability_status' => 'discontinued']);

        $html = $this->get('http://localhost/product/'.$product->slug)->assertOk()->getContent();
        $card = substr($html, strpos($html, 'torgash-buy-mini'));

        $this->assertStringNotContainsString('x-on:click="buy()"', $card);
    }

    protected function product(string $sku, array $attributes): Product
    {
        return Product::create($attributes + [
            'sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku),
            'price' => 100_00, 'status' => 'active',
        ]);
    }
}
