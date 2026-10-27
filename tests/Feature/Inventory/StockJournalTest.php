<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\OrderStatusChanger;
use Torgash\Checkout\Orders\OrderStock;
use Torgash\Inventory\Models\Move;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Warehouses\Models\Warehouse;
use Torgash\Warehouses\StockAllocator;

/**
 * Every movement of stock is written down with its cause (PLAN §42), from the
 * places the number really changes — whoever caused it.
 */
class StockJournalTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::role('admin')->firstOrFail();
    }

    public function test_an_order_takes_stock_and_a_cancellation_gives_it_back_both_written_down(): void
    {
        $product = Product::create(['sku' => 'SJ-1', 'name' => ['en' => 'Lamp'], 'slug' => 'sj-lamp', 'price' => 50_00, 'stock' => 10, 'status' => 'active']);
        $this->assertSame([10], $this->moves($product)->pluck('delta')->all(), 'a new product with stock is a movement from nothing');

        $this->post('http://localhost/cart/add/sj-lamp', ['qty' => 3]);
        $this->post('http://localhost/checkout', [
            'customer_name' => 'Buyer', 'email' => 'buyer@example.test', 'address_line' => '1 Street', 'city' => 'Town',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true, 'position' => -1])->id,
        ])->assertSessionHasNoErrors();
        $order = Order::latest('id')->firstOrFail();

        $sold = $this->moves($product)->last();
        $this->assertSame(-3, $sold->delta);
        $this->assertSame(7, $sold->after);
        $this->assertSame('order', $sold->type);
        $this->assertSame($order->id, $sold->order_id);

        app(OrderStatusChanger::class)->change($order->fresh(), OrderStatus::findOrFail('cancelled'));

        $back = $this->moves($product)->last();
        $this->assertSame([3, 10, 'order', $order->id], [$back->delta, $back->after, $back->type, $back->order_id]);
    }

    public function test_a_return_is_a_return_though_it_goes_through_the_orders_own_code(): void
    {
        $product = Product::create(['sku' => 'SJ-2', 'name' => ['en' => 'Chair'], 'slug' => 'sj-chair', 'price' => 50_00, 'stock' => 5, 'status' => 'active']);
        $order = Order::create([
            'order_number' => 'TG-SJ'.random_int(1000, 9999), 'customer_name' => 'B', 'email' => 'b@example.test',
            'currency_code' => shop_currency(), 'status' => 'open', 'stock_deducted' => true,
            'subtotal' => 50_00, 'shipping_cost' => 0, 'total' => 50_00,
            'items' => [['key' => 'k', 'product_id' => $product->id, 'sku' => 'SJ-2', 'name' => 'Chair', 'qty' => 1, 'unit_price' => 50_00, 'line_total' => 50_00]],
        ]);

        app(\Torgash\Catalog\Stock\StockMoves::class)->because(['type' => 'return', 'order_id' => $order->id, 'return_id' => 9],
            fn () => app(OrderStock::class)->restore($order, $order->items));

        $move = $this->moves($product)->last();
        $this->assertSame(['return', 1, 9], [$move->type, $move->delta, $move->ref['return_id']]);
    }

    public function test_a_number_typed_on_the_product_or_on_the_stock_screen_is_an_edit_by_that_person(): void
    {
        $product = Product::create(['sku' => 'SJ-3', 'name' => ['en' => 'Desk'], 'slug' => 'sj-desk', 'price' => 50_00, 'stock' => 4, 'status' => 'active']);

        $this->actingAs($this->admin);
        $product->update(['stock' => 6]);

        $edit = $this->moves($product)->last();
        $this->assertSame([2, 6, 'edit', $this->admin->name], [$edit->delta, $edit->after, $edit->type, $edit->user_name]);

        // The stock screen splits it into warehouses: the product's own six
        // go to the default warehouse, then the typed number is set.
        $home = Warehouse::where('is_default', true)->first() ?? Warehouse::create(['name' => ['en' => 'Main'], 'is_default' => true, 'enabled' => true]);
        $this->post('http://localhost/admin/warehouses/'.$home->id.'/stock', ['qty' => [$product->id => '9'], 'was' => [$product->id => '']])
            ->assertRedirect();

        $tail = $this->moves($product)->slice(-3)->values();
        $this->assertSame(
            [['split', null, -6], ['split', $home->id, 6], ['edit', $home->id, 3]],
            $tail->map(fn (Move $m) => [$m->type, $m->warehouse_id, $m->delta])->all(),
        );
        $this->assertSame(9, $product->fresh()->stock);
    }

    public function test_the_journal_adds_up_to_the_stock(): void
    {
        $product = Product::create(['sku' => 'SJ-4', 'name' => ['en' => 'Shelf'], 'slug' => 'sj-shelf', 'price' => 50_00, 'stock' => 0, 'status' => 'active']);
        $home = Warehouse::where('is_default', true)->first() ?? Warehouse::create(['name' => ['en' => 'Main'], 'is_default' => true, 'enabled' => true]);
        $allocator = app(StockAllocator::class);

        $allocator->move($product->id, $home->id, 12);
        $allocator->move($product->id, $home->id, -5);
        $product->fresh()->adjustStock(-2);

        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(5, (int) DB::table('m_inventory_moves')->where('product_id', $product->id)->sum('delta'));
    }

    public function test_the_journal_screen_lists_and_narrows(): void
    {
        $product = Product::create(['sku' => 'SJ-5', 'name' => ['en' => 'Rug'], 'slug' => 'sj-rug', 'price' => 50_00, 'stock' => 3, 'status' => 'active']);

        $this->actingAs($this->admin)->get('http://localhost/admin/inventory/moves?product='.$product->id)
            ->assertOk()->assertSee('SJ-5');
        $this->get('http://localhost/admin/inventory/moves?type=return')->assertOk()->assertDontSee('SJ-5');
        $this->get('http://localhost/admin/catalog/products/'.$product->id.'/edit')->assertOk()->assertSee(__('Latest movements'));
    }

    protected function moves(Product $product)
    {
        return Move::where('product_id', $product->id)->orderBy('id')->get();
    }
}
