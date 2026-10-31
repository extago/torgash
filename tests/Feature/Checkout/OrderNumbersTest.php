<?php

namespace Tests\Feature\Checkout;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\OrderNumbers;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * An order's number comes from a sequence of its own, written with the
 * shop's prefix and padding, and the shop may say what the next one is —
 * never one that was issued already. A sequence is not rolled back with the
 * test's transaction, so every assertion here is relative to the last.
 */
class OrderNumbersTest extends TestCase
{
    protected PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cash = PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true, 'position' => -1]);
        Product::create(['sku' => 'ON-1', 'name' => ['en' => 'Lamp'], 'slug' => 'on-lamp', 'price' => 10_00, 'stock' => 50, 'status' => 'active']);
    }

    public function test_numbers_follow_the_sequence_in_the_shops_format(): void
    {
        $numbers = app(OrderNumbers::class);
        $first = $this->buy();
        $this->assertMatchesRegularExpression('/^TG-\d{6}$/', $first->order_number);
        $this->assertSame($numbers->last(), (int) substr($first->order_number, 3));

        $second = $this->buy();
        $this->assertSame((int) substr($first->order_number, 3) + 1, (int) substr($second->order_number, 3));

        $settings = app(SettingsManager::class);
        $settings->set('torgash.checkout', 'order_number_prefix', 'SHOP/');
        $settings->set('torgash.checkout', 'order_number_digits', 0);

        $third = $this->buy();
        $this->assertSame('SHOP/'.((int) substr($second->order_number, 3) + 1), $third->order_number);
        // The ones already given keep their name.
        $this->assertSame($first->order_number, $first->fresh()->order_number);
    }

    public function test_the_shop_says_what_the_next_number_is_but_never_one_already_issued(): void
    {
        $numbers = app(OrderNumbers::class);
        $this->buy();
        $jump = $numbers->last() + 1000;

        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/settings/torgash.checkout', $this->settingsForm(['order_number_next' => $jump]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('TG-'.str_pad((string) $jump, 6, '0', STR_PAD_LEFT), $this->buy()->order_number);
        $this->assertSame('TG-'.str_pad((string) ($jump + 1), 6, '0', STR_PAD_LEFT), $this->buy()->order_number);

        // Below the last one issued: refused, and nothing moves.
        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/settings/torgash.checkout', $this->settingsForm(['order_number_next' => $jump - 5]))
            ->assertRedirect()->assertSessionHasErrors('order_number_next');
        $this->assertSame((int) $jump + 1, $numbers->last());

        // The number typed before stays on the form; saving the page again
        // with it unchanged is not a second jump and not a refusal.
        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/settings/torgash.checkout', $this->settingsForm(['order_number_next' => $jump]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('TG-'.str_pad((string) ($jump + 2), 6, '0', STR_PAD_LEFT), $this->buy()->order_number);
    }

    public function test_the_settings_page_says_the_last_number_issued(): void
    {
        $order = $this->buy();

        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/settings/torgash.checkout')
            ->assertOk()->assertSee($order->order_number);
    }

    /** The settings form carries every field; these are the ones that matter here. */
    protected function settingsForm(array $with): array
    {
        // $with first: in a union of arrays the LEFT key wins.
        return $with + ['order_number_prefix' => 'TG-', 'order_number_digits' => 6, 'order_number_next' => 0];
    }

    protected function buy(): Order
    {
        $customer = User::factory()->create();
        $this->actingAs($customer)->post('http://localhost/cart/add/on-lamp', ['qty' => 1]);
        $this->actingAs($customer)->post('http://localhost/checkout', [
            'customer_name' => 'Buyer', 'email' => $customer->email,
            'address_line' => '1 Street', 'city' => 'Town',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => $this->cash->id,
        ])->assertSessionHasNoErrors();

        return Order::latest('id')->firstOrFail();
    }
}
