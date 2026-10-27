<?php

namespace Tests\Feature\Checkout;

use App\Core\Settings\SettingsManager;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Coupon;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * What the checkout asks, which the shop decides: an order for a company
 * (`business_orders`), the postal code (`postal_code`), and the coupon at
 * checkout, which re-prices the summary rather than drawing a cart.
 */
class CheckoutFieldsTest extends TestCase
{
    protected PaymentMethod $payment;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->payment = PaymentMethod::create(['name' => ['en' => 'Invoice'], 'enabled' => true, 'position' => 99]);

        Product::create([
            'sku' => 'CF-1', 'name' => ['en' => 'Fields widget'], 'slug' => 'fields-widget',
            'price' => 10_00, 'stock' => 5, 'status' => 'active',
        ]);
        $this->post('http://localhost/cart/add/fields-widget', ['qty' => 1])->assertOk();
    }

    protected function set(string $key, mixed $value): void
    {
        app(SettingsManager::class)->set('torgash.checkout', $key, $value);
    }

    /** @return array<string, mixed> */
    protected function form(array $overrides = []): array
    {
        return $overrides + [
            'customer_name' => 'Olena',
            'email' => 'olena@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Kyiv',
            'country' => 'UA',
            'postal_code' => '01001',
            'payment_method_id' => $this->payment->id,
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
        ];
    }

    public function test_a_company_order_keeps_the_company(): void
    {
        $this->set('business_orders', true);
        $this->get('http://localhost/checkout')->assertOk()->assertSee(__('Company name'));

        $this->post('http://localhost/checkout', $this->form([
            'buyer_type' => 'company', 'company_name' => 'Acme LLC', 'company_tax_id' => '12345678',
        ]))->assertRedirect();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame('Acme LLC', $order->company_name);
        $this->assertSame('12345678', $order->company_tax_id);
        $this->assertSame('Olena', $order->customer_name);
    }

    public function test_a_company_must_be_named(): void
    {
        $this->set('business_orders', true);

        $this->post('http://localhost/checkout', $this->form(['buyer_type' => 'company', 'company_name' => '']))
            ->assertSessionHasErrors('company_name');
        $this->assertSame(0, Order::count());
    }

    /** The company's fields are hidden, not removed, on the person's tab — what they still held must not make it a company's order. */
    public function test_a_private_persons_order_carries_no_company_whatever_was_typed(): void
    {
        $this->set('business_orders', true);

        $this->post('http://localhost/checkout', $this->form([
            'buyer_type' => 'person', 'company_name' => 'Left over', 'company_tax_id' => '999',
        ]))->assertRedirect();

        $order = Order::latest('id')->firstOrFail();
        $this->assertNull($order->company_name);
        $this->assertNull($order->company_tax_id);
    }

    public function test_a_shop_that_takes_no_company_orders_ignores_the_fields(): void
    {
        $this->set('business_orders', false);
        $this->get('http://localhost/checkout')->assertOk()->assertDontSee(__('Company name'));

        $this->post('http://localhost/checkout', $this->form([
            'buyer_type' => 'company', 'company_name' => 'Acme LLC',
        ]))->assertRedirect();

        $this->assertNull(Order::latest('id')->firstOrFail()->company_name);
    }

    public function test_the_postal_code_can_be_required_or_not_asked(): void
    {
        $this->set('postal_code', 'required');
        $this->post('http://localhost/checkout', $this->form(['postal_code' => '']))
            ->assertSessionHasErrors('postal_code');

        $this->set('postal_code', 'hidden');
        $this->get('http://localhost/checkout')->assertOk()->assertDontSee('name="postal_code"', false);
        $this->post('http://localhost/checkout', $this->form(['postal_code' => '99999']))->assertRedirect();
        $this->assertNull(Order::latest('id')->firstOrFail()->postal_code);
    }

    /** At checkout a coupon is announced, not drawn: the delivery step re-prices the summary. */
    public function test_a_coupon_at_checkout_asks_for_a_new_quote(): void
    {
        Coupon::create(['code' => 'CF10', 'type' => 'percent', 'value' => 10, 'enabled' => true]);

        $this->post('http://localhost/cart/coupon', ['code' => 'CF10', 'in' => 'checkout'], ['HX-Request' => 'true'])
            ->assertOk()
            ->assertHeader('HX-Trigger', 'coupon-changed');

        $this->get('http://localhost/checkout')->assertOk()->assertSee('CF10');
    }

    /** A code from a newsletter is typed however the customer types it. */
    public function test_a_coupon_code_is_read_whatever_its_case(): void
    {
        Coupon::create(['code' => 'CASE10', 'type' => 'percent', 'value' => 10, 'enabled' => true]);

        $this->post('http://localhost/cart/coupon', ['code' => ' case10 ', 'in' => 'checkout'], ['HX-Request' => 'true'])
            ->assertOk()
            ->assertHeader('HX-Trigger', 'coupon-changed');

        $this->get('http://localhost/checkout')->assertOk()->assertSee('CASE10');
    }

    /**
     * The last use of a coupon goes to ONE order (audit 2026-10-15: the limit
     * was checked before the transaction and the counter bumped without
     * asking again, so two orders at once both got it). The other order,
     * which had the coupon in hand when it started, is refused, not placed
     * at the wrong price.
     */
    public function test_the_last_use_of_a_coupon_goes_to_one_order_only(): void
    {
        $coupon = Coupon::create(['code' => 'LAST1', 'type' => 'percent', 'value' => 10, 'enabled' => true, 'usage_limit' => 1]);
        $this->post('http://localhost/cart/coupon', ['code' => 'LAST1', 'in' => 'checkout'], ['HX-Request' => 'true'])->assertOk();

        // Another order takes the last use while this one is being placed:
        // after the coupon was read, before the order's transaction.
        hook_listen('checkout.delivery.snapshot', function ($snapshot) use ($coupon) {
            Coupon::whereKey($coupon->id)->update(['used_count' => 1]);

            return $snapshot;
        });

        $this->post('http://localhost/checkout', $this->form())->assertSessionHasErrors('coupon');

        $this->assertSame(0, Order::count());
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    /** A placed order carries the coupon's TERMS, so an edit recomputes it (OrderDiscounts). */
    public function test_a_placed_order_carries_its_coupons_terms(): void
    {
        Coupon::create(['code' => 'TERMS10', 'type' => 'percent', 'value' => 10, 'enabled' => true]);
        $this->post('http://localhost/cart/coupon', ['code' => 'TERMS10', 'in' => 'checkout'], ['HX-Request' => 'true'])->assertOk();

        $this->post('http://localhost/checkout', $this->form())->assertRedirect();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame('TERMS10', $order->coupon_code);
        $this->assertSame('percent', $order->coupon_terms['type']);
        $this->assertSame(10, $order->coupon_terms['value']);
        $this->assertSame(0, $order->coupon_terms['excluded']);
        $this->assertSame((int) round($order->subtotal / 10), $order->discount_amount);
    }
}
