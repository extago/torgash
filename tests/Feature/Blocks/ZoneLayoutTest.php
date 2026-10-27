<?php

namespace Tests\Feature\Blocks;

use App\Models\User;
use Tests\TestCase;
use Torgash\Blocks\Models\Block;
use Torgash\Blocks\ZoneRenderer;

/**
 * A zone laid out like a page: neighbouring blocks sharing a `row` are
 * COLUMNS (a word on the blocks, as tabs are — never a block that holds
 * blocks), joined and taken apart from the zone screen, moved as one; and the
 * picker offers what suits the zone's shape first.
 */
class ZoneLayoutTest extends TestCase
{
    protected string $zone = 'storefront.home.content';

    protected function setUp(): void
    {
        parent::setUp();

        Block::where('zone', $this->zone)->delete();
        $this->actingAs(User::role('admin')->firstOrFail());
    }

    protected function html(string $text, int $sort, array $extra = []): Block
    {
        return Block::create(['zone' => $this->zone, 'type' => 'html', 'title' => $text, 'enabled' => true, 'sort' => $sort,
            'settings' => ['html' => '<p>'.$text.'</p>'], 'rules_match' => 'all', ...$extra]);
    }

    public function test_widths_always_fill_the_row(): void
    {
        $this->assertSame([6, 6], ZoneRenderer::spans([null, null]));
        $this->assertSame([4, 4, 4], ZoneRenderer::spans([null, null, null]));
        $this->assertSame([8, 4], ZoneRenderer::spans(['2/3', null]));
        $this->assertSame([3, 5, 4], ZoneRenderer::spans(['1/4', null, '1/3']));
        // Twelve does not divide by five: the first columns take the remainder.
        $this->assertSame([3, 3, 2, 2, 2], ZoneRenderer::spans([null, null, null, null, null]));
    }

    public function test_a_row_is_drawn_as_columns_and_a_row_of_one_is_a_block(): void
    {
        $this->html('Left column', 1, ['row' => 'r1', 'width' => '2/3']);
        $this->html('Right column', 2, ['row' => 'r1']);
        $this->html('Alone below', 3);

        $out = app(ZoneRenderer::class)->render(Block::where('zone', $this->zone)->orderBy('sort')->get());

        $this->assertStringContainsString('torgash-block-row', $out);
        $this->assertMatchesRegularExpression('/md:col-span-8">\s*<div[^>]*><p>Left column/', $out);
        $this->assertMatchesRegularExpression('/md:col-span-4">\s*<div[^>]*><p>Right column/', $out);
        $this->assertSame(1, substr_count($out, 'torgash-block-row'), 'the lone block is not a row');
    }

    public function test_join_detach_and_moving_a_row_as_one(): void
    {
        $a = $this->html('A', 1);
        $b = $this->html('B', 2);
        $c = $this->html('C', 3);

        $this->patch("http://localhost/admin/blocks/{$a->id}/join", ['as' => 'row'])->assertRedirect();
        $this->assertNotNull($a->fresh()->row);
        $this->assertSame($a->fresh()->row, $b->fresh()->row);

        // C moves up past the whole row, never into the middle of it.
        $this->patch("http://localhost/admin/blocks/{$c->id}/move", ['direction' => 'up'])->assertRedirect();
        $order = Block::where('zone', $this->zone)->orderBy('sort')->pluck('title')->all();
        $this->assertSame(['C', 'A', 'B'], $order);

        // Inside the row, B goes left of A.
        $this->patch("http://localhost/admin/blocks/{$b->id}/move", ['direction' => 'left'])->assertRedirect();
        $this->assertSame(['C', 'B', 'A'], Block::where('zone', $this->zone)->orderBy('sort')->pluck('title')->all());

        $this->patch("http://localhost/admin/blocks/{$b->id}/width", ['width' => '1/3'])->assertRedirect();
        $this->assertSame('1/3', $b->fresh()->width);

        // Taking one out of a row of two leaves two blocks on their own — no
        // stale word waiting to glue A to the next block it meets.
        $this->patch("http://localhost/admin/blocks/{$b->id}/detach")->assertRedirect();
        $this->assertNull($a->fresh()->row);
        $this->assertNull($b->fresh()->row);
        $this->assertNull($b->fresh()->width);
    }

    public function test_joining_as_tabs_and_the_block_form_keeping_the_arrangement(): void
    {
        $a = $this->html('Tab one', 1);
        $b = $this->html('Tab two', 2);

        $this->patch("http://localhost/admin/blocks/{$a->id}/join", ['as' => 'tabs'])->assertRedirect();
        $this->assertNotNull($a->fresh()->tabs);
        $this->assertSame($a->fresh()->tabs, $b->fresh()->tabs);

        // The form has no tabs field any more: saving it must not take the
        // block out of its tabs.
        $this->put("http://localhost/admin/blocks/{$a->id}", ['title' => 'Tab one', 'enabled' => 1, 'input_mode' => 'form', 'settings' => ['html' => '<p>x</p>']])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.blocks.edit', $a));
        $this->assertSame($b->fresh()->tabs, $a->fresh()->tabs);
    }

    public function test_the_picker_offers_what_suits_the_zone_first(): void
    {
        // A header line: the town switch suits it, a product grid does not.
        $page = $this->get('http://localhost/admin/blocks/zone/storefront.header.top_end')->assertOk()->getContent();
        $fitting = substr($page, 0, (int) strpos($page, 'Show all block types'));

        $this->assertStringContainsString(route('admin.blocks.create', ['storefront.header.top_end', 'town']), $fitting);
        $this->assertStringNotContainsString(route('admin.blocks.create', ['storefront.header.top_end', 'products']), $fitting);
        $this->assertStringContainsString(route('admin.blocks.create', ['storefront.header.top_end', 'products']), $page, 'still one click away');
    }
}
