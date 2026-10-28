<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use App\Models\Storefront;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;

/**
 * What a category's page shows of the categories under it: tiles with a
 * picture, buttons or nothing — the theme's choice unless the category makes
 * its own — counts, and the photo a subcategory without a picture borrows
 * from its most popular product in stock.
 */
class SubcategoriesTest extends TestCase
{
    protected Storefront $storefront;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storefront = Storefront::query()->firstOrFail();
        $this->storefront->update(['theme' => 'default']);
    }

    public function test_tiles_by_default_with_counts_and_only_the_subcategories_on_offer_here(): void
    {
        $parent = $this->category('parent');
        $phones = $this->category('phones', $parent->id);
        $this->category('cases', $parent->id);
        $this->category('off', $parent->id, ['status' => Category::STATUS_DISABLED]);
        $this->category('aside', $parent->id, ['shown_in_parent' => false]);
        $this->product('SUB-P1', $phones);

        $page = $this->get('http://localhost/catalog/sub-parent')->assertOk()->getContent();
        $block = $this->block($page);

        $this->assertStringContainsString('torgash-tiles-circle', $block, 'the theme\'s frame by default');
        $this->assertStringContainsString('Sub Phones', $block);
        $this->assertStringContainsString('Sub Cases', $block);
        $this->assertStringContainsString('1 product', $block);
        $this->assertStringContainsString('0 products', $block);
        $this->assertStringNotContainsString('Sub Off', $block);
        $this->assertStringNotContainsString('Sub Aside', $block, 'left out of its parent\'s page');
        $this->assertStringContainsString('Sub Aside', $page, 'the list beside the catalogue still has it');

        $this->assertSame('', $this->block($this->get('http://localhost/catalog/sub-phones')->assertOk()->getContent()), 'nothing under it, nothing drawn');
    }

    public function test_a_category_says_for_itself_and_its_word_beats_the_themes(): void
    {
        $parent = $this->category('own');
        $this->category('a', $parent->id);
        $this->category('b', $parent->id);

        $parent->update(['subcategories_view' => 'buttons']);
        $block = $this->block($this->get('http://localhost/catalog/sub-own')->getContent());
        $this->assertStringContainsString('torgash-subcategory-chips', $block);
        $this->assertStringNotContainsString('torgash-tiles', $block);
        $this->assertStringContainsString('Sub A', $block);

        $parent->update(['subcategories_view' => 'none']);
        $this->assertSame('', $this->block($this->get('http://localhost/catalog/sub-own')->getContent()));

        $parent->update(['subcategories_view' => null]);
        $this->theme('subcategories', 'none');
        $this->assertSame('', $this->block($this->get('http://localhost/catalog/sub-own')->getContent()), 'the theme says none');

        $parent->update(['subcategories_view' => 'tiles']);
        $this->assertStringContainsString('torgash-tiles', $this->block($this->get('http://localhost/catalog/sub-own')->getContent()));
    }

    public function test_a_subcategory_without_a_picture_borrows_the_photo_of_its_most_popular_product_in_stock(): void
    {
        app(SettingsManager::class)->set('torgash.catalog', 'external_images', true);
        $parent = $this->category('lend');
        $x = $this->category('x', $parent->id);
        $y = $this->category('y', $parent->id);
        $deep = $this->category('deep', $y->id);

        $this->product('SUB-A', $x, ['popularity' => 10, 'image_url' => 'https://img.test/a.jpg']);
        $this->product('SUB-B', $x, ['popularity' => 50, 'image_url' => 'https://img.test/b.jpg', 'availability_status' => 'out_of_stock']);
        $this->product('SUB-C', $x, ['popularity' => 30]);
        $this->product('SUB-D', $deep, ['popularity' => 5, 'image_url' => 'https://img.test/d.jpg']);

        $block = $this->block($this->get('http://localhost/catalog/sub-lend')->getContent());
        $this->assertStringContainsString('https://img.test/a.jpg', $block, 'the most popular one in stock that has a photo');
        $this->assertStringNotContainsString('https://img.test/b.jpg', $block, 'never one that cannot be ordered');
        $this->assertStringContainsString('https://img.test/d.jpg', $block, 'from anywhere in its subtree');
        $this->assertStringContainsString('is-product', $block);

        $this->theme('subcategories_photos', false);
        $this->assertStringNotContainsString('img.test', $this->block($this->get('http://localhost/catalog/sub-lend')->getContent()));
    }

    /** The theme names the frame and the air inside it; the picture is what is left. */
    public function test_the_frame_its_padding_and_the_space_between_tiles_are_the_themes(): void
    {
        $parent = $this->category('frame');
        $this->category('inner', $parent->id);
        $style = fn () => (string) preg_replace('/.*?<section[^>]*style="([^"]*)".*/s', '$1', $this->block($this->get('http://localhost/catalog/sub-frame')->getContent()));

        $this->assertStringContainsString('--tile-pic: 72px; --tile-gap: 16px; --tile-text: 14px; --tile-pt: 16px', $style(), '104 less 16 on each side, as before the frame had a size');

        $this->theme('subcategories_frame_size', 120);
        $this->theme('subcategories_frame_padding', 0);
        $this->theme('subcategories_gap', 8);
        $this->assertStringContainsString('--tile-pic: 120px; --tile-gap: 8px; --tile-text: 14px; --tile-pt: 0px', $style(), 'a photo that fills the frame');

        $this->theme('subcategories_frame_size', 48);
        $this->theme('subcategories_frame_padding', 30);
        $this->assertStringContainsString('--tile-pic: 24px; --tile-gap: 8px; --tile-text: 14px; --tile-pt: 12px', $style(), 'padding never leaves the picture under 24px');
    }

    public function test_the_category_form_saves_its_own_choice_and_the_parent_flag(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());
        $category = $this->category('form');
        $url = 'http://localhost/admin/catalog/categories/'.$category->id;

        $this->get($url.'/edit')->assertOk()->assertSee('name="subcategories_view"', false);

        $this->put($url, ['name' => 'Sub Form', 'slug' => 'sub-form', 'subcategories_view' => 'buttons', 'shown_in_parent' => '0'])->assertRedirect();
        $this->assertSame('buttons', $category->fresh()->subcategories_view);
        $this->assertFalse($category->fresh()->shown_in_parent);

        $this->put($url, ['name' => 'Sub Form', 'slug' => 'sub-form', 'subcategories_view' => '', 'shown_in_parent' => '1'])->assertRedirect();
        $this->assertNull($category->fresh()->subcategories_view, 'empty is the theme\'s word');
        $this->assertTrue($category->fresh()->shown_in_parent);

        $this->put($url, ['name' => 'Sub Form', 'slug' => 'sub-form'])->assertRedirect();
        $this->assertTrue($category->fresh()->shown_in_parent, 'a client that never sends the box changes nothing');

        $this->put($url, ['name' => 'Sub Form', 'slug' => 'sub-form', 'subcategories_view' => 'sideways'])->assertSessionHasErrors('subcategories_view');
    }

    /** Counts and borrowed photos are one query each for the whole row. */
    public function test_the_tiles_cost_the_same_queries_however_many_subcategories_there_are(): void
    {
        app(SettingsManager::class)->set('torgash.catalog', 'external_images', true);
        $parent = $this->category('budget');
        $add = function (int $from, int $to) use ($parent) {
            foreach (range($from, $to) as $i) {
                $this->product("SUB-BUD-{$i}", $this->category("budget-{$i}", $parent->id), ['image_url' => "https://img.test/{$i}.jpg"]);
            }
        };

        $add(1, 2);
        $few = $this->queries('http://localhost/catalog/sub-budget');
        $add(3, 8);
        $many = $this->queries('http://localhost/catalog/sub-budget');

        $this->assertStringContainsString('https://img.test/8.jpg', $this->block($this->get('http://localhost/catalog/sub-budget')->getContent()));
        $this->assertSame($few, $many);
    }

    protected function category(string $slug, ?int $parent = null, array $extra = []): Category
    {
        $category = Category::create(['name' => ['en' => 'Sub '.ucfirst($slug)], 'slug' => "sub-{$slug}", 'parent_id' => $parent] + $extra);
        app(CategoryTree::class)->flush();

        return $category;
    }

    protected function product(string $sku, Category $category, array $extra = []): Product
    {
        $product = Product::create(['sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku), 'price' => 10_00, 'stock' => 5, 'status' => 'active'] + $extra);
        $product->categories()->attach($category->id);

        return $product;
    }

    protected function theme(string $key, mixed $value): void
    {
        app(SettingsManager::class)->set('theme.default', $key, $value, $this->storefront->id);
    }

    /** The subcategories' own drawing on a page, or '' where there is none. */
    protected function block(string $html): string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR);
        $node = (new \DOMXPath($dom))->query('//*[contains(concat(" ", normalize-space(@class), " "), " torgash-subcategories ")]')->item(0);

        return $node ? (string) $dom->saveHTML($node) : '';
    }

    /** Queries of a page, measured on the second request: the first warms the memos. */
    protected function queries(string $url): int
    {
        $this->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
