<?php

namespace Tests\Feature\Warehouses;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Warehouses\Models\Warehouse;

/**
 * Warehouses in the admin's common shape, and the stock screen writing only
 * what was changed — it used to write every box on the page, which put a 0 row
 * on every product shown and wiped the stock of each one not split yet.
 */
class WarehousesScreenTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    protected function product(string $name, int $stock): Product
    {
        return Product::create(['sku' => 'WH-'.Str::random(6), 'name' => ['en' => $name], 'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'price' => 10_00, 'stock' => $stock, 'status' => 'active']);
    }

    public function test_saving_the_stock_page_touches_only_the_boxes_that_changed(): void
    {
        $warehouse = Warehouse::create(['name' => ['en' => 'Side shelf'], 'sort' => 5, 'enabled' => true]);
        $untouched = $this->product('Untouched kettle', 10);
        $counted = $this->product('Counted kettle', 4);

        // Exactly what the page posts: every box, with its starting value.
        $this->actingAs($this->admin)->post("http://localhost/admin/warehouses/{$warehouse->id}/stock", [
            'qty' => [$untouched->id => '', $counted->id => '3'],
            'was' => [$untouched->id => '', $counted->id => ''],
        ])->assertRedirect();

        $this->assertSame(10, $untouched->fresh()->stock, 'a box left empty changes nothing');
        $this->assertFalse(DB::table('m_warehouse_product_stock')->where('product_id', $untouched->id)->exists());

        // The first row on a non-default warehouse keeps what the product had:
        // 4 go to the default warehouse, 3 are counted here.
        $default = Warehouse::where('is_default', true)->value('id');
        $this->assertSame(4, (int) DB::table('m_warehouse_product_stock')->where(['product_id' => $counted->id, 'warehouse_id' => $default])->value('qty'));
        $this->assertSame(7, $counted->fresh()->stock);

        // Posted again unchanged: nothing is written.
        $this->post("http://localhost/admin/warehouses/{$warehouse->id}/stock", [
            'qty' => [$counted->id => '3'], 'was' => [$counted->id => '3'],
        ])->assertSessionHas('status', 'Nothing changed.');
    }

    public function test_the_list_counts_units_and_keeps_a_warehouse_that_holds_stock(): void
    {
        $full = Warehouse::create(['name' => ['en' => 'Full depot'], 'sort' => 7, 'enabled' => true]);
        $empty = Warehouse::create(['name' => ['en' => 'Empty depot'], 'sort' => 8, 'enabled' => true]);
        $product = $this->product('Stored kettle', 0);
        DB::table('m_warehouse_product_stock')->insert(['warehouse_id' => $full->id, 'product_id' => $product->id, 'qty' => 12]);
        // A row at zero is not stock and does not keep a warehouse alive.
        DB::table('m_warehouse_product_stock')->insert(['warehouse_id' => $empty->id, 'product_id' => $product->id, 'qty' => 0]);

        $this->actingAs($this->admin)->get('http://localhost/admin/warehouses?q=depot')->assertOk()
            ->assertSeeInOrder(['Full depot', '12', 'Empty depot']);

        $this->patch("http://localhost/admin/warehouses/{$empty->id}/status", ['enabled' => 0])
            ->assertOk()->assertSee('warehouse-status-'.$empty->id, false);
        $this->assertFalse($empty->refresh()->enabled);

        $this->post('http://localhost/admin/warehouses/bulk', ['action' => 'delete', 'ids' => [$full->id, $empty->id]])
            ->assertRedirect()->assertSessionHasErrors();
        $this->assertNotNull(Warehouse::find($full->id));
        $this->assertNull(Warehouse::find($empty->id));
    }

    public function test_the_form_stays_open_after_save(): void
    {
        $warehouse = Warehouse::create(['name' => ['en' => 'Form depot'], 'sort' => 9, 'enabled' => true]);

        $this->actingAs($this->admin)->get("http://localhost/admin/warehouses/{$warehouse->id}/edit")->assertOk()
            ->assertSee('warehouse-delete', false);

        $this->put("http://localhost/admin/warehouses/{$warehouse->id}", ['name' => 'Form depot', 'enabled' => 1, 'sort' => 9])
            ->assertRedirect(route('admin.warehouses.edit', $warehouse));
        $this->put("http://localhost/admin/warehouses/{$warehouse->id}", ['name' => 'Form depot', 'sort' => 9, 'after_save' => 'close'])
            ->assertRedirect(route('admin.warehouses.index'));
    }
}
