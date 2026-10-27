<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use Tests\TestCase;
use Torgash\Catalog\Filters\FilterQuery;
use Torgash\Catalog\Filters\Flags;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Promotions\Models\Promotion;
use Torgash\Promotions\Models\PromotionTier;

/**
 * The yes/no filters, from the shop's question: "there is only In stock — how
 * do I turn it off, and how do I add one for discounted products?"
 *
 * Neither was possible, and both for the same reason: the box was markup. The
 * properties worth pinning are the three the registry bought — the shop
 * chooses, a module can add one, and a filter nobody can see may not still be
 * filtering.
 */
class CatalogFilterFlagsTest extends TestCase
{
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => ['en' => 'Flagged'], 'slug' => 'flagged', 'enabled' => true,
        ]);
    }

    /** What the panel did before any of this, unchanged: the box is there and it narrows. */
    public function test_in_stock_is_offered_by_default_and_narrows_the_grid(): void
    {
        $this->product('FLG-1', 'Has stock', stock: 4);
        $this->product('FLG-2', 'None left', stock: 0);

        $this->get('http://localhost/catalog/flagged')->assertOk()
            ->assertSee('name="in_stock"', false)
            ->assertSee('None left');

        $this->get('http://localhost/catalog/flagged?in_stock=1')->assertOk()
            ->assertSee('Has stock')
            ->assertDontSee('None left');
    }

    /**
     * Turning it off has to turn off the FILTERING too, not just the box.
     *
     * A control the panel no longer draws is a filter with no way off it: the
     * visitor cannot untick what is not on the screen, and the address stays in
     * their history and in other people's links.
     */
    public function test_a_flag_the_shop_has_switched_off_filters_nothing(): void
    {
        $this->product('FLG-3', 'Has stock', stock: 4);
        $this->product('FLG-4', 'None left', stock: 0);

        $this->offer([]);

        $this->get('http://localhost/catalog/flagged?in_stock=1')->assertOk()
            ->assertDontSee('name="in_stock"', false)
            ->assertSee('Has stock')
            ->assertSee('None left');
    }

    /** The one the shop asked for: cheaper than its recommended price. */
    public function test_on_sale_selects_a_product_priced_below_its_rrp(): void
    {
        $this->offer(['on_sale']);

        $this->product('FLG-5', 'Reduced', price: 80_00, listPrice: 100_00);
        $this->product('FLG-6', 'Full price', price: 100_00);

        $this->get('http://localhost/catalog/flagged?on_sale=1')->assertOk()
            ->assertSee('Reduced')
            ->assertDontSee('Full price');
    }

    /**
     * ...and a discount that comes from a running promotion reaches the SAME
     * box, through `catalog.filters.discounted`.
     *
     * One question, one control: which mechanism produced the discount is the
     * shop's business, and a visitor offered "below RRP" beside "in a sale"
     * would have to understand our pricing model to use the panel.
     */
    public function test_a_promotion_reaches_the_same_box(): void
    {
        $this->offer(['on_sale']);

        $promoted = $this->product('FLG-7', 'In the sale', price: 100_00);
        $this->product('FLG-8', 'Not in it', price: 100_00);

        $promotion = Promotion::create([
            'name' => ['en' => 'Ten off'],
            'zone' => Promotion::ZONE_CATALOG,
            'enabled' => true,
            'combine' => Promotion::REPLACE,
        ]);

        PromotionTier::create([
            'promotion_id' => $promotion->id,
            'discount_type' => PromotionTier::PERCENT,
            'discount_value' => 10,
            'conditions' => ['match' => 'all', 'rules' => [
                ['kind' => 'product', 'operator' => 'prod_in', 'ids' => [$promoted->id]],
            ]],
        ]);

        $this->get('http://localhost/catalog/flagged?on_sale=1')->assertOk()
            ->assertSee('In the sale')
            ->assertDontSee('Not in it');
    }

    /**
     * §103's rule, for a control with no values to thin out: a filter that
     * would empty the grid is not offered at all.
     */
    public function test_a_flag_nothing_here_matches_is_not_offered(): void
    {
        $this->product('FLG-9', 'None left', stock: 0);

        $this->get('http://localhost/catalog/flagged')->assertOk()
            ->assertDontSee('name="in_stock"', false);
    }

    /**
     * ...but a flag IN FORCE is still named, with the way back — the answer
     * §102 already gives for a value code nobody knows. Otherwise the grid is
     * empty and nothing on the page says why.
     */
    public function test_a_flag_in_force_is_named_even_where_its_box_is_hidden(): void
    {
        $this->product('FLG-10', 'None left', stock: 0);

        $this->get('http://localhost/catalog/flagged?in_stock=1')->assertOk()
            ->assertDontSee('None left')
            ->assertSee('In stock only')
            ->assertSee('href="http://localhost/catalog/flagged"', false);
    }

    /**
     * A characteristic may not be called `on_sale` even while the flag is
     * switched off: what an address MEANS cannot depend on a tick box in the
     * settings, or a shop turning the sale filter on would silently change
     * every published link that used the characteristic's name.
     */
    public function test_a_flags_name_is_reserved_even_when_it_is_not_offered(): void
    {
        $this->offer([]);

        $feature = Feature::create([
            'name' => ['en' => 'Sale'], 'code' => 'ON_SALE', 'purpose' => 'filter', 'input_type' => 'select',
        ]);
        FeatureValue::create(['feature_id' => $feature->id, 'value' => ['en' => 'Yes'], 'code' => 'YES']);
        app(FilterQuery::class)->flush();

        $this->assertNull(app(FilterQuery::class)->paramFor($feature));
    }

    /** @param list<string> $keys */
    protected function offer(array $keys): void
    {
        app(SettingsManager::class)->set('torgash.catalog', 'filter_flags', $keys);
        app(Flags::class)->flush();
    }

    protected function product(string $sku, string $name, int $stock = 4, int $price = 100_00, ?int $listPrice = null): Product
    {
        $product = Product::create([
            'sku' => $sku,
            'name' => ['en' => $name],
            'slug' => strtolower($sku),
            'price' => $price,
            'list_price' => $listPrice,
            'stock' => $stock,
            'status' => 'active',
        ]);

        $product->categories()->attach($this->category->id);

        return $product;
    }
}
