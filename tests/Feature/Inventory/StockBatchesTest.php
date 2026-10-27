<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Stock\StockMoves;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\OrderStock;
use Torgash\Inventory\Models\Document;
use Torgash\Inventory\Posting;
use Torgash\Warehouses\Models\Warehouse;

/**
 * Batches at their purchase price, taken oldest first (PLAN §42): what a sale
 * cost is worked out from the receipts behind it, a return goes back into the
 * batch it left, a transfer carries its prices, and units nobody priced say
 * so.
 */
class StockBatchesTest extends TestCase
{
    protected User $admin;

    protected Warehouse $main;

    protected Warehouse $shop;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
        Warehouse::query()->update(['is_default' => false]);
        $this->main = Warehouse::create(['name' => ['en' => 'Main'], 'is_default' => true, 'enabled' => true, 'sort' => 0]);
        $this->shop = Warehouse::create(['name' => ['en' => 'Shop'], 'enabled' => true, 'sort' => 1]);
        $this->product = Product::create(['sku' => 'BT-CHAIR', 'name' => ['en' => 'Chair'], 'slug' => 'bt-chair', 'price' => 300_00, 'stock' => 0, 'status' => 'active']);
    }

    public function test_a_sale_costs_what_its_oldest_receipts_cost(): void
    {
        $this->receive(5, 100_00);
        $this->receive(5, 120_00);

        $order = $this->order(7);

        $this->assertSame(5 * 100_00 + 2 * 120_00, $this->costOf($order));
        $this->assertSame([0, 3], DB::table('m_inventory_batches')->where('product_id', $this->product->id)->orderBy('id')->pluck('remaining')->map('intval')->all());
    }

    public function test_a_return_goes_back_into_the_batch_it_left(): void
    {
        $this->receive(5, 100_00);
        $this->receive(5, 120_00);
        $order = $this->order(7);

        // One comes back: the last one taken, the 120 one.
        app(StockMoves::class)->because(['type' => 'return', 'order_id' => $order->id],
            fn () => app(OrderStock::class)->restore($order->fresh(), [['product_id' => $this->product->id, 'qty' => 1]], $this->main->id));

        $this->assertSame(5 * 100_00 + 1 * 120_00, $this->costOf($order));
        $this->assertSame([0, 4], DB::table('m_inventory_batches')->where('product_id', $this->product->id)->orderBy('id')->pluck('remaining')->map('intval')->all());
    }

    public function test_a_transfer_carries_its_prices_and_what_nobody_priced_says_so(): void
    {
        // Two units typed in by hand before any receipt: unknown price, and
        // the oldest, so they go first.
        $this->actingAs($this->admin);
        $this->product->update(['stock' => 2]);
        $this->receive(3, 100_00);

        $transfer = $this->document('transfer', 4, null, ['to_warehouse_id' => $this->shop->id]);
        app(Posting::class)->post($transfer);

        $atShop = DB::table('m_inventory_batches')->where('product_id', $this->product->id)->where('warehouse_id', $this->shop->id)->orderBy('id')->get();
        $this->assertSame([[2, null], [2, 10000]], $atShop->map(fn ($b) => [(int) $b->qty, $b->unit_cost === null ? null : (int) $b->unit_cost])->all());

        // Three sold: the one left at Main (a 100 unit, the unknown ones having
        // gone to the shop) and two from the shop — the two unknown ones.
        $order = $this->order(3);
        $costs = DB::table('m_inventory_move_costs as c')->join('m_inventory_moves as m', 'm.id', '=', 'c.move_id')
            ->where('m.order_id', $order->id)->get(['c.qty', 'c.unit_cost']);
        $this->assertSame(2, (int) $costs->whereNull('unit_cost')->sum('qty'), 'two units of unknown cost');
        $this->assertSame(100_00, (int) $costs->whereNotNull('unit_cost')->sum(fn ($c) => $c->qty * $c->unit_cost));
    }

    protected function receive(int $qty, int $unitCost): void
    {
        app(Posting::class)->post($this->document('receipt', $qty, $unitCost));
    }

    protected function document(string $type, int $qty, ?int $unitCost = null, array $attributes = []): Document
    {
        $document = Document::create($attributes + ['type' => $type, 'warehouse_id' => $this->main->id, 'document_date' => now()->toDateString()]);
        $document->assignNumber();
        $document->lines()->create([
            'product_id' => $this->product->id, 'sku' => $this->product->sku, 'name' => 'Chair', 'qty' => $qty,
            'unit_cost_original' => $unitCost, 'currency' => $unitCost ? shop_currency() : null,
        ]);

        return $document;
    }

    protected function order(int $qty): Order
    {
        $order = Order::create([
            'order_number' => 'TG-BT'.random_int(10000, 99999), 'customer_name' => 'B', 'email' => 'b@example.test',
            'currency_code' => shop_currency(), 'status' => 'open', 'stock_deducted' => true,
            'subtotal' => 300_00 * $qty, 'shipping_cost' => 0, 'total' => 300_00 * $qty, 'items' => [],
        ]);

        $items = [['key' => 'c', 'product_id' => $this->product->id, 'sku' => 'BT-CHAIR', 'name' => 'Chair', 'qty' => $qty, 'unit_price' => 300_00, 'line_total' => 300_00 * $qty]];
        $order->update(['items' => app(OrderStock::class)->deductAll($items, $order)]);

        return $order;
    }

    protected function costOf(Order $order): int
    {
        return (int) DB::table('m_inventory_move_costs as c')->join('m_inventory_moves as m', 'm.id', '=', 'c.move_id')
            ->where('m.order_id', $order->id)
            ->selectRaw('coalesce(sum(case when m.delta < 0 then c.qty * c.unit_cost else -c.qty * c.unit_cost end), 0) as cost')
            ->value('cost');
    }
}
