<?php

namespace Tests\Feature\Cart;

use App\Core\Settings\SettingsManager;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;

/**
 * Quick view (PLAN §57, setting torgash.cart.quick_view): an eye in a card's
 * corner opens the product in the shared dialog — its name, price, the same
 * buy box as its page — and it can be switched off.
 */
class QuickViewTest extends TestCase
{
    public function test_a_card_offers_it_and_the_window_holds_the_buy_box(): void
    {
        $product = $this->product();

        $this->get('http://localhost/catalog')->assertOk()
            ->assertSee(route('cart.quick', $product->slug, false), false);

        $this->get('http://localhost/cart/quick/'.$product->slug)->assertOk()
            ->assertSee('data-dialog-wide', false)
            ->assertSee('Quick lamp')
            ->assertSee(route('cart.add', $product->slug, false), false)
            ->assertSee(route('catalog.product', $product->slug, false), false);
    }

    public function test_switched_off_it_is_neither_offered_nor_answered(): void
    {
        $product = $this->product();
        app(SettingsManager::class)->set('torgash.cart', 'quick_view', false);

        $this->get('http://localhost/catalog')->assertOk()
            ->assertDontSee(route('cart.quick', $product->slug, false), false);
        $this->get('http://localhost/cart/quick/'.$product->slug)->assertNotFound();
    }

    public function test_a_hidden_product_is_not_shown(): void
    {
        $product = $this->product(['status' => 'disabled']);

        $this->get('http://localhost/cart/quick/'.$product->slug)->assertNotFound();
    }

    protected function product(array $extra = []): Product
    {
        return Product::create($extra + [
            'sku' => 'QV-1', 'name' => ['en' => 'Quick lamp'], 'slug' => 'quick-lamp',
            'price' => 25_00, 'stock' => 3, 'status' => 'active',
        ]);
    }
}
