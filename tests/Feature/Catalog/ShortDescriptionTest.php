<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Catalog\Support\ShortDescription;

/**
 * The one line under a product's name — PLAN §30.10.
 *
 * What is worth pinning is not that it prints, but the four places it could
 * quietly go wrong: the mode (does what is typed win), which characteristics
 * compose it (only the ticked ones), where it differs between a page and a
 * card, and whether a grid pays for it per row.
 */
class ShortDescriptionTest extends TestCase
{
    protected Category $category;

    protected Feature $memory;

    protected Feature $warranty;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => ['en' => 'Laptops'], 'slug' => 'sd-laptops', 'enabled' => true,
        ]);

        $this->memory = Feature::create([
            'name' => ['en' => 'Memory'], 'code' => 'sd_memory', 'purpose' => 'info',
            'input_type' => 'select', 'in_short_description' => true, 'sort' => 1,
        ]);

        // Ticked off, deliberately: a catalogue has thirty characteristics and
        // three of them belong in a one-line answer.
        $this->warranty = Feature::create([
            'name' => ['en' => 'Warranty'], 'code' => 'sd_warranty', 'purpose' => 'info',
            'input_type' => 'text', 'in_short_description' => false, 'sort' => 2,
        ]);

        $this->settings([
            'short_description_mode' => 'fill_empty',
            'short_description_names' => '1',
            'short_description_separator' => ', ',
            'short_description_card_separator' => ' · ',
            'short_description_card_limit' => 3,
        ]);
    }

    public function test_it_is_composed_from_the_ticked_characteristics(): void
    {
        $product = $this->product('SD-1', 'Air');

        $this->assertSame('Memory: 16 GB', app(ShortDescription::class)->for($product));

        $this->get('http://localhost/product/sd-1')->assertOk()->assertSee('Memory: 16 GB');
    }

    /** A characteristic nobody ticked stays out of it, however useful it is elsewhere. */
    public function test_an_unticked_characteristic_is_not_in_it(): void
    {
        $product = $this->product('SD-2', 'Zenbook');

        ProductFeature::create([
            'product_id' => $product->id, 'feature_id' => $this->warranty->id, 'value_text' => '24 months',
        ]);

        $this->assertStringNotContainsString('24 months', app(ShortDescription::class)->for($product->fresh()));
    }

    public function test_what_is_typed_wins_until_the_shop_says_otherwise(): void
    {
        $product = $this->product('SD-3', 'Air');
        $product->update(['short_description' => ['en' => 'The thin one.']]);

        $this->assertSame('The thin one.', app(ShortDescription::class)->for($product));

        $this->settings(['short_description_mode' => 'override']);
        app(ShortDescription::class)->flush();

        $this->assertSame('Memory: 16 GB', app(ShortDescription::class)->for($product));
    }

    /**
     * ...and with nothing composed, `override` still prints what was written:
     * composing is what the setting asks for, and an empty line is not an
     * improvement on a sentence somebody wrote.
     */
    public function test_override_falls_back_to_what_was_typed(): void
    {
        $product = Product::create([
            'sku' => 'SD-4', 'name' => ['en' => 'Bare'], 'slug' => 'sd-4',
            'price' => 1000_00, 'stock' => 1, 'status' => 'active',
            'short_description' => ['en' => 'Nothing measured about this one.'],
        ]);

        $this->settings(['short_description_mode' => 'override']);
        app(ShortDescription::class)->flush();

        $this->assertSame('Nothing measured about this one.', app(ShortDescription::class)->for($product));
    }

    public function test_off_means_only_what_a_person_wrote(): void
    {
        $product = $this->product('SD-5', 'Air');

        $this->settings(['short_description_mode' => 'off']);
        app(ShortDescription::class)->flush();

        $this->assertSame('', app(ShortDescription::class)->for($product));
    }

    /** A card has two lines: its own separator, its own limit, and no names. */
    public function test_a_card_is_written_differently_from_a_page(): void
    {
        $product = $this->product('SD-6', 'Air');
        $screen = Feature::create([
            'name' => ['en' => 'Screen'], 'code' => 'sd_screen', 'purpose' => 'info',
            'input_type' => 'text', 'in_short_description' => true, 'sort' => 3,
        ]);
        ProductFeature::create([
            'product_id' => $product->id, 'feature_id' => $screen->id, 'value_text' => '15.6',
        ]);

        app(ShortDescription::class)->flush();

        $service = app(ShortDescription::class);

        $this->assertSame('Memory: 16 GB, Screen: 15.6', $service->for($product));
        $this->assertSame('16 GB · 15.6', $service->for($product, ShortDescription::PLACE_CARD));

        $this->settings(['short_description_card_limit' => 1]);
        $service->flush();

        $this->assertSame('16 GB', $service->for($product, ShortDescription::PLACE_CARD));
    }

    /**
     * The line is composed from a relation, which is exactly the shape that
     * costs a query per card — so the grid must not grow with the grid.
     */
    public function test_a_grid_does_not_pay_for_it_per_card(): void
    {
        foreach (range(1, 3) as $i) {
            $this->product("SDG-{$i}", "Grid {$i}");
        }

        // Warm-up: the per-request memos are filled once per PROCESS, because
        // tests reuse one application across requests.
        $this->get('http://localhost/catalog/sd-laptops')->assertOk();
        $few = $this->queriesFor('http://localhost/catalog/sd-laptops');

        foreach (range(4, 12) as $i) {
            $this->product("SDG-{$i}", "Grid {$i}");
        }

        $many = $this->queriesFor('http://localhost/catalog/sd-laptops');

        $this->assertSame($few, $many, "12 products cost {$many} queries and 3 cost {$few} — something is asked per card");
    }

    /** It reaches a template the same way it reaches the page. */
    public function test_it_is_a_placeholder(): void
    {
        $product = $this->product('SD-7', 'Air');

        $this->assertSame(
            'Air — Memory: 16 GB',
            expand_placeholders('{product} — {short_description}', ['product' => $product]),
        );
    }

    protected function product(string $sku, string $name): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => 1000_00, 'stock' => 5, 'status' => 'active',
        ]);

        $product->categories()->attach($this->category->id);

        ProductFeature::create([
            'product_id' => $product->id,
            'feature_id' => $this->memory->id,
            'feature_value_id' => FeatureValue::firstOrCreate(
                ['feature_id' => $this->memory->id, 'code' => '16gb'],
                ['value' => ['en' => '16 GB'], 'slug' => 'sd-16gb'],
            )->id,
        ]);

        app(ShortDescription::class)->flush();

        return $product->fresh();
    }

    /** @param array<string, mixed> $values */
    protected function settings(array $values): void
    {
        foreach ($values as $key => $value) {
            app(SettingsManager::class)->set('torgash.catalog', $key, $value);
        }

        app(ShortDescription::class)->flush();
    }

    protected function queriesFor(string $url): int
    {
        // A real request starts with an empty memo, and this one must too: the
        // application is reused across requests here, so a warm singleton would
        // make the FIRST measurement look like the cheap one and hide exactly
        // the regression this test is for.
        app(ShortDescription::class)->flush();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get($url)->assertOk();

        $count = count(DB::getRawQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
