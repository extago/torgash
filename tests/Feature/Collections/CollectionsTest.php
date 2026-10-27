<?php

namespace Tests\Feature\Collections;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Catalog\Selections\Sources;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Collections\Models\Collection;

/**
 * Collections (PLAN §57.3): the catalogue filter's own conditions, saved,
 * plus products picked and left out; a page that is the catalogue's listing;
 * a product source for any product block.
 */
class CollectionsTest extends TestCase
{
    protected Category $furniture;

    protected Category $chairs;

    protected Feature $colour;

    protected FeatureValue $red;

    protected FeatureValue $blue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->furniture = Category::create(['name' => ['en' => 'Furniture'], 'slug' => 'col-furniture', 'enabled' => true]);
        $this->chairs = Category::create(['name' => ['en' => 'Chairs'], 'slug' => 'col-chairs', 'parent_id' => $this->furniture->id, 'enabled' => true]);
        app(CategoryTree::class)->flush();
        $this->colour = Feature::create(['name' => ['en' => 'Colour'], 'code' => 'col_colour', 'input_type' => 'select']);
        $this->red = FeatureValue::create(['feature_id' => $this->colour->id, 'value' => ['en' => 'Red'], 'slug' => 'col-red']);
        $this->blue = FeatureValue::create(['feature_id' => $this->colour->id, 'value' => ['en' => 'Blue'], 'slug' => 'col-blue']);
    }

    public function test_the_conditions_mean_what_the_filter_means(): void
    {
        $redChair = $this->product('C-RED', 100_00, $this->chairs, $this->red);
        $blueChair = $this->product('C-BLUE', 300_00, $this->chairs, $this->blue);
        $soldOut = $this->product('C-OUT', 100_00, $this->chairs, $this->red, stock: 0);
        $elsewhere = $this->product('C-ELSE', 100_00, null, $this->red);

        $ids = fn (Collection $c) => $c->productQuery()->pluck('m_catalog_products.id')->sort()->values()->all();

        // A category takes its subcategories.
        $byCategory = $this->collection(['categories' => [$this->furniture->id]]);
        $this->assertSame(collect([$redChair, $blueChair, $soldOut])->pluck('id')->sort()->values()->all(), $ids($byCategory));

        // Every piece must hold: furniture AND red AND in stock AND under 200.
        $narrow = $this->collection([
            'categories' => [$this->furniture->id],
            'features' => [$this->colour->id => [$this->red->id]],
            'flags' => ['in_stock'],
            'price_max' => 200_00,
        ]);
        $this->assertSame([$redChair->id], $ids($narrow));

        // Picked products are added, left-out ones removed.
        DB::table('m_collection_products')->insert([
            ['collection_id' => $narrow->id, 'product_id' => $elsewhere->id, 'excluded' => false],
            ['collection_id' => $byCategory->id, 'product_id' => $soldOut->id, 'excluded' => true],
        ]);
        $this->assertSame(collect([$redChair, $elsewhere])->pluck('id')->sort()->values()->all(), $ids($narrow));
        $this->assertNotContains($soldOut->id, $ids($byCategory));

        // Nothing set and nothing picked: empty, never the whole catalogue.
        $this->assertSame([], $ids($this->collection([])));
    }

    public function test_a_label_counts_as_the_cards_show_it_by_hand_and_by_its_rules(): void
    {
        $fresh = $this->product('N-FRESH', 100_00, null, null);
        $old = $this->product('N-OLD', 100_00, null, null);
        $old->forceFill(['created_at' => now()->subDays(90)])->save();
        $handPicked = $this->product('N-NONE', 100_00, null, null);
        $handPicked->forceFill(['created_at' => now()->subDays(90)])->save();

        // "New": a product younger than 30 days, by its rule — and one put on by hand.
        $new = \Torgash\Catalog\Models\Label::create([
            'name' => ['en' => 'New'], 'name_for_admin' => 'Col New', 'color' => 'primary', 'enabled' => true,
            'conditions' => ['match' => 'all', 'rules' => [['field' => 'age_days', 'operator' => 'gte', 'value' => 30]]],
        ]);
        $old->labels()->attach($new->id);
        $collection = $this->collection(['labels' => [$new->id]]);

        $ids = $collection->productQuery()->pluck('m_catalog_products.id')->all();
        $this->assertContains($fresh->id, $ids, 'by the rule');
        $this->assertContains($old->id, $ids, 'by hand');
        $this->assertNotContains($handPicked->id, $ids);

        // Switched off, the label gives nothing — as on a card.
        $new->update(['enabled' => false]);
        $this->assertSame([], $collection->fresh()->productQuery()->pluck('m_catalog_products.id')->all());
    }

    public function test_the_page_and_the_list(): void
    {
        $this->product('P-1', 100_00, $this->chairs, $this->red);
        $shown = $this->collection(['categories' => [$this->chairs->id]], ['slug' => 'red-chairs', 'name' => ['en' => 'Red chairs']]);
        $unlisted = $this->collection(['categories' => [$this->chairs->id]], ['slug' => 'secret', 'name' => ['en' => 'Secret one'], 'listed' => false]);
        $this->collection(['categories' => [$this->chairs->id]], ['slug' => 'off', 'name' => ['en' => 'Off one'], 'enabled' => false]);
        $this->collection(['categories' => [$this->chairs->id]], ['slug' => 'later', 'name' => ['en' => 'Later one'], 'starts_at' => now()->addDay()]);

        $this->get('http://localhost/collections/red-chairs')->assertOk()->assertSee('Red chairs')->assertSee('P-1 product');
        $this->get('http://localhost/collections/secret')->assertOk();
        $this->get('http://localhost/collections/off')->assertNotFound();
        $this->get('http://localhost/collections/later')->assertNotFound();

        $this->get('http://localhost/collections')->assertOk()->assertSee('Red chairs')->assertDontSee('Secret one')->assertDontSee('Off one');
        $this->assertTrue($shown->isShown() && $unlisted->isShown());
    }

    public function test_any_product_block_can_show_a_collection(): void
    {
        $product = $this->product('S-1', 100_00, $this->chairs, $this->blue);
        $collection = $this->collection(['features' => [$this->colour->id => [$this->blue->id]]]);

        $source = app(Sources::class)->all()['collection'] ?? null;
        $this->assertNotNull($source);
        $ids = ($source['apply'])(Product::listed(), (string) $collection->id, [], [])->pluck('m_catalog_products.id')->all();
        $this->assertSame([$product->id], $ids);
    }

    public function test_the_admin_form(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('http://localhost/admin/collections/create')->assertOk();
        $this->actingAs($admin)->post('http://localhost/admin/collections', [
            'name' => 'Summer chairs', 'enabled' => '1', 'listed' => '1',
            'categories' => [$this->chairs->id], 'values' => [$this->red->id, $this->blue->id],
            'flags' => ['in_stock'], 'price_min' => '10', 'price_max' => '',
            'cover' => UploadedFile::fake()->image('cover.png', 40, 30),
        ])->assertRedirect();

        $collection = Collection::where('slug', 'summer-chairs')->firstOrFail();
        $this->assertSame([$this->colour->id => [$this->red->id, $this->blue->id]], array_map(fn ($v) => array_values($v), $collection->conditions['features']));
        $this->assertSame(10_00, $collection->conditions['price_min']);
        $this->assertNull($collection->conditions['price_max']);
        $this->assertNotNull($collection->cover);
        Storage::disk('public')->assertExists($collection->cover);

        // A cover that is not a picture is not kept.
        $this->actingAs($admin)->put('http://localhost/admin/collections/'.$collection->id, [
            'name' => 'Summer chairs', 'enabled' => '1', 'slug' => 'summer-chairs',
            'cover' => UploadedFile::fake()->createWithContent('cover.png', '<svg onload="x()"/>'),
        ])->assertRedirect();
        Storage::disk('public')->assertExists($collection->fresh()->cover);
        $this->assertStringEndsWith('.png', $collection->fresh()->cover);

        $this->actingAs($admin)->get('http://localhost/admin/collections')->assertOk()->assertSee('Summer chairs');
        $this->actingAs($admin)->delete('http://localhost/admin/collections/'.$collection->id)->assertRedirect();
        $this->assertNull(Collection::find($collection->id));
    }

    public function test_a_look_is_a_picture_with_points_on_products(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $chair = $this->product('L-1', 100_00, $this->chairs, null);
        $hidden = $this->product('L-2', 100_00, $this->chairs, null);
        $hidden->update(['status' => 'disabled']);
        $collection = $this->collection(['categories' => [$this->chairs->id]], ['slug' => 'living-room', 'name' => ['en' => 'Living room']]);

        $form = ['name' => 'Living room', 'enabled' => '1', 'listed' => '1', 'slug' => 'living-room', 'categories' => [$this->chairs->id]];
        $this->actingAs($admin)->put('http://localhost/admin/collections/'.$collection->id, $form + [
            'looks_new' => [UploadedFile::fake()->image('room.jpg', 80, 60), UploadedFile::fake()->createWithContent('evil.png', '<svg onload="x()"/>')],
        ])->assertRedirect();
        $look = $collection->looks()->sole();
        Storage::disk('public')->assertExists($look->image);

        // Points: one on the chair, one on a product nobody may see, one out of the picture, one on nothing.
        $this->actingAs($admin)->put('http://localhost/admin/collections/'.$collection->id, $form + [
            'looks' => [$look->id => ['caption' => 'A calm corner', 'sort' => 0, 'points' => json_encode([
                ['x' => 30, 'y' => 40, 'product_id' => $chair->id],
                ['x' => 70, 'y' => 20, 'product_id' => $hidden->id],
                ['x' => 150, 'y' => -5, 'product_id' => $chair->id],
                ['x' => 10, 'y' => 10, 'product_id' => 999999],
            ])]],
        ])->assertRedirect();
        $this->assertCount(3, $look->fresh()->points, 'a product that does not exist is not kept');
        $this->assertSame(['x' => 100, 'y' => 0, 'product_id' => $chair->id], $look->fresh()->points[2], 'clamped to the picture');

        $page = $this->get('http://localhost/collections/living-room')->assertOk()->getContent();
        $this->assertStringContainsString('A calm corner', $page);
        $this->assertStringContainsString('left: 30%; top: 40%', $page);
        $this->assertStringNotContainsString('L-2 product', $page, 'a hidden product has no point');

        $this->actingAs($admin)->put('http://localhost/admin/collections/'.$collection->id, $form + ['looks' => [$look->id => ['delete' => '1']]])->assertRedirect();
        $this->assertNull($look->fresh());
        Storage::disk('public')->assertMissing($look->image);
    }

    /** A shop of a hundred thousand products: the form names only the chosen ones, the rest are FOUND. */
    public function test_products_are_searched_never_listed_in_the_form(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $picked = $this->product('PICK-ME', 100_00, null, null);
        $other = $this->product('NOT-IN-PAGE', 100_00, null, null);
        $collection = $this->collection([], ['slug' => 'searched']);
        DB::table('m_collection_products')->insert(['collection_id' => $collection->id, 'product_id' => $picked->id, 'excluded' => false]);

        $form = $this->actingAs($admin)->get('http://localhost/admin/collections/'.$collection->id.'/edit')->assertOk()->getContent();
        $this->assertStringContainsString('PICK-ME', $form);
        $this->assertStringNotContainsString('NOT-IN-PAGE', $form);

        $found = $this->actingAs($admin)->getJson('http://localhost/admin/collections/products?q=not-in')->assertOk()->json();
        $this->assertSame([['id' => $other->id, 'label' => 'NOT-IN-PAGE — NOT-IN-PAGE product']], $found);
        $this->assertSame([], $this->actingAs($admin)->getJson('http://localhost/admin/collections/products?q=')->json());
        // By the name in any language, and a % typed is a percent sign, not "anything".
        $this->assertCount(1, $this->actingAs($admin)->getJson('http://localhost/admin/collections/products?q=pick-me+product')->json());
        $this->assertSame([], $this->actingAs($admin)->getJson('http://localhost/admin/collections/products?q=%25')->json());

        $customer = User::factory()->create();
        $this->actingAs($customer)->getJson('http://localhost/admin/collections/products?q=pick')->assertForbidden();
    }

    public function test_the_tiles_block(): void
    {
        $this->collection(['categories' => [$this->chairs->id]], ['slug' => 'tile-one', 'name' => ['en' => 'Tile one']]);

        $html = view('torgash.collections::blocks.collections', ['settings' => ['heading' => 'Ideas']])->render();
        $this->assertStringContainsString('Ideas', $html);
        $this->assertStringContainsString('/collections/tile-one', $html);
    }

    protected function collection(array $conditions, array $extra = []): Collection
    {
        static $n = 0;
        $n++;

        return Collection::create($extra + ['name' => ['en' => 'Collection '.$n], 'slug' => 'collection-test-'.$n, 'conditions' => $conditions]);
    }

    protected function product(string $sku, int $price, ?Category $category, ?FeatureValue $value, int $stock = 5): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => ['en' => $sku.' product'], 'slug' => strtolower($sku), 'price' => $price, 'stock' => $stock, 'status' => 'active',
        ]);
        if ($category) {
            $product->categories()->attach($category->id);
        }
        if ($value) {
            ProductFeature::create(['product_id' => $product->id, 'feature_id' => $value->feature_id, 'feature_value_id' => $value->id]);
        }

        return $product;
    }
}
