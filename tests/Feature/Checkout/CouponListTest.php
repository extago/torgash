<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Tests\TestCase;
use Torgash\Checkout\Models\Coupon;
use Torgash\Checkout\Models\Order;

/**
 * The coupons list: tabs by the computed state, search, the switch in place,
 * several at once, the orders a coupon was used in; and a customer's code is
 * read whatever its case.
 */
class CouponListTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    protected function coupon(array $attributes = []): Coupon
    {
        return Coupon::create($attributes + ['code' => 'C'.strtoupper(substr(md5(uniqid()), 0, 6)), 'type' => 'percent', 'value' => 10, 'enabled' => true]);
    }

    public function test_the_state_is_what_a_customer_would_meet(): void
    {
        $this->assertSame('active', $this->coupon()->state());
        $this->assertSame('off', $this->coupon(['enabled' => false])->state());
        $this->assertSame('expired', $this->coupon(['expires_at' => now()->subDay()])->state());
        $this->assertSame('used_up', $this->coupon(['usage_limit' => 2, 'used_count' => 2])->state());
        $this->assertSame('scheduled', $this->coupon(['starts_at' => now()->addDay()])->state());
    }

    public function test_tabs_and_search(): void
    {
        $this->coupon(['code' => 'ZEBRA1']);
        $this->coupon(['code' => 'ZEBRA2', 'expires_at' => now()->subDay()]);

        $this->actingAs($this->admin)
            ->get('http://localhost/admin/checkout/coupons?q=zebra&state=expired')
            ->assertOk()->assertSee('ZEBRA2')->assertDontSee('ZEBRA1');
    }

    public function test_the_switch_in_place_and_in_bulk(): void
    {
        $a = $this->coupon();
        $b = $this->coupon();

        $this->actingAs($this->admin)
            ->patch("http://localhost/admin/checkout/coupons/{$a->id}/status", ['enabled' => 0])
            ->assertOk()->assertSee('coupon-status-'.$a->id, false);
        $this->assertFalse($a->refresh()->enabled);

        $this->post('http://localhost/admin/checkout/coupons/bulk', ['action' => 'delete', 'ids' => [$a->id, $b->id]]);
        $this->assertNull($b->fresh());
    }

    public function test_the_orders_list_narrows_to_a_coupon(): void
    {
        $base = ['customer_name' => 'Buyer', 'email' => 'b@example.test', 'subtotal' => 100, 'total' => 100, 'items' => [], 'status' => 'new'];
        Order::create($base + ['order_number' => 'TG-CPN1', 'coupon_code' => 'SPRING']);
        Order::create($base + ['order_number' => 'TG-CPN2', 'coupon_code' => 'AUTUMN']);

        $this->actingAs($this->admin)
            ->get('http://localhost/admin/orders?coupon=spring')
            ->assertOk()->assertSee('TG-CPN1')->assertDontSee('TG-CPN2');
    }

    public function test_expiry_includes_its_day_and_saving_stays_on_the_form(): void
    {
        $this->actingAs($this->admin)
            ->post('http://localhost/admin/checkout/coupons', ['code' => 'lastday', 'type' => 'fixed', 'value' => '5', 'expires_at' => now()->toDateString(), 'enabled' => 1])
            ->assertRedirect();

        $coupon = Coupon::where('code', 'LASTDAY')->firstOrFail();
        $this->assertSame(500, $coupon->value);
        $this->assertSame('active', $coupon->state());

        $this->put("http://localhost/admin/checkout/coupons/{$coupon->id}", ['code' => 'LASTDAY', 'type' => 'fixed', 'value' => '5', 'after_save' => 'close'])
            ->assertRedirect(route('admin.checkout.coupons.index'));
    }
}
