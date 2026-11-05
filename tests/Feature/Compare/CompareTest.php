<?php

namespace Tests\Feature\Compare;

use App\Core\Settings\SettingsManager;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Compare\Comparison;

/**
 * Comparison (PLAN §35.4): kept in the session, grouped by the deepest
 * category, capped per group, and a table of the characteristics the product
 * page itself shows, with "differences only".
 */
class CompareTest extends TestCase
{
    public function test_the_button_toggles_counts_and_announces_an_add(): void
    {
        $product = $this->product('CMP-1', 'Comparekettle');

        $added = $this->post(route('compare.toggle', 'cmp-1'), [], ['HX-Request' => 'true'])->assertOk()->getContent();

        $this->assertStringContainsString('aria-pressed="true"', $added);
        $this->assertMatchesRegularExpression('/id="compare-counter"[^>]*hx-swap-oob="true"/', $added);
        $this->assertStringContainsString('id="torgash-notices" hx-swap-oob="afterbegin"', $added);
        $this->assertStringContainsString('Added to comparison', $added);
        $this->assertSame([$product->id], array_keys(session(Comparison::SESSION)));

        $removed = $this->post(route('compare.toggle', 'cmp-1'), [], ['HX-Request' => 'true'])->assertOk()->getContent();
        $this->assertStringContainsString('aria-pressed="false"', $removed);
        $this->assertStringNotContainsString('torgash-notices', $removed);
        $this->assertSame([], session(Comparison::SESSION));
    }

    public function test_a_full_group_pushes_out_the_first_and_says_so(): void
    {
        app(SettingsManager::class)->set('torgash.compare', 'per_group_max', 2);
        $kettles = Category::create(['name' => ['en' => 'Kettles'], 'slug' => 'cmp-kettles', 'enabled' => true]);

        foreach (['CMP-2' => 'Firstkettle', 'CMP-3' => 'Secondkettle', 'CMP-4' => 'Thirdkettle'] as $sku => $name) {
            $this->product($sku, $name)->categories()->attach($kettles->id);
        }

        $this->post(route('compare.toggle', 'cmp-2'), [], ['HX-Request' => 'true']);
        $this->post(route('compare.toggle', 'cmp-3'), [], ['HX-Request' => 'true']);
        $third = $this->post(route('compare.toggle', 'cmp-4'), [], ['HX-Request' => 'true'])->getContent();

        $this->assertStringContainsString('in place of «Firstkettle»', $third);
        $this->assertSame(['cmp-3', 'cmp-4'], Product::whereIn('id', array_keys(session(Comparison::SESSION)))->orderBy('id')->pluck('slug')->all());
    }

    /** One tab per deepest category, opened on the one added to last. */
    public function test_products_are_compared_one_category_at_a_time(): void
    {
        $tech = Category::create(['name' => ['en' => 'Tech'], 'slug' => 'cmp-tech', 'enabled' => true]);
        $laptops = Category::create(['name' => ['en' => 'Laptops'], 'slug' => 'cmp-laptops', 'enabled' => true, 'parent_id' => $tech->id]);
        $phones = Category::create(['name' => ['en' => 'Phones'], 'slug' => 'cmp-phones', 'enabled' => true, 'parent_id' => $tech->id]);

        $laptop = $this->product('CMP-5', 'Complaptop');
        $laptop->categories()->attach([$tech->id, $laptops->id]);
        $this->product('CMP-6', 'Compphone')->categories()->attach($phones->id);

        $this->post(route('compare.toggle', 'cmp-5'));
        $this->post(route('compare.toggle', 'cmp-6'));

        $page = $this->get(route('compare.index'))->assertOk();
        $page->assertSee('Laptops')->assertSee('Phones')->assertSee('Compphone')->assertDontSee('Complaptop');

        $this->get(route('compare.index', ['group' => $laptops->id]))->assertOk()
            ->assertSee('Complaptop')->assertDontSee('Compphone');
    }

    /** The setting `grouping`: "All together" beside the tabs, or instead of them. */
    public function test_everything_can_be_compared_together_whatever_the_category(): void
    {
        $kettles = Category::create(['name' => ['en' => 'Kettles'], 'slug' => 'cmp-all-kettles', 'enabled' => true]);
        $toasters = Category::create(['name' => ['en' => 'Toasters'], 'slug' => 'cmp-all-toasters', 'enabled' => true]);
        $this->product('CMP-11', 'Allkettle')->categories()->attach($kettles->id);
        $this->product('CMP-12', 'Alltoaster')->categories()->attach($toasters->id);
        $this->post(route('compare.toggle', 'cmp-11'));
        $this->post(route('compare.toggle', 'cmp-12'));

        // By category (the default): no such tab, and ?group=all is not an
        // answer — the first category's tab opens, one category at a time.
        $this->get(route('compare.index', ['group' => 'all']))->assertOk()
            ->assertDontSee('All together')->assertSee('Allkettle')->assertDontSee('Alltoaster');

        app(SettingsManager::class)->set('torgash.compare', 'grouping', 'category_all');
        $page = $this->get(route('compare.index', ['group' => 'all']))->assertOk();
        $page->assertSee('All together')->assertSee('Kettles')->assertSee('Toasters')->assertSee('Allkettle')->assertSee('Alltoaster');
        $page->assertSee('aria-current="page"', false);
        // One category alone has nothing to put together.
        $this->post(route('compare.toggle', 'cmp-12'));
        $this->get(route('compare.index'))->assertOk()->assertDontSee('All together');

        // No categories at all: one group, the limit counts every product.
        app(SettingsManager::class)->set('torgash.compare', 'grouping', 'all');
        app(SettingsManager::class)->set('torgash.compare', 'per_group_max', 2);
        $this->post(route('compare.toggle', 'cmp-12'));
        $this->product('CMP-13', 'Allblender');
        $third = $this->post(route('compare.toggle', 'cmp-13'), [], ['HX-Request' => 'true'])->getContent();
        $this->assertStringContainsString('in place of «Allkettle»: up to 2 products are compared.', $third);

        $page = $this->get(route('compare.index'))->assertOk();
        // (The categories still stand in the site's menu; no TAB names one.)
        $page->assertSee('All products')->assertDontSee('group='.$kettles->id, false)->assertSee('Alltoaster')->assertSee('Allblender');
    }

    public function test_the_table_shows_what_the_product_page_shows_and_can_hide_the_same(): void
    {
        $colour = Feature::create(['name' => ['en' => 'Shade'], 'code' => 'CMP_SHADE', 'purpose' => 'filter', 'input_type' => 'select']);
        $red = FeatureValue::create(['feature_id' => $colour->id, 'value' => ['en' => 'Crimson'], 'code' => 'CRIMSON']);
        $blue = FeatureValue::create(['feature_id' => $colour->id, 'value' => ['en' => 'Navyish'], 'code' => 'NAVYISH']);
        $volume = Feature::create(['name' => ['en' => 'Capacity'], 'code' => 'CMP_CAP', 'purpose' => 'filter', 'input_type' => 'text']);
        $hidden = Feature::create(['name' => ['en' => 'Supplier note'], 'code' => 'CMP_NOTE', 'purpose' => 'filter', 'input_type' => 'text', 'shown_in' => ['filters']]);

        $a = $this->product('CMP-7', 'Alphakettle');
        $b = $this->product('CMP-8', 'Betakettle');
        ProductFeature::create(['product_id' => $a->id, 'feature_id' => $colour->id, 'feature_value_id' => $red->id]);
        ProductFeature::create(['product_id' => $b->id, 'feature_id' => $colour->id, 'feature_value_id' => $blue->id]);
        ProductFeature::create(['product_id' => $a->id, 'feature_id' => $volume->id, 'value_text' => '1.7 l']);
        ProductFeature::create(['product_id' => $b->id, 'feature_id' => $volume->id, 'value_text' => '1.7 l']);
        ProductFeature::create(['product_id' => $a->id, 'feature_id' => $hidden->id, 'value_text' => 'Secretish']);

        $this->post(route('compare.toggle', 'cmp-7'));
        $this->post(route('compare.toggle', 'cmp-8'));

        $all = $this->get(route('compare.index'))->assertOk();
        $all->assertSee('Crimson')->assertSee('Navyish')->assertSee('Capacity')->assertDontSee('Secretish');

        $diff = $this->get(route('compare.index', ['diff' => 1]))->assertOk();
        $diff->assertSee('Shade')->assertDontSee('Capacity');
    }

    public function test_a_product_that_has_gone_stops_being_compared(): void
    {
        $product = $this->product('CMP-9', 'Gonekettle');
        $this->post(route('compare.toggle', 'cmp-9'));

        $product->update(['status' => 'disabled']);

        $this->get(route('compare.index'))->assertOk()->assertSee('Nothing to compare yet');
        $this->assertSame([], session(Comparison::SESSION));
    }

    public function test_the_button_is_on_cards_and_under_the_buy_button(): void
    {
        $this->product('CMP-10', 'Cardkettle');

        $this->get('http://localhost/catalog?sort=newest')->assertOk()->assertSee('action="'.route('compare.toggle', 'cmp-10').'"', false);
        $this->get(route('catalog.product', 'cmp-10'))->assertOk()->assertSee('Add to comparison');

        app(SettingsManager::class)->set('torgash.compare', 'card_button', false);
        $this->get('http://localhost/catalog?sort=newest')->assertOk()->assertDontSee('action="'.route('compare.toggle', 'cmp-10').'"', false);
    }

    protected function product(string $sku, string $name, array $extra = []): Product
    {
        return Product::create($extra + [
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => 100_00, 'stock' => 10, 'status' => 'active', 'created_at' => now()->addYear(),
        ]);
    }
}
