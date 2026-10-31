<?php

namespace Tests\Feature\Checkout;

use App\Core\Settings\SettingsManager;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * The shop's minimum order (Orders\MinimumOrder): measured on the goods, the
 * basket says how much is missing and holds its button back, the checkout
 * sends an under-minimum basket back to it, PlaceOrder refuses it anyway, and
 * the product page carries the note where the shop puts it.
 */
class MinimumOrderTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'MIN-1', 'name' => ['en' => 'Wrapping paper'], 'slug' => 'wrapping-paper',
            'price' => 300_00, 'stock' => 50, 'status' => 'active',
        ]);

        app(SettingsManager::class)->set('torgash.checkout', 'min_order_amount', 1000);
    }

    public function test_under_the_minimum_the_basket_shows_the_bar_and_holds_the_button(): void
    {
        $this->post('http://localhost/cart/add/wrapping-paper', ['qty' => 1])
            ->assertOk()
            ->assertHeader('HX-Trigger', 'torgash:cart-changed');

        $this->get('http://localhost/cart')->assertOk()
            ->assertSee('Minimum order amount')
            ->assertSee('<progress', false)
            ->assertSee('value="30"', false)
            ->assertDontSee('href="'.route('checkout.create').'"', false);

        $this->get('http://localhost/checkout')->assertRedirect(route('cart.index'));
    }

    public function test_placing_under_the_minimum_is_refused_and_over_it_goes_through(): void
    {
        $this->post('http://localhost/cart/add/wrapping-paper', ['qty' => 3]);

        $this->post('http://localhost/checkout', $this->form())->assertSessionHasErrors('cart');
        $this->assertSame(0, Order::count());

        $this->post('http://localhost/cart/add/wrapping-paper', ['qty' => 1]);

        $this->get('http://localhost/cart')->assertOk()
            ->assertDontSee('<progress', false)
            ->assertSee('href="'.route('checkout.create').'"', false);

        $this->post('http://localhost/checkout', $this->form())->assertSessionHasNoErrors();
        $this->assertSame(1200_00, Order::latest('id')->firstOrFail()->subtotal);
    }

    public function test_no_minimum_no_bar(): void
    {
        app(SettingsManager::class)->set('torgash.checkout', 'min_order_amount', 0);
        $this->post('http://localhost/cart/add/wrapping-paper', ['qty' => 1]);

        $this->get('http://localhost/cart')->assertOk()
            ->assertDontSee('Minimum order amount')
            ->assertSee('href="'.route('checkout.create').'"', false);
        $this->get('http://localhost/product/wrapping-paper')->assertOk()->assertDontSee('Minimum order amount');
    }

    public function test_the_product_page_carries_the_note_where_the_shop_puts_it(): void
    {
        $this->get('http://localhost/product/wrapping-paper')->assertOk()
            ->assertSee('Minimum order amount')
            ->assertSee('hx-trigger="torgash:cart-changed from:body"', false);

        $this->post('http://localhost/cart/add/wrapping-paper', ['qty' => 1]);
        $this->get('http://localhost/checkout/minimum?place=buy_box')->assertOk()
            ->assertSee('<progress', false)
            ->assertSee('value="30"', false);

        app(SettingsManager::class)->set('torgash.checkout', 'min_order_product', 'none');
        $this->get('http://localhost/product/wrapping-paper')->assertOk()->assertDontSee('Minimum order amount');
    }

    /** @return array<string, mixed> */
    protected function form(): array
    {
        return [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => ShippingMethod::where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ];
    }
}
