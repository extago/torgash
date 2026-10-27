<?php

namespace Tests\Feature\Checkout;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * Which contact an order must carry is the shop's (`order_contact`, PLAN §44
 * 3b): an email by default, as before; a shop that takes orders by phone lets
 * a guest order with a number alone — no email, no code unless the shop asks
 * for codes — and the number must be one a person can ring.
 */
class OrderContactTest extends TestCase
{
    protected PaymentMethod $payment;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->payment = PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true, 'position' => 99]);
        Product::create(['sku' => 'OC-1', 'name' => ['en' => 'Helmet'], 'slug' => 'oc-helmet', 'price' => 10_00, 'stock' => 5, 'status' => 'active']);
        $this->post('http://localhost/cart/add/oc-helmet', ['qty' => 1])->assertOk();
    }

    public function test_by_default_an_order_needs_an_email_as_before(): void
    {
        $this->post('http://localhost/checkout', $this->form(['email' => '', 'phone' => '+380935550107']))->assertSessionHasErrors('email');
        $this->assertSame(0, Order::count());
    }

    public function test_a_phone_alone_places_an_order_where_the_shop_takes_orders_by_phone(): void
    {
        $this->mode('phone');

        $this->get('http://localhost/checkout')->assertOk()->assertSee(__('Phone').' *');

        $this->post('http://localhost/checkout', $this->form(['email' => '', 'phone' => '']))->assertSessionHasErrors('phone');
        $this->post('http://localhost/checkout', $this->form(['email' => '', 'phone' => '12']))->assertSessionHasErrors('phone');

        $this->post('http://localhost/checkout', $this->form(['email' => '', 'phone' => '+380935550107']))->assertRedirect();

        $order = Order::latest('id')->firstOrFail();
        $this->assertNull($order->email);
        $this->assertSame('+380935550107', preg_replace('/\s+/', '', (string) $order->phone));
        Mail::assertNothingQueued();
    }

    public function test_either_takes_one_of_the_two(): void
    {
        $this->mode('either');

        $this->post('http://localhost/checkout', $this->form(['email' => '', 'phone' => '']))->assertSessionHasErrors(['email', 'phone']);
        $this->post('http://localhost/checkout', $this->form(['email' => '', 'phone' => '+380935550107']))->assertRedirect();
    }

    public function test_a_signed_in_customer_with_no_email_can_order(): void
    {
        $customer = User::factory()->create(['email' => null, 'phone' => '+380935550199', 'phone_verified_at' => now()]);

        $this->actingAs($customer)
            ->post('http://localhost/checkout', $this->form(['email' => '', 'phone' => '+380935550199']))
            ->assertRedirect();

        $this->assertSame($customer->id, Order::latest('id')->value('user_id'));
    }

    protected function mode(string $mode): void
    {
        app(SettingsManager::class)->set('torgash.checkout', 'order_contact', $mode);
    }

    protected function form(array $overrides = []): array
    {
        return $overrides + [
            'customer_name' => 'Taras',
            'email' => 'taras@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Kyiv',
            'country' => 'UA',
            'postal_code' => '01001',
            'payment_method_id' => $this->payment->id,
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
        ];
    }
}
