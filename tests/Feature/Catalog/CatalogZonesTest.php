<?php

namespace Tests\Feature\Catalog;

use App\Core\Zones\ZoneRegistry;
use Tests\TestCase;
use Torgash\Blocks\Models\Block;
use Torgash\Blocks\ZoneRenderer;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;

/**
 * The shop's own blocks on category and product pages (PLAN §30.15): the
 * zones exist, they are OUTSIDE the filter form, and "the category being
 * viewed" is a display rule that takes the subtree.
 */
class CatalogZonesTest extends TestCase
{
    public function test_the_zones_are_offered_to_the_admin(): void
    {
        $registry = app(ZoneRegistry::class);

        foreach (['catalog.category.top', 'catalog.category.bottom', 'catalog.product.top', 'catalog.product.before_details', 'catalog.product.bottom'] as $zone) {
            $this->assertTrue($registry->has($zone), $zone);
        }
    }

    /** A banner for "Clothing" shows on Clothing and under it, and nowhere else — the catalogue root included. */
    public function test_a_block_follows_the_category_being_viewed(): void
    {
        $clothing = Category::create(['name' => ['en' => 'Zone clothing'], 'slug' => 'zone-clothing', 'enabled' => true]);
        $shirts = Category::create(['name' => ['en' => 'Zone shirts'], 'slug' => 'zone-shirts', 'enabled' => true, 'parent_id' => $clothing->id]);
        $tools = Category::create(['name' => ['en' => 'Zone tools'], 'slug' => 'zone-tools', 'enabled' => true]);

        $this->block('catalog.category.top', 'CLOTHING-BANNER', [['kind' => 'listing_category', 'operator' => 'in', 'value' => [$clothing->id]]]);

        $this->get(route('catalog.category', 'zone-clothing'))->assertOk()->assertSee('CLOTHING-BANNER');
        $this->get(route('catalog.category', 'zone-shirts'))->assertOk()->assertSee('CLOTHING-BANNER');
        $this->get(route('catalog.category', 'zone-tools'))->assertOk()->assertDontSee('CLOTHING-BANNER');
        $this->get('http://localhost/catalog')->assertOk()->assertDontSee('CLOTHING-BANNER');
    }

    /** Never inside the filter form: a block's own form (a buy button) would be a nested one. */
    public function test_the_category_zones_stand_outside_the_filter_form(): void
    {
        Category::create(['name' => ['en' => 'Zone lamps'], 'slug' => 'zone-lamps', 'enabled' => true]);
        $this->block('catalog.category.top', 'TOP-OF-LIST');
        $this->block('catalog.category.bottom', 'BOTTOM-OF-LIST');

        $html = $this->get(route('catalog.category', 'zone-lamps'))->assertOk()->getContent();
        $formStart = strpos($html, 'data-catalog-filters');
        $formEnd = strpos($html, '</form>', $formStart);

        $this->assertLessThan($formStart, strpos($html, 'TOP-OF-LIST'));
        $this->assertGreaterThan($formEnd, strpos($html, 'BOTTOM-OF-LIST'));
    }

    public function test_product_zones_answer_about_the_product(): void
    {
        $kettles = Category::create(['name' => ['en' => 'Zone kettles'], 'slug' => 'zone-kettles', 'enabled' => true]);
        $product = Product::create(['sku' => 'ZN-1', 'name' => ['en' => 'Zone kettle'], 'slug' => 'zn-1', 'price' => 100_00, 'stock' => 5, 'status' => 'active']);
        $product->categories()->attach($kettles->id);
        Product::create(['sku' => 'ZN-2', 'name' => ['en' => 'Zone lamp'], 'slug' => 'zn-2', 'price' => 100_00, 'stock' => 5, 'status' => 'active']);

        $this->block('catalog.product.top', 'KETTLE-NOTE', [['kind' => 'product_category', 'operator' => 'in', 'value' => [$kettles->id]]]);
        $this->block('catalog.product.bottom', 'EVERY-PRODUCT-FOOTER');

        $this->get(route('catalog.product', 'zn-1'))->assertOk()->assertSee('KETTLE-NOTE')->assertSee('EVERY-PRODUCT-FOOTER');
        $this->get(route('catalog.product', 'zn-2'))->assertOk()->assertDontSee('KETTLE-NOTE')->assertSee('EVERY-PRODUCT-FOOTER');
    }

    /**
     * The blocks module subscribes to zones once, at boot, from the blocks
     * that exist then — so a block made inside a test is subscribed here the
     * same way (see DisplayRulesTest for why the app is not refreshed instead:
     * it would drop the transaction these rows live in).
     */
    protected function block(string $zone, string $text, array $rules = []): Block
    {
        $block = Block::create([
            'zone' => $zone, 'type' => 'html', 'title' => $text,
            'settings' => ['html' => '<p>'.$text.'</p>'],
            'enabled' => true, 'sort' => 1, 'rules' => $rules, 'rules_match' => 'all',
        ]);

        hook_listen($zone, fn (string $value, array $context) => $value.app(ZoneRenderer::class)->render(collect([$block]), $context));

        return $block;
    }
}
