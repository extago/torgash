<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;

/**
 * A product form left open for a week must not put back last week's numbers:
 * stock an order has taken since, views and sales counted since. The form
 * says what it showed (`seen[…]`), and a number equal to that is not written.
 */
class StaleProductFormTest extends TestCase
{
    protected function form(Product $product, array $overrides = []): array
    {
        return $overrides + [
            'sku' => $product->sku, 'name' => 'Stale', 'price' => '10', 'status' => 'active',
            'stock' => '40', 'popularity' => '7', 'views_count' => '100', 'sales_count' => '3',
            'seen' => ['stock' => 40, 'popularity' => 7, 'views_count' => 100, 'sales_count' => 3],
        ];
    }

    protected function product(): Product
    {
        $this->actingAs(User::role('admin')->firstOrFail());

        return Product::create([
            'sku' => 'STALE-1', 'name' => ['en' => 'Stale'], 'slug' => 'stale-1', 'price' => 10_00,
            'stock' => 40, 'popularity' => 7, 'views_count' => 100, 'sales_count' => 3, 'status' => 'active',
        ]);
    }

    public function test_what_the_shop_moved_meanwhile_survives_a_save_that_did_not_touch_it(): void
    {
        $product = $this->product();

        // A week passes: an order takes three, visitors look, one more sale.
        DB::table('m_catalog_products')->where('id', $product->id)
            ->update(['stock' => 37, 'views_count' => 180, 'sales_count' => 6, 'popularity' => 12]);

        $this->put('http://localhost/admin/catalog/products/'.$product->id, $this->form($product))->assertRedirect();

        $fresh = $product->fresh();
        $this->assertSame([37, 180, 6, 12], [$fresh->stock, $fresh->views_count, $fresh->sales_count, $fresh->popularity]);
        $this->assertSame('Stale', $fresh->name['en']);
    }

    public function test_a_number_the_admin_changed_is_written_as_typed(): void
    {
        $product = $this->product();
        DB::table('m_catalog_products')->where('id', $product->id)->update(['stock' => 37]);

        $this->put('http://localhost/admin/catalog/products/'.$product->id, $this->form($product, ['stock' => '50', 'popularity' => '0']))
            ->assertRedirect();

        $this->assertSame(50, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->popularity);
    }
}
