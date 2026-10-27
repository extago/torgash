<?php

namespace Tests\Feature\Promotions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Checkout\Models\Order;
use Torgash\Promotions\Models\Promotion;

/**
 * "What did Black Friday cost us" — the question the snapshot rows have been
 * able to answer since the module existed, with no screen to ask it on.
 *
 * The headline totals what is being LOOKED AT, filter included: a number that
 * ignores the filter above it is a number nobody trusts, and this one exists to
 * be trusted with money.
 */
class PromotionUsageLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::role('admin')->firstOrFail());
    }

    public function test_the_log_totals_what_the_filter_selects(): void
    {
        $friday = Promotion::create(['name' => ['en' => 'Black Friday'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true]);
        $spring = Promotion::create(['name' => ['en' => 'Spring'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true]);

        $this->applied($friday, -30_00, 'TG-A');
        $this->applied($friday, -20_00, 'TG-B');
        $this->applied($spring, -5_00, 'TG-C');

        $all = $this->get('http://localhost/admin/promotions/usage')->assertOk();
        $all->assertSee('TG-A');
        $all->assertSee('TG-C');

        $onlyFriday = $this->get('http://localhost/admin/promotions/usage?promotion='.$friday->id)->assertOk();
        $onlyFriday->assertSee('TG-A');
        $onlyFriday->assertDontSee('TG-C');

        // 30.00 + 20.00, and NOT the 5.00 belonging to the other sale.
        $this->assertSame(50_00, $onlyFriday->viewData('given'));
        $this->assertSame(2, $onlyFriday->viewData('orders'));
        $this->assertSame(55_00, $all->viewData('given'));
    }

    /** A side-effect row (a gift, a group) is a record, not an amount. */
    public function test_a_row_with_no_money_is_listed_but_adds_nothing(): void
    {
        $promotion = Promotion::create(['name' => ['en' => 'Gifts'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true]);

        $this->applied($promotion, 0, 'TG-GIFT');

        $response = $this->get('http://localhost/admin/promotions/usage')->assertOk();

        $response->assertSee('TG-GIFT');
        $this->assertSame(0, $response->viewData('given'));
        $this->assertSame(1, $response->viewData('orders'));
    }

    /** The name is the one frozen on the order, not the promotion's current one. */
    public function test_the_log_prints_the_name_the_order_took_it_under(): void
    {
        $promotion = Promotion::create(['name' => ['en' => 'Old name'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true]);

        $this->applied($promotion, -10_00, 'TG-OLD', name: 'Old name');

        $promotion->update(['name' => ['en' => 'Renamed yesterday']]);

        $response = $this->get('http://localhost/admin/promotions/usage')->assertOk();

        // The row carries the frozen name. (The new one is on the page too —
        // in the filter's own list, where it belongs.)
        $this->assertSame('Old name', $response->viewData('rows')->first()->name);
        $response->assertSee('Old name');
    }

    public function test_the_screen_is_staff_only(): void
    {
        $customer = User::create(['name' => 'Nobody', 'email' => 'nobody-usage@example.test', 'password' => 'secret']);

        $this->actingAs($customer)
            ->get('http://localhost/admin/promotions/usage')
            ->assertForbidden();
    }

    protected function applied(Promotion $promotion, int $amount, string $orderNumber, ?string $name = null): void
    {
        $order = Order::create([
            'order_number' => $orderNumber,
            'customer_name' => 'Buyer',
            'email' => 'buyer@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'currency_code' => shop_currency(),
            'subtotal' => 100_00,
            'shipping_cost' => 0,
            'total' => 100_00 + $amount,
            'status' => 'open',
            'items' => [],
        ]);

        DB::table('m_promotions_order_promotions')->insert([
            'order_id' => $order->id,
            'promotion_id' => $promotion->id,
            'name' => $name ?? $promotion->t('name', 'en'),
            'amount' => $amount,
        ]);
    }
}
