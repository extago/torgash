<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Checkout\Models\Order;

/**
 * The admin's list of orders costs the same whatever a page holds — the
 * guard `QueryBudgetTest` is for the catalogue, here for the orders screen,
 * which every module decorates (facts, badges, managers).
 */
class OrderListQueryBudgetTest extends TestCase
{
    public function test_a_page_of_orders_costs_the_same_at_three_as_at_twelve(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $manager = User::factory()->create();

        foreach (range(1, 3) as $n) {
            $this->order($manager);
        }
        $this->actingAs($admin)->get('http://localhost/admin/orders')->assertOk(); // warm the memos

        $few = $this->queries(fn () => $this->get('http://localhost/admin/orders')->assertOk());

        foreach (range(1, 9) as $n) {
            $last = $this->order($manager);
        }
        $many = $this->queries(fn () => $this->get('http://localhost/admin/orders')->assertOk()->assertSee($last->order_number));

        $this->assertSame($few, $many, 'no query per order: '.$this->dump);
    }

    protected string $dump = '';

    protected function queries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $log = DB::getQueryLog();
        DB::disableQueryLog();
        $this->dump = implode("\n", array_map(fn ($q) => $q['query'], $log));

        return count($log);
    }

    protected function order(User $manager): Order
    {
        return Order::create([
            'order_number' => 'TG-QB'.random_int(100000, 999999),
            'user_id' => User::factory()->create()->id,
            'manager_id' => $manager->id,
            'customer_name' => 'Buyer', 'email' => 'buyer@example.test', 'address_line' => '1 Street', 'city' => 'Town',
            'currency_code' => shop_currency(), 'status' => 'open', 'stock_deducted' => false,
            'subtotal' => 100_00, 'shipping_cost' => 0, 'discount_amount' => 0, 'total' => 100_00,
            'payment_method_name' => 'Cash', 'shipping_method_name' => 'Courier',
            'items' => [['key' => 'x', 'sku' => 'X', 'name' => 'Thing', 'qty' => 1, 'unit_price' => 100_00, 'line_total' => 100_00]],
        ]);
    }
}
