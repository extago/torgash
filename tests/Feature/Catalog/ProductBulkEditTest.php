<?php

namespace Tests\Feature\Catalog;

use App\Models\Currency;
use App\Models\User;
use App\Core\Support\Currencies;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;

/** The product list: found by filters, changed in bulk — ticked rows or everything found. */
class ProductBulkEditTest extends TestCase
{
    protected const BULK = 'http://localhost/admin/catalog/products/bulk';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::role('admin')->firstOrFail());
    }

    protected function product(string $sku, int $price, array $extra = []): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku),
            'price' => $price, 'stock' => 5, 'status' => 'active',
        ] + $extra);
    }

    protected function category(string $name, ?int $parent = null): Category
    {
        $category = Category::create(['name' => ['en' => $name], 'slug' => strtolower(str_replace(' ', '-', $name)), 'parent_id' => $parent, 'sort' => 0, 'enabled' => true]);
        app(CategoryTree::class)->flush();

        return $category;
    }

    public function test_prices_move_by_percent_and_amount_with_rounding_and_never_below_zero(): void
    {
        $a = $this->product('PB-A', 1000_00, ['list_price' => 1200_00]);
        $b = $this->product('PB-B', 333_00);
        $c = $this->product('PB-C', 20_00);

        $this->post(self::BULK, [
            'action' => 'edit', 'ids' => [$a->id, $b->id],
            'price_mode' => 'percent', 'price_value' => 10, 'round' => 10,
            'list_price_mode' => 'add', 'list_price_value' => 50,
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(1100_00, $a->fresh()->price);
        $this->assertSame(1250_00, $a->fresh()->list_price);
        $this->assertSame(370_00, $b->fresh()->price, '366.30 rounded to tens');
        $this->assertNull($b->fresh()->list_price, 'no old price to move, none invented');
        $this->assertSame(20_00, $c->fresh()->price, 'not ticked');

        // Lowering past zero leaves that product's prices alone and says so.
        $this->post(self::BULK, ['action' => 'edit', 'ids' => [$c->id, $b->id], 'price_mode' => 'add', 'price_value' => -100])
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'below zero'));
        $this->assertSame(20_00, $c->fresh()->price);
        $this->assertSame(270_00, $b->fresh()->price);
    }

    public function test_a_price_typed_in_another_currency_moves_as_typed(): void
    {
        Currency::updateOrCreate(['code' => 'UAH'], ['symbol' => '₴', 'rate' => 40, 'decimals' => 0, 'enabled' => true]);
        app(Currencies::class)->flush();

        $product = $this->product('PB-UAH', 100_00, ['price_currency' => 'UAH', 'price_original' => 4000_00]);

        $this->post(self::BULK, ['action' => 'edit', 'ids' => [$product->id], 'price_mode' => 'percent', 'price_value' => 50]);

        $this->assertSame(6000_00, $product->fresh()->price_original);
        $this->assertSame(150_00, $product->fresh()->price);
    }

    public function test_stock_availability_categories_and_labels(): void
    {
        $keep = $this->category('PB Keep');
        $drop = $this->category('PB Drop');
        $product = $this->product('PB-REL', 10_00);
        $product->categories()->sync([$keep->id, $drop->id]);
        $added = $this->category('PB Added');

        $this->post(self::BULK, [
            'action' => 'edit', 'ids' => [$product->id],
            'stock_mode' => 'add', 'stock_value' => -2,
            'availability_status' => 'preorder',
            'categories_add' => [$added->id], 'categories_remove' => [$drop->id],
        ])->assertRedirect();

        $product->refresh();
        $this->assertSame(3, $product->stock);
        $this->assertSame('preorder', $product->availability_status);
        $this->assertEqualsCanonicalizing([$keep->id, $added->id], $product->categories()->pluck('m_catalog_categories.id')->all());

        $this->post(self::BULK, ['action' => 'edit', 'ids' => [$product->id]])->assertSessionHasErrors('action');
    }

    public function test_everything_found_means_what_the_filters_found_including_subcategories(): void
    {
        $parent = $this->category('PB Parent');
        $child = $this->category('PB Child', $parent->id);
        $inChild = $this->product('PB-IN-CHILD', 10_00);
        $inChild->categories()->sync([$child->id]);
        $elsewhere = $this->product('PB-ELSEWHERE', 10_00);

        $this->get('http://localhost/admin/catalog/products?category='.$parent->id)->assertOk()
            ->assertSee('PB-IN-CHILD')->assertDontSee('PB-ELSEWHERE');

        $this->post(self::BULK, ['action' => 'hidden', 'scope' => 'found', 'category' => $parent->id])->assertRedirect();

        $this->assertSame('hidden', $inChild->fresh()->status);
        $this->assertSame('active', $elsewhere->fresh()->status);
    }

    public function test_duplicate_copies_the_ticked_products_disabled(): void
    {
        $product = $this->product('PB-DUP', 10_00);

        $this->post(self::BULK, ['action' => 'duplicate', 'ids' => [$product->id]])->assertRedirect();

        $copy = Product::where('sku', 'like', 'PB-DUP-%')->first();
        $this->assertNotNull($copy);
        $this->assertSame('disabled', $copy->status);
    }
}
