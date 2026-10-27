<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Blocks\BlockTypes;
use Torgash\Blocks\Models\Block;
use Torgash\Blocks\ZoneRenderer;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Support\CategoryTree;

/**
 * The "Category tiles" block — categories the shop picks and orders, drawn as
 * a grid or a row that scrolls — and the `picks` field it is built on.
 */
class CategoryTilesTest extends TestCase
{
    protected function category(string $slug, ?int $parent = null, string $status = Category::STATUS_ACTIVE): Category
    {
        $category = Category::create(['name' => ['en' => 'Tile '.ucfirst($slug)], 'slug' => "ct-{$slug}", 'parent_id' => $parent, 'status' => $status]);
        app(CategoryTree::class)->flush();

        return $category;
    }

    protected function tiles(array $settings): string
    {
        return app(ZoneRenderer::class)->block(new Block(['type' => 'category_tiles', 'settings' => $settings]));
    }

    /** A pick is kept in its order, once, and only while it is still on offer. */
    public function test_picks_keep_their_order_and_drop_what_is_not_offered(): void
    {
        $a = $this->category('alpha');
        $b = $this->category('beta');

        $clean = BlockTypes::sanitize('category_tiles', ['categories' => [(string) $b->id, $a->id, $b->id, 999999, ['x']]]);
        $this->assertSame([$b->id, $a->id], $clean['categories']);

        $this->assertSame([], BlockTypes::sanitize('category_tiles', [])['categories'], 'nothing posted is an empty list');
        $this->assertSame([], BlockTypes::sanitize('category_tiles', ['categories' => 'not a list'])['categories']);
    }

    public function test_picked_categories_are_drawn_in_the_order_picked_and_switched_off_ones_skipped(): void
    {
        $parent = $this->category('parent');
        $child = $this->category('child', $parent->id);
        $off = $this->category('off', status: Category::STATUS_DISABLED);

        $html = $this->tiles(['categories' => [$child->id, $off->id, $parent->id], 'layout' => 'grid']);

        $this->assertStringContainsString('torgash-tiles-grid', $html);
        $this->assertStringNotContainsString('Tile Off', $html);
        $this->assertLessThan(strpos($html, 'Tile Parent'), strpos($html, 'Tile Child'), 'the order picked, not the tree\'s');
    }

    /** None picked: the top level, in the catalogue's order. */
    public function test_none_picked_draws_the_top_categories(): void
    {
        $top = $this->category('top');
        $this->category('below', $top->id);

        $html = $this->tiles([]);

        $this->assertStringContainsString('Tile Top', $html);
        $this->assertStringNotContainsString('Tile Below', $html);
        $this->assertStringContainsString('x-data="torgashTiles"', $html, 'a row that scrolls by default');
        $this->assertStringContainsString('torgash-tiles-indicator', $html);
    }

    public function test_a_row_of_two_and_a_strip_without_its_indicator(): void
    {
        $this->category('solo');

        $html = $this->tiles(['layout' => 'strip2', 'indicator' => false, 'arrows' => true, 'picture_size' => 128, 'look' => 'inside']);

        $this->assertStringContainsString('torgash-tiles-two', $html);
        $this->assertStringContainsString('torgash-tiles-inside', $html);
        $this->assertStringContainsString('--tile-pic: 128px', $html);
        $this->assertStringNotContainsString('torgash-tiles-indicator', $html);
        $this->assertStringContainsString('torgash-tiles-arrow', $html);
    }

    /** Tinted tiles take a hue each by position, so neighbours never share one; plain tiles take none. */
    public function test_tinted_tiles_give_neighbours_different_colours(): void
    {
        $this->category('first');
        $this->category('second');

        $html = $this->tiles(['look' => 'corner', 'colors' => 'tinted']);

        $this->assertStringContainsString('torgash-tiles-corner', $html);
        $this->assertStringContainsString('torgash-tiles-tinted', $html);
        preg_match_all('/--tile-hue: (\d+)/', $html, $hues);
        $this->assertGreaterThanOrEqual(2, count($hues[1]));
        $this->assertNotSame($hues[1][0], $hues[1][1]);

        $plain = $this->tiles(['look' => 'nonsense']);
        $this->assertStringContainsString('torgash-tiles-under', $plain, 'an unknown look falls back to the default');
        $this->assertStringNotContainsString('--tile-hue', $plain);
    }

    /** Sizes are px the shop types; padding is CSS shorthand, anything else is the look's own. */
    public function test_sizes_and_padding_are_typed_in_px(): void
    {
        $this->category('solo');

        $html = $this->tiles(['picture_size' => 90, 'gap_size' => 24, 'text_size' => 999, 'padding' => '30px 40px']);
        $this->assertStringContainsString('--tile-pic: 90px; --tile-gap: 24px; --tile-text: 32px', $html, 'text size clamped to its max');
        $this->assertStringContainsString('--tile-pt: 30px; --tile-pr: 40px; --tile-pb: 30px; --tile-pl: 40px', $html);

        $this->assertStringContainsString('--tile-pt: 4px; --tile-pr: 8px; --tile-pb: 12px; --tile-pl: 16px', $this->tiles(['padding' => '4 8 12 16']));
        $this->assertStringContainsString('--tile-pt: 1px; --tile-pr: 2px; --tile-pb: 3px; --tile-pl: 2px', $this->tiles(['padding' => '1 2px 3']));
        $this->assertStringContainsString('--tile-pt: 16px; --tile-pr: 16px', $this->tiles(['padding' => '1rem; color: red']), 'not px numbers: the default of the look');
        $this->assertStringContainsString('--tile-pt: 12px; --tile-pr: 14px', $this->tiles(['look' => 'corner']));
    }

    /** The tile picture wins over the icon in "auto", and "icon" ignores it. */
    public function test_a_category_has_a_tile_picture_of_its_own(): void
    {
        Storage::fake('public');
        $this->actingAs(User::role('admin')->firstOrFail());
        $category = $this->category('pictured');

        $this->put('http://localhost/admin/catalog/categories/'.$category->id, [
            'name' => 'Tile Pictured',
            'slug' => 'ct-pictured',
            'tile_file' => UploadedFile::fake()->image('phone.png', 300, 300),
        ])->assertRedirect();

        $path = $category->fresh()->tile_image;
        $this->assertStringStartsWith(Category::TILE_DIR.'/', (string) $path);
        Storage::disk('public')->assertExists($path);
        app(CategoryTree::class)->flush();

        $this->assertStringContainsString(basename($path), $this->tiles(['categories' => [$category->id]]));
        $this->assertStringNotContainsString(basename($path), $this->tiles(['categories' => [$category->id], 'picture' => 'icon']));

        $this->put('http://localhost/admin/catalog/categories/'.$category->id, [
            'name' => 'Tile Pictured', 'slug' => 'ct-pictured', 'tile_image_remove' => '1',
        ])->assertRedirect();

        $this->assertNull($category->fresh()->tile_image);
        Storage::disk('public')->assertMissing($path);
    }

    /** The home page's categories are the theme's block now, not the template's. */
    public function test_the_home_page_draws_the_themes_category_tiles(): void
    {
        $this->category('homely');

        $this->assertSame(['category_tiles'], Block::where('zone', 'storefront.home.content')->pluck('type')->all());

        $this->get('http://localhost/')
            ->assertOk()
            ->assertSee('torgash-block-category-tiles', false)
            ->assertSee('Tile Homely');
    }
}
