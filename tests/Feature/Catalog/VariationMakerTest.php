<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use InvalidArgumentException;
use Tests\Concerns\MakesVariationAxes;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Catalog\Support\VariationMaker;
use Torgash\Catalog\Support\Variations;

/**
 * "Add variation" on the product form's Variations tab (VariationMaker):
 * new members of the group made from this product — the facts of the model
 * copied, the content NOT copied but shown from this product — one per
 * combination ticked, the ones the group already holds skipped; and the
 * tab's table: rows by card, in the values' order, each row's quick edit
 * posting its own names so the product form's are never confused with it.
 */
class VariationMakerTest extends TestCase
{
    use MakesVariationAxes;

    protected Feature $colour;

    protected Feature $size;

    /** @var array<string, FeatureValue> */
    protected array $colours;

    /** @var array<string, FeatureValue> */
    protected array $sizes;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->colour, $this->colours] = $this->variationAxis('VM Colour', ['black', 'red']);
        [$this->size, $this->sizes] = $this->variationAxis('VM Size', ['40', '42', '44']);
        $this->size->update(['variation_role' => Feature::ROLE_CHOICE]);
        $this->actingAs(User::role('admin')->firstOrFail());
    }

    protected function shoe(string $sku, string $colour, string $size, array $extra = []): Product
    {
        $product = Product::create($extra + [
            'sku' => $sku, 'name' => ['en' => 'VM Runner '.$colour.' '.$size], 'slug' => strtolower($sku),
            'price' => 100_00, 'stock' => 5, 'status' => 'active', 'variation_group' => 'vm-runner',
        ]);
        $this->answer($product, $this->colours[$colour]);
        $this->answer($product, $this->sizes[$size]);

        return $product;
    }

    public function test_a_new_size_copies_the_facts_and_takes_its_content_from_this_product(): void
    {
        $category = Category::create(['name' => ['en' => 'VM Shoes'], 'slug' => 'vm-shoes']);
        $black40 = $this->shoe('VM-B-40', 'black', '40', [
            'description' => ['en' => 'A long story'], 'weight' => 900, 'external_id' => 'ext-1',
        ]);
        $black40->categories()->sync([$category->id]);

        $result = app(VariationMaker::class)->make($black40, [$this->size->id => [$this->sizes['42']->id]], ['stock' => 3]);

        $this->assertSame(0, $result['existed']);
        $this->assertCount(1, $result['created']);
        $new = $result['created'][0]->fresh();

        $this->assertSame('vm-runner', $new->variation_group);
        $this->assertSame('VM Runner black 42', $new->name['en'], 'the value swapped into the name');
        $this->assertSame(100_00, (int) $new->price);
        $this->assertSame(3, (int) $new->stock);
        $this->assertSame(900, (int) $new->weight);
        $this->assertSame(Product::STATUS_DISABLED, $new->status);
        $this->assertSame([$category->id], $new->categories->pluck('id')->all());
        $this->assertNotSame($black40->sku, $new->sku);
        $this->assertNull($new->external_id, 'an external id is nobody else\'s');

        $this->assertNull($new->getAttributes()['description'] ?? null, 'content is not copied…');
        $this->assertSame($black40->id, (int) $new->content_source_id, '…it is shown from this product');
        $this->assertSame('A long story', $new->t('description', 'en'));

        $answers = ProductFeature::where('product_id', $new->id)->pluck('feature_value_id', 'feature_id')->all();
        $this->assertSame([$this->colour->id => $this->colours['black']->id, $this->size->id => $this->sizes['42']->id],
            [$this->colour->id => (int) $answers[$this->colour->id], $this->size->id => (int) $answers[$this->size->id]]);
    }

    public function test_a_source_is_never_a_chain(): void
    {
        $black40 = $this->shoe('VM-C-40', 'black', '40');
        $black42 = $this->shoe('VM-C-42', 'black', '42', ['content_source_id' => $black40->id]);

        $result = app(VariationMaker::class)->make($black42, [$this->size->id => [$this->sizes['44']->id]]);

        $this->assertSame($black40->id, (int) $result['created'][0]->content_source_id);
    }

    public function test_combinations_multiply_and_the_ones_the_group_holds_are_skipped(): void
    {
        $black40 = $this->shoe('VM-M-40', 'black', '40');
        $this->shoe('VM-M-R42', 'red', '42');

        // black and red × 40, 42, 44 = 6; black 40 (this one) and red 42 exist.
        $result = app(VariationMaker::class)->make($black40, [
            $this->colour->id => [$this->colours['black']->id, $this->colours['red']->id],
            $this->size->id => [$this->sizes['40']->id, $this->sizes['42']->id, $this->sizes['44']->id],
        ], ['activate' => true]);

        $this->assertSame(2, $result['existed']);
        $this->assertCount(4, $result['created']);
        $this->assertSame(['VM Runner black 42', 'VM Runner black 44', 'VM Runner red 40', 'VM Runner red 44'],
            array_map(fn (Product $p) => $p->name['en'], $result['created']));
        $this->assertSame([Product::STATUS_ACTIVE], array_unique(array_map(fn (Product $p) => $p->status, $result['created'])));

        $group = app(Variations::class)->for($black40, everyStatus: true);
        $this->assertCount(6, $group['members']);
        $this->assertSame([], $group['duplicates'], 'nothing made twice');
    }

    public function test_nothing_chosen_a_foreign_value_or_too_many_are_refused(): void
    {
        $black40 = $this->shoe('VM-R-40', 'black', '40');
        $maker = app(VariationMaker::class);

        try {
            $maker->make($black40, []);
            $this->fail('nothing chosen');
        } catch (InvalidArgumentException) {
        }

        try {
            $maker->make($black40, [$this->size->id => [$this->colours['red']->id]]);
            $this->fail('a colour is not a size');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(0, Product::where('variation_group', 'vm-runner')->count() - 1);
    }

    public function test_the_screen_adds_one_and_opens_it_or_several_and_stays(): void
    {
        $black40 = $this->shoe('VM-S-40', 'black', '40');

        $this->post(route('admin.catalog.products.variations.add', $black40), [
            'values' => [$this->size->id => [$this->sizes['42']->id]],
            'price' => '120', 'stock' => '2',
        ])->assertRedirect();

        $new = Product::where('variation_group', 'vm-runner')->whereKeyNot($black40->id)->firstOrFail();
        $this->assertSame(120_00, (int) $new->price);
        $this->assertSame(2, (int) $new->stock);

        $response = $this->post(route('admin.catalog.products.variations.add', $black40), [
            'values' => [$this->colour->id => [$this->colours['red']->id], $this->size->id => [$this->sizes['40']->id, $this->sizes['42']->id]],
        ]);
        $response->assertRedirect(route('admin.catalog.products.edit', [$black40, 'tab' => 'variations']));
        $this->assertSame(4, Product::where('variation_group', 'vm-runner')->count());

        // Nothing ticked: back to the tab with the reason.
        $this->post(route('admin.catalog.products.variations.add', $black40), [])
            ->assertSessionHasErrors('variations');
    }

    public function test_the_table_stands_by_card_in_the_values_order_and_its_row_edits_in_place(): void
    {
        $black42 = $this->shoe('VM-T-B42', 'black', '42');
        $red40 = $this->shoe('VM-T-R40', 'red', '40');
        $black40 = $this->shoe('VM-T-B40', 'black', '40');
        $red44 = $this->shoe('VM-T-R44', 'red', '44');

        $html = $this->get(route('admin.catalog.products.edit', [$black42, 'tab' => 'variations']))->assertOk()->getContent();

        $at = fn (string $sku) => strpos($html, 'id="variant-row-'.Product::where('sku', $sku)->value('id').'"');
        $this->assertTrue($at('VM-T-B40') < $at('VM-T-B42'), 'black 40 before black 42');
        $this->assertTrue($at('VM-T-B42') < $at('VM-T-R40'), 'black (first colour) before red');
        $this->assertTrue($at('VM-T-R40') < $at('VM-T-R44'));
        $this->assertStringContainsString('variant-card-heading', $html, 'a heading per card');
        $this->assertStringContainsString('name="variant[price]"', $html, 'the row\'s own names, not the form\'s');
        $this->assertStringContainsString('id="product-add-variations"', $html);

        // The row's quick edit: its own names, the row of the tab comes back.
        $response = $this->patch(route('admin.catalog.products.quick', [$red40, 'row' => 'variant', 'for' => $black42->id]), [
            'variant' => ['sku' => 'VM-T-R40', 'price' => '99', 'stock' => '7'],
            // What HTMX would send from the product form around the table, had hx-params not kept it out.
            'price' => '1', 'stock' => '1',
        ]);
        $response->assertOk();
        $this->assertStringContainsString('id="variant-row-'.$red40->id.'"', $response->getContent());
        $this->assertStringContainsString('this product', $this->get(route('admin.catalog.products.edit', $black42))->getContent());
        $this->assertSame(99_00, (int) $red40->fresh()->price);
        $this->assertSame(7, (int) $red40->fresh()->stock);

        $response = $this->patch(route('admin.catalog.products.status', [$red40, 'row' => 'variant', 'for' => $black42->id, 'status' => 'hidden']));
        $response->assertOk();
        $this->assertStringContainsString('id="variant-row-'.$red40->id.'"', $response->getContent());
        $this->assertSame('hidden', $red40->fresh()->status);
    }
}
