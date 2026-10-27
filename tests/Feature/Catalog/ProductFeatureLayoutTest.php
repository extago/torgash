<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;

/**
 * The order of a product's individual attributes, and the sections they sit in.
 *
 * The order cannot live in `features` itself: Postgres jsonb sorts keys by
 * length and then bytewise, so `{"zzz":1,"a":2}` reads back as `{"a":2,"zzz":1}`
 * and whatever the admin typed was already gone. It lives in `features_layout`
 * — one flat ordered list whose rows say what they are, which is exactly how
 * the editor shows them.
 *
 * What is pinned here is the drift between the two columns, because they are
 * edited together and imported separately.
 */
class ProductFeatureLayoutTest extends TestCase
{
    /** jsonb really does throw key order away — the reason any of this exists. */
    public function test_jsonb_does_not_keep_the_order_the_keys_were_written_in(): void
    {
        $product = $this->product(['zzz' => '1', 'a' => '2', 'mmmm' => '3', 'bb' => '4']);

        $this->assertSame(['a', 'bb', 'zzz', 'mmmm'], array_keys($product->fresh()->features));
    }

    /** With a layout, the rows come back in the admin's order, sections and all. */
    public function test_the_layout_decides_the_order_and_the_sections(): void
    {
        $product = $this->product(['zzz' => '1', 'a' => '2'], [
            ['type' => 'section', 'label' => 'Screen'],
            ['type' => 'feature', 'key' => 'zzz'],
            ['type' => 'feature', 'key' => 'a'],
        ]);

        $this->assertSame([
            ['type' => 'section', 'label' => 'Screen'],
            ['type' => 'feature', 'key' => 'zzz', 'value' => '1', 'label' => 'zzz'],
            ['type' => 'feature', 'key' => 'a', 'value' => '2', 'label' => 'a'],
        ], $product->featureRows());
    }

    /** A spec the layout never heard of is shown at the end, never hidden. */
    public function test_a_key_the_layout_does_not_mention_is_appended(): void
    {
        $product = $this->product(['a' => '1', 'imported' => '2'], [
            ['type' => 'feature', 'key' => 'a'],
        ]);

        $rows = $product->featureRows();

        $this->assertCount(2, $rows);
        $this->assertSame('imported', $rows[1]['key']);
    }

    /** A layout row whose spec is gone leaves no blank line. */
    public function test_a_layout_row_for_a_deleted_spec_is_skipped(): void
    {
        $product = $this->product(['a' => '1'], [
            ['type' => 'feature', 'key' => 'a'],
            ['type' => 'feature', 'key' => 'deleted'],
        ]);

        $this->assertSame([['type' => 'feature', 'key' => 'a', 'value' => '1', 'label' => 'a']], $product->featureRows());
    }

    /** ...and a section left with nothing under it leaves no heading. */
    public function test_a_section_with_nothing_under_it_is_dropped(): void
    {
        $product = $this->product(['a' => '1'], [
            ['type' => 'section', 'label' => 'Kept'],
            ['type' => 'feature', 'key' => 'a'],
            ['type' => 'section', 'label' => 'Empty'],
        ]);

        $rows = $product->featureRows();

        $this->assertCount(2, $rows);
        $this->assertSame('Kept', $rows[0]['label']);
    }

    /**
     * ...but the EDITOR keeps it: a section added a second ago has nothing
     * under it yet, and dropping it on the way back reads as "it did not save".
     */
    public function test_the_editor_keeps_a_section_that_has_nothing_under_it_yet(): void
    {
        $product = $this->product(['a' => '1'], [
            ['type' => 'feature', 'key' => 'a'],
            ['type' => 'section', 'label' => 'Just added'],
        ]);

        $rows = $product->featureRows(null, keepEmptySections: true);

        $this->assertCount(2, $rows);
        $this->assertSame('Just added', $rows[1]['label']);

        // And the form really does render it back.
        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/catalog/products/'.$product->id.'/edit')
            ->assertOk()
            ->assertSee('Just added');
    }

    /** The JSON tab may post the full list; the column still stores a map. */
    public function test_the_full_list_shape_is_read_back_into_a_map_and_an_order(): void
    {
        $product = $this->product(['a' => '1']);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->put('http://localhost/admin/catalog/products/'.$product->id, [
                'sku' => $product->sku,
                'name' => 'Layout widget',
                'price' => '10',
                'stock' => '1',
                'status' => 'active',
                'features' => json_encode([
                    ['type' => 'section', 'label' => 'Body'],
                    ['type' => 'feature', 'key' => 'height', 'value' => '13'],
                ]),
                'features_layout' => json_encode([
                    ['type' => 'section', 'label' => 'Body'],
                    ['type' => 'feature', 'key' => 'height'],
                ]),
            ])
            ->assertRedirect();

        $product->refresh();

        $this->assertSame(['height' => '13'], $product->features);
        $this->assertEquals([
            ['type' => 'section', 'label' => 'Body'],
            ['type' => 'feature', 'key' => 'height'],
        ], $product->features_layout);
    }

    /** The form posts the two together, and the save keeps only what it may. */
    public function test_saving_the_form_stores_the_order_and_refuses_a_key_that_is_not_there(): void
    {
        $product = $this->product(['a' => '1', 'b' => '2']);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->put('http://localhost/admin/catalog/products/'.$product->id, [
                'sku' => $product->sku,
                'name' => 'Layout widget',
                'price' => '10',
                'stock' => '1',
                'status' => 'active',
                'features' => json_encode(['a' => '1', 'b' => '2']),
                'features_layout' => json_encode([
                    ['type' => 'section', 'label' => ' Body '],
                    ['type' => 'feature', 'key' => 'b'],
                    ['type' => 'feature', 'key' => 'a'],
                    // Neither of these may end up stored: one is a spec the
                    // product does not have, the other a second mention of one
                    // it does — and a layout is never a place a value is defined.
                    ['type' => 'feature', 'key' => 'ghost'],
                    ['type' => 'feature', 'key' => 'b'],
                    ['type' => 'section', 'label' => '   '],
                ]),
            ])
            ->assertRedirect();

        // assertEquals, not assertSame: jsonb sorts the keys INSIDE each row too
        // ("key" before "type"), which is the same fact this whole column exists
        // for. What must hold is the order of the rows themselves — that is an
        // array, and arrays it does keep.
        $this->assertEquals([
            ['type' => 'section', 'label' => 'Body'],
            ['type' => 'feature', 'key' => 'b'],
            ['type' => 'feature', 'key' => 'a'],
        ], $product->fresh()->features_layout);
    }

    /** A product nobody ordered by hand carries no layout at all. */
    public function test_an_ordinary_product_stores_nothing(): void
    {
        $product = $this->product(['a' => '1']);

        $this->assertNull($product->features_layout);
        $this->assertSame([['type' => 'feature', 'key' => 'a', 'value' => '1', 'label' => 'a']], $product->featureRows());
    }

    /** @param array<string, mixed> $features */
    protected function product(array $features, ?array $layout = null): Product
    {
        return Product::create([
            'sku' => 'LAY-'.random_int(1000, 9999),
            'name' => ['en' => 'Layout widget'],
            'slug' => 'layout-widget-'.random_int(1000, 9999),
            'price' => 10_00,
            'stock' => 1,
            'status' => 'active',
            'features' => $features,
            'features_layout' => $layout,
        ]);
    }
}
