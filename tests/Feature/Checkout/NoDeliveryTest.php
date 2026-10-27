<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Giftcards\Models\GiftProduct;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * Goods that are not sent anywhere (PLAN §40.10/2) — a service, a download, an
 * electronic gift card: a basket of only such things asks for no address and
 * no delivery; one real parcel beside them asks for both, as before.
 */
class NoDeliveryTest extends TestCase
{
    protected PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->cash = PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true, 'position' => -1]);
        Product::create(['sku' => 'ND-SVC', 'name' => ['en' => 'Installation'], 'slug' => 'nd-installation', 'price' => 300_00, 'stock' => 0, 'status' => 'active', 'availability_status' => 'unlimited', 'needs_delivery' => false]);
        Product::create(['sku' => 'ND-BOX', 'name' => ['en' => 'Boiler'], 'slug' => 'nd-boiler', 'price' => 900_00, 'stock' => 5, 'status' => 'active']);
    }

    public function test_a_basket_of_services_asks_for_no_address_and_no_delivery(): void
    {
        $this->post('http://localhost/cart/add/nd-installation', ['qty' => 1])->assertOk();

        $this->get('http://localhost/checkout')->assertOk()->assertDontSee('name="address_line"', false);

        $this->post('http://localhost/checkout', [
            'customer_name' => 'Buyer', 'email' => 'buyer@example.test',
            'payment_method_id' => $this->cash->id,
        ])->assertSessionHasNoErrors();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame(300_00, $order->total);
        $this->assertNull($order->shipping_method_name);
        $this->assertFalse($order->hasDelivery());
        $this->get('http://localhost/checkout/'.$order->order_number.'/confirmation')->assertOk();
    }

    public function test_one_parcel_beside_them_asks_for_everything_again(): void
    {
        $this->post('http://localhost/cart/add/nd-installation', ['qty' => 1]);
        $this->post('http://localhost/cart/add/nd-boiler', ['qty' => 1]);

        $this->post('http://localhost/checkout', [
            'customer_name' => 'Buyer', 'email' => 'buyer@example.test',
            'payment_method_id' => $this->cash->id,
        ])->assertSessionHasErrors(['city', 'shipping_method_id']);
    }

    public function test_an_electronic_gift_card_is_never_delivered(): void
    {
        $card = Product::create(['sku' => 'ND-GIFT', 'name' => ['en' => 'Gift'], 'slug' => 'nd-gift', 'price' => 500_00, 'status' => 'active', 'availability_status' => 'unlimited']);
        GiftProduct::create(['product_id' => $card->id, 'free_amount' => false]);

        $this->assertFalse($card->fresh()->needsDelivery());
    }

    public function test_the_product_form_saves_the_switch(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $product = Product::where('sku', 'ND-BOX')->firstOrFail();

        $this->actingAs($admin)->get('http://localhost/admin/catalog/products/'.$product->id.'/edit')->assertOk()->assertSee('needs_delivery_form', false);
    }
}
