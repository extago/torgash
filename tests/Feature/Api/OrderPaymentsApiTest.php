<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Api\Models\ApiToken;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\MergeOrders;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Payments\Models\Payment;

/**
 * An order's payments in the admin API (PLAN §40.10/4): each on its own, the
 * payments of an order merged into it included — and a page of orders costs
 * the same number of queries whatever it holds, because the page is read in
 * one go when the API announces it.
 */
class OrderPaymentsApiTest extends TestCase
{
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->token] = ApiToken::issue(User::role('admin')->firstOrFail(), 'Tests');
    }

    public function test_each_payment_is_listed_with_the_merged_orders_own(): void
    {
        $a = $this->order(['deposit_amount' => 20_00]);
        $this->payment($a, 'inv-dep', 20_00);
        $this->payment($a, 'inv-rest', 30_00);
        $b = $this->order();
        $this->payment($b, 'inv-b', 100_00);

        app(MergeOrders::class)->merge($a, $b, User::role('admin')->firstOrFail());

        $payments = collect($this->api('/api/v2/admin/orders?sort=-id')->json('data'))
            ->firstWhere('id', $a->id)['payment']['payments'];

        $this->assertSame(['inv-dep', 'inv-rest', 'inv-b'], array_column($payments, 'external_id'));
        $this->assertSame([null, null, $b->id], array_column($payments, 'order_id'));
        $this->assertSame(150_00, array_sum(array_column($payments, 'held')));

        // One order on its own says the same.
        $this->assertSame($payments, $this->api('/api/v2/admin/orders/'.$a->id)->json('data.payment.payments'));
    }

    public function test_a_page_of_orders_costs_the_same_whatever_it_holds(): void
    {
        foreach (range(1, 3) as $n) {
            $this->payment($this->order(), 'few-'.$n, 10_00);
        }
        $this->api('/api/v2/admin/orders?per_page=50'); // warm the per-request memos

        $few = $this->queries(fn () => $this->api('/api/v2/admin/orders?per_page=50'));

        foreach (range(1, 9) as $n) {
            $this->payment($this->order(), 'many-'.$n, 10_00);
        }
        // Warm again: creating orders may drop a cache the first request then
        // refills once — that is not a query per order, and must not read as one.
        $this->api('/api/v2/admin/orders?per_page=50');
        $many = $this->queries(fn () => $this->api('/api/v2/admin/orders?per_page=50'));

        $this->assertSame($few, $many, 'no query per order');
    }

    protected function queries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    protected function api(string $path)
    {
        return $this->getJson('http://localhost'.$path, ['Authorization' => 'Bearer '.$this->token])->assertOk();
    }

    protected function payment(Order $order, string $id, int $amount): void
    {
        Payment::create(['order_id' => $order->id, 'gateway' => 'fake', 'external_id' => $id, 'state' => PaymentState::PAID, 'requested' => $amount, 'amount' => $amount]);
    }

    protected function order(array $attributes = []): Order
    {
        return Order::create($attributes + [
            'order_number' => 'TG-AP'.random_int(100000, 999999),
            'customer_name' => 'Buyer', 'email' => 'buyer@example.test', 'address_line' => '1 Street', 'city' => 'Town',
            'currency_code' => shop_currency(), 'status' => 'open', 'stock_deducted' => false,
            'subtotal' => 100_00, 'shipping_cost' => 0, 'discount_amount' => 0, 'total' => 100_00,
            'items' => [['key' => 'x', 'sku' => 'X', 'name' => 'Thing', 'qty' => 1, 'unit_price' => 100_00, 'line_total' => 100_00]],
        ])->fresh();
    }
}
