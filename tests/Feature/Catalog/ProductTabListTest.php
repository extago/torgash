<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductTab;

/**
 * The admin's list of product tabs: ONE list in page order — the page's own
 * sections, the shop's tabs and the modules' — plus the tabs of single
 * products apart; searched, switched in place.
 */
class ProductTabListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::role('admin')->firstOrFail());
    }

    public function test_one_list_in_page_order_with_the_modules_sections(): void
    {
        ProductTab::create(['title' => ['en' => 'PTL Delivery'], 'content' => ['en' => '<p>x</p>'], 'sort' => 15, 'enabled' => true]);

        $html = $this->get('http://localhost/admin/catalog/product-tabs')->assertOk()->getContent();

        // Description 10 → our tab 15 → Characteristics 20 → Reviews 30 (the module's).
        $at = fn (string $text) => strpos($html, $text);
        $this->assertNotFalse($at('PTL Delivery'));
        $this->assertLessThan($at('PTL Delivery'), $at('>Description<'));
        $this->assertGreaterThan($at('PTL Delivery'), $at('>Characteristics<'));
        $this->assertGreaterThan($at('>Characteristics<'), $at('Module: Reviews'));
    }

    public function test_search_and_the_single_products_list(): void
    {
        $product = Product::create(['sku' => 'PTL-1', 'name' => ['en' => 'PTL Kettle'], 'slug' => 'ptl-kettle', 'price' => 100, 'status' => 'active']);
        ProductTab::create(['title' => ['en' => 'PTL Warranty'], 'content' => ['en' => '<p>x</p>'], 'sort' => 40, 'enabled' => true]);
        ProductTab::create(['title' => ['en' => 'PTL Manual'], 'content' => ['en' => '<p>x</p>'], 'sort' => 40, 'enabled' => true, 'product_id' => $product->id]);

        $this->get('http://localhost/admin/catalog/product-tabs?q=warranty')->assertOk()
            ->assertSee('PTL Warranty')->assertDontSee('PTL Manual');

        $this->get('http://localhost/admin/catalog/product-tabs')->assertOk()
            ->assertSee('PTL Manual')->assertSee('PTL Kettle');
    }

    /** A named section with no row yet switches from the list too: the click makes its row. */
    public function test_a_section_without_a_row_switches_in_place(): void
    {
        $this->assertNull(ProductTab::where('section', 'reviews')->first());

        $this->patch('http://localhost/admin/catalog/product-tabs/section/reviews/status', ['enabled' => 0, 'cell' => 'product-section-status-reviews'])
            ->assertOk()
            ->assertSee('id="product-section-status-reviews"', false);

        $row = ProductTab::where('section', 'reviews')->whereNull('product_id')->firstOrFail();
        $this->assertFalse($row->enabled);
        $this->assertSame(30, (int) $row->sort, 'made at the usual position');

        // The second click reaches the row it made, and adds no other.
        $this->patch('http://localhost/admin/catalog/product-tabs/section/reviews/status', ['enabled' => 1])->assertOk();
        $this->assertSame(1, ProductTab::where('section', 'reviews')->count());
        $this->assertTrue($row->fresh()->enabled);

        $this->patch('http://localhost/admin/catalog/product-tabs/section/nobody/status', ['enabled' => 0])->assertNotFound();
    }

    public function test_a_row_switches_in_place_and_the_form_closes_on_request(): void
    {
        $tab = ProductTab::create(['title' => ['en' => 'PTL Switch'], 'content' => ['en' => '<p>x</p>'], 'sort' => 40, 'enabled' => true]);

        $this->patch('http://localhost/admin/catalog/product-tabs/'.$tab->id.'/status', ['enabled' => 0])
            ->assertOk()->assertSee('id="product-tab-status-'.$tab->id.'"', false);
        $this->assertFalse($tab->fresh()->enabled);

        $this->put('http://localhost/admin/catalog/product-tabs/'.$tab->id, [
            'title' => 'PTL Switch', 'content' => '<p>x</p>', 'sort' => 40, 'enabled' => '1', 'after_save' => 'close',
        ])->assertRedirect(route('admin.catalog.product-tabs.index'));

        $this->put('http://localhost/admin/catalog/product-tabs/'.$tab->id, [
            'title' => 'PTL Switch', 'content' => '<p>x</p>', 'sort' => 40, 'enabled' => '1',
        ])->assertRedirect(route('admin.catalog.product-tabs.edit', $tab));
    }
}
