<?php

namespace Tests\Feature\Blocks;

use Tests\TestCase;
use Torgash\Blocks\Models\Block;
use Torgash\Blocks\ZoneRenderer;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Catalog\Support\RecentlyViewed;

/**
 * A block that is the visitor's own and need not be drawn with the page
 * ("Viewed recently"): the page says whether there is anything to see, and
 * the block comes by its own request when its tab is opened or it is scrolled
 * to. How much that saves on a page kept for guests is PageCacheStorefrontTest's.
 */
class WaitingBlocksTest extends TestCase
{
    protected string $zone = 'storefront.home.content';

    protected Product $here;

    protected Product $seen;

    /** Where the tabs that do not wait take their products from. */
    protected string $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();

        Block::where('zone', $this->zone)->delete();

        $this->here = $this->product('HERE');
        $this->seen = $this->product('SEEN');

        $category = Category::create(['name' => ['en' => 'Elsewhere'], 'slug' => 'wb-elsewhere', 'enabled' => true]);
        app(CategoryTree::class)->flush();
        $this->product('THIRD')->categories()->sync([$category->id]);
        $this->elsewhere = 'category:'.$category->id;
    }

    protected function product(string $sku, string $status = 'active'): Product
    {
        return Product::create([
            'sku' => 'WB-'.$sku, 'name' => ['en' => 'Product '.$sku], 'slug' => 'wb-'.strtolower($sku),
            'price' => 100_00, 'stock' => 5, 'status' => $status,
        ]);
    }

    protected function block(string $heading, string $source, int $sort, array $extra = []): Block
    {
        return Block::create(['zone' => $this->zone, 'type' => 'products', 'title' => $heading, 'enabled' => true, 'sort' => $sort,
            'rules_match' => 'all', 'settings' => ['heading' => $heading, 'source' => $source, 'limit' => 4], ...$extra]);
    }

    /** @return list<Block> two tabs of the page's own and one that waits, last */
    protected function row(): array
    {
        return [
            $this->block('One', $this->elsewhere, 1, ['tabs' => 't']),
            $this->block('Two', $this->elsewhere, 2, ['tabs' => 't']),
            $this->block('Seen', 'viewed', 3, ['tabs' => 't']),
        ];
    }

    protected function draw(array $blocks): string
    {
        return app(ZoneRenderer::class)->render($blocks, ['product' => $this->here]);
    }

    /** @param list<int> $ids */
    protected function history(array $ids): void
    {
        session([RecentlyViewed::SESSION_KEY => $ids]);
    }

    protected function laterUrl(Block $block, string $query = ''): string
    {
        return 'http://localhost/blocks/'.$block->id.'/later'.$query;
    }

    public function test_a_tab_that_waits_is_a_name_and_a_panel_with_no_cards_in_it(): void
    {
        $this->history([$this->seen->id]);
        $blocks = $this->row();

        $html = $this->draw($blocks);

        $this->assertStringContainsString('<span>Seen</span>', $html);
        $this->assertStringContainsString('value="2" class="sr-only"', $html, 'numbered as its panel is');
        $this->assertStringContainsString('data-tab-panel="2"', $html);
        $this->assertStringContainsString('hx-trigger="intersect once"', $html, 'a hidden panel asks only once it is shown');
        $this->assertStringContainsString('/blocks/'.$blocks[2]->id.'/later?product='.$this->here->id, $html);
        $this->assertStringContainsString('Product THIRD', $html, 'the tabs of its own are drawn');
        $this->assertStringNotContainsString('Product SEEN', $html, 'the cards wait for the tab');
    }

    public function test_nothing_to_look_back_at_is_no_tab(): void
    {
        $blocks = $this->row();
        $taken = $this->product('GONE', 'draft');

        $cases = ['no history' => [], 'only this very product' => [$this->here->id], 'all taken off sale since' => [$taken->id]];

        foreach ($cases as $case => $history) {
            $this->history($history);

            $this->assertStringNotContainsString('<span>Seen</span>', $this->draw($blocks), $case);
        }
    }

    /** The tab open at the start has to exist for everybody. */
    public function test_the_tab_open_at_the_start_is_never_the_one_that_waits(): void
    {
        $this->history([$this->seen->id]);

        $html = $this->draw([
            $this->block('Seen', 'viewed', 1, ['tabs' => 't']),
            $this->block('One', $this->elsewhere, 2, ['tabs' => 't']),
            $this->block('Two', $this->elsewhere, 3, ['tabs' => 't']),
        ]);

        $this->assertLessThan(strpos($html, '<span>One</span>'), strpos($html, '<span>Seen</span>'), 'it keeps its place in the row');
        $this->assertMatchesRegularExpression('/value="1" class="sr-only"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="0" class="sr-only"\s+checked/', $html);
    }

    /**
     * The usual case on a shop whose categories hold one model's variations:
     * "Similar" is empty, and what is left is one tab and the one that waits.
     * It has to wait there too, or the whole row is drawn again for everyone.
     */
    public function test_beside_a_single_other_tab_it_still_waits(): void
    {
        $blocks = [
            $this->block('One', $this->elsewhere, 1, ['tabs' => 't']),
            $this->block('Empty', 'category:999999', 2, ['tabs' => 't']),
            $this->block('Seen', 'viewed', 3, ['tabs' => 't']),
        ];

        $this->history([$this->seen->id]);
        $html = $this->draw($blocks);
        $this->assertStringContainsString('<span>One</span>', $html);
        $this->assertStringContainsString('<span>Seen</span>', $html);
        $this->assertStringContainsString('intersect once', $html);
        $this->assertStringNotContainsString('Product SEEN', $html);
        $this->assertMatchesRegularExpression('/value="0" class="sr-only"\s+checked/', $html);

        // The same row, one name: app.css draws it as a heading.
        $this->history([]);
        $html = $this->draw($blocks);
        $this->assertStringContainsString('<span>One</span>', $html);
        $this->assertStringNotContainsString('<span>Seen</span>', $html);
    }

    /** Nothing else in the row: open from the start for whoever has something to see. */
    public function test_with_no_other_tab_it_is_open_for_a_visitor_with_a_history_and_absent_for_the_rest(): void
    {
        $block = $this->block('Seen', 'viewed', 1, ['tabs' => 't']);

        $this->history([$this->seen->id]);
        $html = $this->draw([$block]);
        $this->assertMatchesRegularExpression('/value="0" class="sr-only"\s+checked/', $html);
        $this->assertStringContainsString('intersect once', $html);
        $this->assertStringNotContainsString('Product SEEN', $html);

        $this->history([]);
        $this->assertStringNotContainsString('<span>Seen</span>', $this->draw([$block]));
    }

    public function test_standing_alone_it_is_a_place_for_a_visitor_with_a_history_and_nothing_for_the_rest(): void
    {
        $block = $this->block('Seen', 'viewed', 1);

        $this->assertSame('', trim($this->draw([$block])));

        $this->history([$this->seen->id]);
        $html = $this->draw([$block]);

        $this->assertStringContainsString('hx-trigger="intersect once"', $html);
        $this->assertStringContainsString('/blocks/'.$block->id.'/later?product='.$this->here->id, $html);
        $this->assertStringNotContainsString('Product SEEN', $html);
    }

    /** A rule asks about the page the block stands on, and the later request is not that page. */
    public function test_a_block_with_display_rules_or_in_a_row_is_drawn_where_it_stands(): void
    {
        $this->history([$this->seen->id]);

        $ruled = $this->draw([
            $this->block('One', $this->elsewhere, 1, ['tabs' => 't']),
            $this->block('Two', $this->elsewhere, 2, ['tabs' => 't']),
            $this->block('Seen', 'viewed', 3, ['tabs' => 't', 'rules' => [['kind' => 'visitor', 'operator' => 'guest']]]),
        ]);
        $this->assertStringContainsString('Product SEEN', $ruled);

        $column = $this->draw([
            $this->block('Left', $this->elsewhere, 1, ['row' => 'r']),
            $this->block('Right', 'viewed', 2, ['row' => 'r']),
        ]);
        $this->assertStringContainsString('Product SEEN', $column);
        $this->assertStringNotContainsString('intersect once', $column);
    }

    public function test_the_address_draws_what_this_visitor_saw_without_the_page_they_are_on(): void
    {
        $tab = $this->row()[2];

        $answer = $this->withSession([RecentlyViewed::SESSION_KEY => [$this->seen->id, $this->here->id]])
            ->get($this->laterUrl($tab, '?product='.$this->here->id));

        $answer->assertOk()->assertSee('Product SEEN')->assertDontSee('Product HERE');
        $answer->assertDontSee('<html', false);
        $answer->assertDontSee('Seen</h2>', false);

        // Standing alone, nobody else draws its heading.
        $alone = $this->block('Alone in the zone', 'viewed', 9);
        $this->get($this->laterUrl($alone))->assertSee('Alone in the zone');
    }

    public function test_a_visitor_with_nothing_gets_nothing_and_a_nonsense_key_breaks_nothing(): void
    {
        $tab = $this->row()[2];

        $this->assertSame('', trim((string) $this->get($this->laterUrl($tab))->assertOk()->getContent()));
        $this->flushSession();
        $this->get($this->laterUrl($tab, '?product=999999999'))->assertOk();
        $this->get($this->laterUrl($tab, '?product=abc'))->assertOk();
    }

    public function test_the_address_answers_only_for_a_block_that_waits(): void
    {
        [$other, , $tab] = $this->row();

        $this->get($this->laterUrl($other))->assertNotFound();

        $tab->update(['enabled' => false]);
        $this->get($this->laterUrl($tab))->assertNotFound();

        $this->get('http://localhost/blocks/99999999/later')->assertNotFound();
        $this->get('http://localhost/blocks/nonsense/later')->assertNotFound();

        $ruled = $this->block('Ruled', 'viewed', 5, ['rules' => [['kind' => 'visitor', 'operator' => 'guest']]]);
        $this->get($this->laterUrl($ruled))->assertNotFound();
    }
}
