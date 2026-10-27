<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Filters\FilterQuery;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;

/**
 * Where a characteristic is SHOWN, and why that is a different question from
 * what it can be filtered by.
 *
 * `purpose` used to answer both at once: "Info" meant a specification that was
 * not in the filters, "Filter" meant a filter that was also a specification —
 * so a shop could not ask for the combinations it actually wanted (filterable
 * but not printed among the specifications; printed beside the buy button and
 * nowhere else; addressed by a published page and drawn nowhere at all).
 *
 * See Torgash\Catalog\Features\Places for why unticking everything does not
 * make the characteristic any less filterable.
 */
class FeatureVisibilityTest extends TestCase
{
    protected Category $category;

    protected Feature $feature;

    protected FeatureValue $steel;

    protected FeatureValue $wood;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['name' => ['en' => 'Pans'], 'slug' => 'vis-pans', 'enabled' => true]);
        $this->feature = Feature::create([
            'name' => ['en' => 'Material'], 'code' => 'VIS_MATERIAL', 'purpose' => 'filter', 'input_type' => 'select',
        ]);
        $this->steel = FeatureValue::create(['feature_id' => $this->feature->id, 'value' => ['en' => 'Stainless steel'], 'code' => 'STEEL']);
        $this->wood = FeatureValue::create(['feature_id' => $this->feature->id, 'value' => ['en' => 'Wood'], 'code' => 'WOOD']);

        app(FilterQuery::class)->flush();
    }

    /** Ticked everywhere by default: a new characteristic behaves as `purpose = filter` always did. */
    public function test_a_new_characteristic_is_shown_everywhere(): void
    {
        $fresh = Feature::create(['name' => ['en' => 'Finish'], 'purpose' => 'filter', 'input_type' => 'select']);

        // In memory, before anything reads the row back: Postgres does not hand
        // a column default back after INSERT, and `shows()` must not answer
        // from a NULL that only exists because nobody called fresh().
        $this->assertTrue($fresh->shows('filters'));
        $this->assertTrue($fresh->shows('specs'));
        $this->assertTrue($fresh->shows('buy_box'));
    }

    /** The panel offers what the shop ticked for it — and nothing else. */
    public function test_an_unticked_characteristic_is_not_offered_in_the_panel(): void
    {
        $this->product('VIS-1', 'Steel pan', $this->steel);
        $this->product('VIS-2', 'Wooden spoon', $this->wood);

        $this->get('http://localhost/catalog/'.$this->category->slug)->assertOk()->assertSee('Stainless steel');

        $this->feature->update(['shown_in' => ['specs']]);

        $this->get('http://localhost/catalog/'.$this->category->slug)->assertOk()
            ->assertDontSee('Stainless steel');
    }

    /**
     * The point of the split: a characteristic out of the panel still narrows.
     *
     * A published filter page is a set of characteristic VALUES at an address
     * of its own, so a characteristic that stopped filtering when its box was
     * unticked would widen every page built on it into its whole category —
     * under a heading still promising less.
     */
    public function test_a_characteristic_out_of_the_panel_still_filters(): void
    {
        $this->product('VIS-3', 'Steel pan', $this->steel);
        $this->product('VIS-4', 'Wooden spoon', $this->wood);

        $this->feature->update(['shown_in' => []]);
        app(FilterQuery::class)->flush();

        $this->get('http://localhost/catalog/'.$this->category->slug.'?vis_material=steel')->assertOk()
            ->assertSee('Steel pan')
            ->assertDontSee('Wooden spoon');
    }

    /** ...and it is named above the grid, which is the way back off it. */
    public function test_a_filter_with_no_control_is_still_named_with_a_way_out(): void
    {
        $this->product('VIS-5', 'Steel pan', $this->steel);

        $this->feature->update(['shown_in' => []]);
        app(FilterQuery::class)->flush();

        $this->get('http://localhost/catalog/'.$this->category->slug.'?vis_material=steel')->assertOk()
            ->assertSee('Material: Stainless steel')
            ->assertSee('href="http://localhost/catalog/'.$this->category->slug.'"', false);
    }

    /** Out of the specifications means out of them on the page. */
    public function test_an_unticked_characteristic_is_not_printed_among_the_specifications(): void
    {
        $product = $this->product('VIS-6', 'Steel pan', $this->steel);

        $this->get('http://localhost/product/'.$product->slug)->assertOk()->assertSee('Stainless steel');

        $this->feature->update(['shown_in' => ['filters']]);

        $this->get('http://localhost/product/'.$product->slug)->assertOk()
            ->assertDontSee('Stainless steel');
    }

    /**
     * ...including in the rendition a machine reads.
     *
     * A page that disclosed what the HTML hides would be a hole that differs
     * from the shop's own decision only by an Accept header.
     */
    public function test_a_hidden_characteristic_is_absent_from_the_markdown_rendition(): void
    {
        $product = $this->product('VIS-7', 'Steel pan', $this->steel);

        $this->get('http://localhost/product/'.$product->slug.'.md')->assertOk()->assertSee('Stainless steel');

        $this->feature->update(['shown_in' => ['filters']]);

        $this->get('http://localhost/product/'.$product->slug.'.md')->assertOk()
            ->assertDontSee('Stainless steel');
    }

    /** The short list beside the buy button is its own answer, not the first four specs. */
    public function test_the_buy_panel_shows_only_what_is_ticked_for_it(): void
    {
        $product = $this->product('VIS-8', 'Steel pan', $this->steel);

        // Both places ticked: the value is on the page twice — once in the
        // panel, once in the specifications.
        // What the PAGE shows: the head's description may name it too.
        $body = strstr($this->get('http://localhost/product/'.$product->slug)->assertOk()->getContent(), '<body');
        $this->assertSame(2, substr_count($body, 'Stainless steel'));

        $this->feature->update(['shown_in' => ['specs']]);

        $body = strstr($this->get('http://localhost/product/'.$product->slug)->assertOk()->getContent(), '<body');
        $this->assertSame(1, substr_count($body, 'Stainless steel'));
    }

    /** A place no module answers for never reaches the column. */
    public function test_the_form_keeps_only_registered_places(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user)->put('http://localhost/admin/catalog/features/'.$this->feature->id, [
            'name' => 'Material',
            'code' => 'VIS_MATERIAL',
            'purpose' => 'filter',
            'input_type' => 'select',
            'shown_in_set' => '1',
            'shown_in' => ['specs', 'invented_place'],
            'values_json' => '[]',
        ])->assertRedirect();

        $this->assertSame(['specs'], $this->feature->fresh()->shown_in);
    }

    /** Nothing ticked is a real answer, and it survives the form. */
    public function test_nothing_ticked_is_saved_as_nothing(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user)->put('http://localhost/admin/catalog/features/'.$this->feature->id, [
            'name' => 'Material',
            'code' => 'VIS_MATERIAL',
            'purpose' => 'filter',
            'input_type' => 'select',
            'shown_in_set' => '1',
            'values_json' => '[]',
        ])->assertRedirect();

        $this->assertSame([], $this->feature->fresh()->shown_in);
    }

    protected function product(string $sku, string $name, FeatureValue $value): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => 100_00, 'stock' => 4, 'status' => 'active',
        ]);

        $product->categories()->attach($this->category->id);
        ProductFeature::create([
            'product_id' => $product->id,
            'feature_id' => $this->feature->id,
            'feature_value_id' => $value->id,
        ]);

        return $product;
    }
}
