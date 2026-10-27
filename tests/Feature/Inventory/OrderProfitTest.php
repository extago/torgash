<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\MergeOrders;
use Torgash\Checkout\Orders\OrderStock;
use Torgash\Inventory\Models\Document;
use Torgash\Inventory\Posting;
use Torgash\Inventory\Profit;
use Torgash\Warehouses\Models\Warehouse;

/**
 * What an order earned (PLAN §42, §42.5а): its goods and delivery, less what
 * the goods cost from their receipts, less its own expenses, plus its extra
 * income — on the order's card and summed over a period.
 */
class OrderProfitTest extends TestCase
{
    protected User $admin;

    protected Product $sofa;

    protected Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
        $this->actingAs($this->admin);
        Warehouse::query()->update(['is_default' => false]);
        $this->main = Warehouse::create(['name' => ['en' => 'Main'], 'is_default' => true, 'enabled' => true, 'sort' => 0]);
        $this->sofa = Product::create(['sku' => 'PR-SOFA', 'name' => ['en' => 'Sofa'], 'slug' => 'pr-sofa', 'price' => 1000_00, 'stock' => 0, 'status' => 'active']);

        $receipt = Document::create(['type' => 'receipt', 'warehouse_id' => $this->main->id, 'document_date' => now()->toDateString()]);
        $receipt->assignNumber();
        $receipt->lines()->create(['product_id' => $this->sofa->id, 'sku' => 'PR-SOFA', 'name' => 'Sofa', 'qty' => 10, 'unit_cost_original' => 600_00, 'currency' => shop_currency()]);
        app(Posting::class)->post($receipt);
    }

    public function test_the_order_card_shows_what_is_left_after_cost_expenses_and_income(): void
    {
        $order = $this->sale(1, shipping: 50_00);

        // Delivery and assembly given as a bonus; a small payment on top.
        $this->post($this->url($order), ['kind' => 'expense', 'label' => 'Delivery to the carrier', 'amount' => '120'])->assertOk();
        $this->post($this->url($order), ['kind' => 'expense', 'label' => 'Assembly', 'amount' => '80,50'])->assertOk();
        $this->post($this->url($order), ['kind' => 'income', 'label' => 'Paid on top', 'amount' => '30'])->assertOk()
            ->assertSee('Assembly');

        $line = app(Profit::class)->forOrders([$order->id])[$order->id];
        $this->assertSame(['goods' => 1000_00, 'delivery' => 50_00, 'cost' => 600_00, 'unknown' => 0, 'expenses' => 200_50, 'income' => 30_00], array_diff_key($line, ['profit' => 1]));
        $this->assertSame(1000_00 + 50_00 - 600_00 - 200_50 + 30_00, $line['profit']);

        // What the customer pays is untouched.
        $this->assertSame(1050_00, $order->fresh()->total);

        $this->get('http://localhost/admin/orders/'.$order->order_number)->assertOk()->assertSee(__('Profit of the order'));

        $row = DB::table('m_inventory_order_costs')->where('label', 'Assembly')->value('id');
        $this->delete($this->url($order).'/'.$row)->assertOk()->assertDontSee('Assembly');
    }

    public function test_units_nobody_priced_are_counted_not_costed_at_zero(): void
    {
        $cheap = Product::create(['sku' => 'PR-LAMP', 'name' => ['en' => 'Lamp'], 'slug' => 'pr-lamp', 'price' => 100_00, 'stock' => 5, 'status' => 'active']);
        $order = $this->sale(2, product: $cheap);

        $line = app(Profit::class)->forOrders([$order->id])[$order->id];
        $this->assertSame(0, $line['cost']);
        $this->assertSame(2, $line['unknown']);
    }

    public function test_a_merged_orders_cost_and_expenses_are_the_order_it_went_into(): void
    {
        $a = $this->sale(1);
        $b = $this->sale(1);
        $this->post($this->url($b), ['kind' => 'expense', 'label' => 'Packing', 'amount' => '10'])->assertOk();

        app(MergeOrders::class)->merge($a->fresh(), $b->fresh(), $this->admin);

        $line = app(Profit::class)->forOrders([$a->id])[$a->id];
        $this->assertSame(1200_00, $line['cost']);
        $this->assertSame(10_00, $line['expenses']);
    }

    public function test_the_period_adds_up_its_sales(): void
    {
        $this->sale(1);
        $second = $this->sale(2);
        $this->post($this->url($second), ['kind' => 'expense', 'label' => 'Delivery to the carrier', 'amount' => '100'])->assertOk();

        $this->get('http://localhost/admin/inventory/profit?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertSee($second->order_number)
            ->assertSee(admin_price(3000_00 - 1800_00 - 100_00))
            ->assertSee('Delivery to the carrier');
    }

    protected function sale(int $qty, int $shipping = 0, ?Product $product = null): Order
    {
        $product ??= $this->sofa;
        $order = Order::create([
            'order_number' => 'TG-PR'.random_int(10000, 99999), 'customer_name' => 'B', 'email' => 'b@example.test',
            'currency_code' => shop_currency(), 'status' => 'open', 'stock_deducted' => true,
            'subtotal' => $product->price * $qty, 'discount_amount' => 0, 'shipping_cost' => $shipping,
            'total' => $product->price * $qty + $shipping, 'items' => [],
        ]);

        $items = [['key' => (string) $product->id, 'product_id' => $product->id, 'sku' => $product->sku, 'name' => 'x', 'qty' => $qty, 'unit_price' => $product->price, 'line_total' => $product->price * $qty]];
        $order->update(['items' => app(OrderStock::class)->deductAll($items, $order)]);

        return $order->fresh();
    }

    protected function url(Order $order): string
    {
        return 'http://localhost/admin/inventory/orders/'.$order->order_number.'/costs';
    }
}
