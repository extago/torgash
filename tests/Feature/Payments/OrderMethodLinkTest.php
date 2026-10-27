<?php

namespace Tests\Feature\Payments;

use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\Payments\FakeGateway;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Payments\Gateways;
use Torgash\Payments\Models\Binding;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * An order knows which payment method it was placed with, by link and not by
 * name.
 *
 * The name is the record — what the customer was sold, printed for ever after.
 * It was also, until now, the only join: the gateway behind an order was found
 * by comparing that frozen string to the CURRENT names. Renaming a method
 * therefore detached every order placed before the rename — the Pay link
 * stopped working and nothing said why — and an order placed in Russian could
 * not be resolved while an admin browsed in English, because the comparison ran
 * against one locale's translation.
 */
class OrderMethodLinkTest extends TestCase
{
    protected PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        FakeGateway::reset();
        hook_listen('payments.gateways', fn (array $g) => $g + ['fake' => FakeGateway::class]);

        // A Russian name the seeded methods do not also use: matching by name
        // can collide between two methods, which is one more reason the id
        // exists — but it is not what these tests are about.
        $this->method = PaymentMethod::create([
            'name' => ['en' => 'Card online', 'ru' => 'Карта онлайн'],
            'enabled' => true,
            'position' => 90,
        ]);

        Binding::create([
            'payment_method_id' => $this->method->id,
            'gateway' => 'fake',
            'config' => ['token' => 'sekret'],
            'test_mode' => true,
        ]);
    }

    public function test_placing_an_order_records_the_method_and_its_name(): void
    {
        $order = $this->place();

        $this->assertSame($this->method->id, $order->payment_method_id);
        $this->assertSame('Card online', $order->payment_method_name);
    }

    /** The bug this exists for: renaming the method must not detach the order. */
    public function test_renaming_the_method_keeps_the_order_attached_to_its_gateway(): void
    {
        $order = $this->place();

        $this->method->update(['name' => ['en' => 'Card', 'ru' => 'Карта']]);

        $found = app(Gateways::class)->forOrder($order->fresh());

        $this->assertNotNull($found, 'the gateway was lost when the method was renamed');
        $this->assertSame('fake', $found['binding']->gateway);
        // ...while the order still prints what it was actually sold under.
        $this->assertSame('Card online', $order->fresh()->payment_method_name);
    }

    /**
     * An order from before the link existed still resolves — by name, against
     * EVERY translation, because the name was frozen in the customer's language
     * and not the reader's.
     */
    public function test_an_older_order_falls_back_to_the_name_in_any_language(): void
    {
        $order = $this->place();
        $order->forceFill(['payment_method_id' => null, 'payment_method_name' => 'Карта онлайн'])->save();

        $this->assertSame($this->method->id, PaymentMethod::forOrder($order->fresh())?->id);
        $this->assertNotNull(app(Gateways::class)->forOrder($order->fresh()));
    }

    /** Deleting the method leaves the order readable, and unresolvable. */
    public function test_deleting_the_method_nulls_the_link_and_keeps_the_name(): void
    {
        $order = $this->place();

        Binding::where('payment_method_id', $this->method->id)->delete();
        $this->method->delete();

        $order->refresh();

        $this->assertNull($order->payment_method_id);
        $this->assertSame('Card online', $order->payment_method_name);
        $this->assertNull(app(Gateways::class)->forOrder($order));
    }

    protected function place(): Order
    {
        Product::firstOrCreate(['sku' => 'LINK-1'], [
            'name' => ['en' => 'Linked'], 'slug' => 'linked-product',
            'price' => 10_00, 'stock' => 5, 'status' => 'active',
        ]);

        $this->post('http://localhost/cart/add/linked-product', ['qty' => 1])->assertOk();

        $this->post('http://localhost/checkout', [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => $this->method->id,
        ]);

        return Order::latest('id')->firstOrFail();
    }
}
