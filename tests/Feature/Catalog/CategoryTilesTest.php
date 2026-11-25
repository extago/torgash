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

    /**
     * The tiles are rows in their order: a bare id stored before (the old
     * list of picks) is a category tile, a category no longer offered or a
     * row without one is dropped, and the same category may stand twice —
     * with a different picture, say.
     */
    public function test_tiles_keep_their_order_and_drop_what_is_not_offered(): void
    {
        $a = $this->category('alpha');
        $b = $this->category('beta');

        $clean = BlockTypes::sanitize('category_tiles', ['categories' => [(string) $b->id, $a->id, $b->id, 999999, ['x']]]);
        $this->assertSame([$b->id, $a->id, $b->id], array_column($clean['categories'], 'category'));
        $this->assertSame(['category'], array_unique(array_column($clean['categories'], 'kind')));
        $this->assertCount(3, array_unique(array_column($clean['categories'], 'id')), 'every row its own id');

        $this->assertSame([], BlockTypes::sanitize('category_tiles', [])['categories'], 'nothing posted is an empty list');
        $this->assertSame([], BlockTypes::sanitize('category_tiles', ['categories' => 'not a list'])['categories']);
    }

    /**
     * A tile of its own: a category with its own name, picture and class on
     * this block, and a link with an address — one that is not an address
     * (javascript:, //elsewhere) or has no name is not a tile.
     */
    public function test_a_tile_has_its_own_name_picture_and_class_and_a_link_is_a_tile(): void
    {
        Storage::fake('public');
        $phones = $this->category('phones');
        $picture = 'block-images/202611/tile-'.str_repeat('a', 16).'.webp';

        $clean = BlockTypes::sanitize('category_tiles', ['categories' => [
            ['id' => 'tileone1', 'kind' => 'category', 'category' => (string) $phones->id, 'name' => 'Smartphones', 'image' => $picture, 'class' => 'hot "><script>'],
            ['kind' => 'link', 'url' => '/sale', 'name' => 'Sale', 'class' => 'sale'],
            ['kind' => 'link', 'url' => 'javascript:alert(1)', 'name' => 'Bad'],
            ['kind' => 'link', 'url' => '//elsewhere.test/x', 'name' => 'Elsewhere'],
            ['kind' => 'link', 'url' => '/no-name'],
            ['kind' => 'category', 'category' => $phones->id, 'image' => '../../.env'],
        ]]);

        $rows = $clean['categories'];
        $this->assertCount(3, $rows);
        $this->assertSame(['id' => 'tileone1', 'kind' => 'category', 'category' => $phones->id, 'name' => 'Smartphones', 'class' => 'hot', 'image' => $picture], $rows[0]);
        $this->assertSame(['link', '/sale', 'Sale', 'sale'], [$rows[1]['kind'], $rows[1]['url'], $rows[1]['name'], $rows[1]['class']]);
        $this->assertArrayNotHasKey('image', $rows[2], 'a path we did not write is never a picture');

        $html = $this->tiles(['categories' => $rows, 'layout' => 'grid']);

        $this->assertSame(1, preg_match('#class="torgash-tile hot".*?</a>#s', $html, $own));
        $this->assertStringContainsString('Smartphones', $own[0]);
        $this->assertStringNotContainsString('Tile Phones', $own[0], 'the own name replaces the category\'s');
        $this->assertStringContainsString(Storage::disk('public')->url($picture), $html);
        $this->assertMatchesRegularExpression('#<a href="[^"]*/catalog/ct-phones" class="torgash-tile hot"#', $html);
        $this->assertMatchesRegularExpression('#<a href="/sale" class="torgash-tile sale"#', $html);
        $this->assertStringContainsString('Tile Phones', $html, 'the second tile has nothing of its own');

        // An own picture stands whole, in the picture size, as a product's
        // photo does — or fills the frame when the tile says so.
        $this->assertStringNotContainsString('is-photo', $own[0]);
        $covered = BlockTypes::sanitize('category_tiles', ['categories' => [$rows[0] + ['fit' => 'cover'], ['kind' => 'category', 'category' => $phones->id, 'fit' => 'stretched']]]);
        $this->assertSame('cover', $covered['categories'][0]['fit']);
        $this->assertArrayNotHasKey('fit', $covered['categories'][1], 'only a choice on offer is kept');
        $this->assertSame(1, preg_match('#class="torgash-tile hot".*?</a>#s', $this->tiles(['categories' => $covered['categories'], 'layout' => 'grid']), $own));
        $this->assertStringContainsString('torgash-tile-pic is-photo', $own[0]);
    }

    /**
     * The form posts one language: a tile's name typed in Russian joins the
     * English one by the row's id, whatever place the row moved to.
     */
    public function test_a_tiles_name_is_translated_by_its_row_not_its_place(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());
        $a = $this->category('first');
        $b = $this->category('second');

        $existing = ['categories' => [
            ['id' => 'rowaaaa1', 'kind' => 'category', 'category' => $a->id, 'name' => 'First own'],
            ['id' => 'rowbbbb2', 'kind' => 'category', 'category' => $b->id, 'name' => 'Second own'],
        ]];

        session(['admin_content_locale' => 'ru']);
        $locale = admin_content_locale();

        $clean = BlockTypes::sanitize('category_tiles', ['categories' => [
            ['id' => 'rowbbbb2', 'kind' => 'category', 'category' => $b->id, 'name' => 'Второй'],
            ['id' => 'rowaaaa1', 'kind' => 'category', 'category' => $a->id, 'name' => ''],
        ]], $existing, 'form');

        $base = content_base_locale();
        $this->assertSame('ru', $locale, 'the test means a second language');
        $this->assertSame([$base => 'Second own', 'ru' => 'Второй'], $clean['categories'][0]['name']);
        $this->assertSame('First own', $clean['categories'][1]['name'], 'blank in this language keeps the others');

        $flat = BlockTypes::localize('category_tiles', $clean, 'ru');
        $this->assertSame('Второй', $flat['categories'][0]['name']);
    }

    /**
     * A picture is stored when picked and answered with its path; the block
     * that stops naming it — replaced, or the block deleted — deletes it.
     */
    public function test_a_tiles_picture_is_uploaded_and_deleted_with_the_last_block_naming_it(): void
    {
        Storage::fake('public');
        $this->actingAs(User::role('admin')->firstOrFail());
        $phones = $this->category('pictured-tile');

        $path = $this->post('http://localhost/admin/blocks/image', ['file' => UploadedFile::fake()->image('tile.png', 300, 300)])
            ->assertOk()->json('path');
        $this->assertMatchesRegularExpression('#^block-images/\d{6}/tile-[A-Za-z0-9]{16}\.png$#', $path);
        Storage::disk('public')->assertExists($path);

        $this->post('http://localhost/admin/blocks/image', ['file' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain')])
            ->assertStatus(302);

        $block = Block::create(['zone' => 'storefront.home.content', 'type' => 'category_tiles', 'enabled' => true, 'settings' => [
            'categories' => [['id' => 'picrow01', 'kind' => 'category', 'category' => $phones->id, 'image' => $path]],
        ]]);

        $form = $this->get('http://localhost/admin/blocks/'.$block->id.'/edit')->assertOk()->getContent();
        $this->assertStringContainsString(basename($path), $form);
        $this->assertStringNotContainsString('row.id === id); if', $form, 'the rows editor arrives whole, not as text');

        $this->put('http://localhost/admin/blocks/'.$block->id, [
            'zone' => $block->zone, 'type' => 'category_tiles', 'enabled' => '1',
            'settings' => ['categories' => [['id' => 'picrow01', 'kind' => 'category', 'category' => $phones->id, 'image' => '']]],
        ])->assertRedirect();

        $this->assertArrayNotHasKey('image', $block->fresh()->settings['categories'][0]);
        Storage::disk('public')->assertMissing($path);

        $second = $this->post('http://localhost/admin/blocks/image', ['file' => UploadedFile::fake()->image('tile.webp')])->json('path');
        $block->update(['settings' => ['categories' => [['id' => 'picrow01', 'kind' => 'category', 'category' => $phones->id, 'image' => $second]]]]);
        $this->delete('http://localhost/admin/blocks/'.$block->id)->assertRedirect();
        Storage::disk('public')->assertMissing($second);
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
