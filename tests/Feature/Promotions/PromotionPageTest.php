<?php

namespace Tests\Feature\Promotions;

use App\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Promotions\Models\Promotion;

/**
 * The sale's own page: which products it lists, who can reach it, and what a
 * page whose sale is over says.
 *
 * The listing is where the two condition vocabularies meet — a tier's rules
 * are asked in SQL here and of one loaded product when pricing, and the two
 * must never disagree: a page that lists a product the cart then charges full
 * price for is worse than no page.
 */
class PromotionPageTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Exactly the products a tier matches — no more, and priced as the sale says. */
    public function test_the_page_lists_what_the_sale_covers(): void
    {
        $category = Category::create(['name' => ['en' => 'Sale cat'], 'slug' => 'page-sale-cat']);
        $inSale = $this->product('PAGE-1', 1000_00);
        $inSale->categories()->attach($category);
        $this->product('PAGE-2', 1000_00);

        $promotion = $this->sale(['slug' => 'winter', 'published' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => [
                ['kind' => 'category', 'operator' => 'cat_in', 'ids' => [$category->id]],
            ]],
            'discount_type' => 'percent', 'discount_value' => 25,
        ]);

        $response = $this->get('http://localhost/sale/winter')->assertOk();

        $response->assertSee('PAGE-1');
        $response->assertDontSee('PAGE-2');
        // The sale price, not the list price: the page and the price come from
        // the same tiers, and a listing showing 1000.00 would be advertising a
        // sale that is not on.
        $response->assertSee('750');
    }

    /**
     * The page is the catalogue's listing over the sale's products: a panel
     * whose Category block counts each category with what is under it, and
     * `?in=` narrowing to one (subtree and all) with a chip to step back.
     */
    public function test_the_sale_lists_its_products_with_the_catalogues_panel(): void
    {
        $kitchen = Category::create(['name' => ['en' => 'Kitchen'], 'slug' => 'sp-kitchen']);
        $pans = Category::create(['name' => ['en' => 'Pans'], 'slug' => 'sp-pans', 'parent_id' => $kitchen->id]);
        $books = Category::create(['name' => ['en' => 'Books'], 'slug' => 'sp-books']);
        $this->product('SP-PAN', 50_00)->categories()->attach($pans);
        $this->product('SP-POT', 60_00)->categories()->attach($kitchen);
        $this->product('SP-BOOK', 20_00)->categories()->attach($books);

        $promotion = $this->sale(['slug' => 'mixed', 'published' => true]);
        $promotion->tiers()->create(['position' => 0, 'conditions' => null, 'discount_type' => 'percent', 'discount_value' => 10]);

        $page = $this->get('http://localhost/sale/mixed')->assertOk()->getContent();
        $this->assertStringContainsString('data-catalog-filters', $page);
        $this->assertMatchesRegularExpression('#in=sp-kitchen"[^>]*>\s*<span[^>]*>Kitchen</span>\s*<span[^>]*>2<#', $page, 'the pans are under the kitchen');

        $narrowed = $this->get('http://localhost/sale/mixed?in=sp-kitchen')->assertOk();
        $narrowed->assertSee('SP-PAN')->assertSee('SP-POT')->assertDontSee('SP-BOOK');
        $narrowed->assertSee('Category: Kitchen');
        $narrowed->assertSee('<input type="hidden" name="in" value="sp-kitchen">', false);

        // A category nobody knows narrows to nothing, never to everything.
        $this->get('http://localhost/sale/mixed?in=nope')->assertOk()->assertDontSee('SP-BOOK');
    }

    /** A tier with no conditions is the sitewide one, and its page says so. */
    public function test_a_tier_with_no_conditions_lists_everything(): void
    {
        $this->product('PAGE-3', 500_00);
        $this->product('PAGE-4', 500_00);

        $promotion = $this->sale(['slug' => 'everything', 'published' => true]);
        $promotion->tiers()->create(['position' => 0, 'conditions' => null, 'discount_type' => 'percent', 'discount_value' => 10]);

        $response = $this->get('http://localhost/sale/everything')->assertOk();
        $response->assertSee('PAGE-3');
        $response->assertSee('PAGE-4');
    }

    /**
     * A rule about the VISITOR cannot be a where() clause, so it decides
     * whether the query is asked at all: a wholesale-only sale lists nothing
     * for a guest and its whole catalogue for a member of that group.
     */
    public function test_a_customer_group_rule_gates_the_whole_listing(): void
    {
        $group = CustomerGroup::create(['name' => ['en' => 'Wholesale page'], 'discount_percent' => 0]);
        $product = $this->product('PAGE-5', 800_00);

        $promotion = $this->sale(['slug' => 'wholesale-only', 'published' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => [
                ['kind' => 'usergroup', 'operator' => 'group_in', 'ids' => [$group->id]],
            ]],
            'discount_type' => 'percent', 'discount_value' => 50,
        ]);

        $this->get('http://localhost/sale/wholesale-only')->assertOk()->assertDontSee('PAGE-5');

        $member = User::create([
            'name' => 'Wholesaler', 'email' => 'wholesale-page@example.com',
            'password' => bcrypt('secret'), 'customer_group_id' => $group->id,
        ]);

        $this->actingAs($member)->get('http://localhost/sale/wholesale-only')->assertOk()->assertSee($product->sku);
    }

    /**
     * A page outlives its sale. The link in last week's newsletter has to land
     * somewhere that says "this ended", not on a 404.
     */
    public function test_an_ended_sale_still_has_a_page_and_says_so(): void
    {
        $this->sale(['slug' => 'over', 'published' => true, 'ends_at' => now()->subDay()]);

        $this->get('http://localhost/sale/over')->assertOk()->assertSee(__('This sale has ended.'));
    }

    /** No page unless the merchant asked for one. */
    public function test_an_unpublished_sale_has_no_page(): void
    {
        $this->sale(['slug' => 'quiet', 'published' => false]);

        $this->get('http://localhost/sale/quiet')->assertNotFound();
    }

    /** The index lists the published sales, running ones first. */
    public function test_the_index_lists_published_sales(): void
    {
        $this->sale(['name' => ['en' => 'Loud sale'], 'slug' => 'loud', 'published' => true]);
        $this->sale(['name' => ['en' => 'Quiet sale'], 'slug' => 'quiet-2', 'published' => false]);

        $response = $this->get('http://localhost/sale')->assertOk();
        $response->assertSee('Loud sale');
        $response->assertDontSee('Quiet sale');
    }

    /** The countdown is rendered against the sale's real deadline, in ISO 8601. */
    public function test_a_running_sale_with_a_deadline_renders_a_countdown(): void
    {
        $this->at('2026-09-08 12:00:00');
        $this->sale(['slug' => 'ticking', 'published' => true, 'ends_at' => '2026-09-09 23:00:00']);

        $this->get('http://localhost/sale/ticking')->assertOk()->assertSee('2026-09-09T23:00:00', false);
    }

    /** ...and a sale with no deadline has nothing to count. */
    public function test_a_sale_with_no_deadline_shows_no_countdown(): void
    {
        $this->sale(['slug' => 'endless', 'published' => true]);

        $this->get('http://localhost/sale/endless')->assertOk()->assertDontSee(__('Ends in'));
    }

    /** The product page says which sale its price comes from, and links to it. */
    public function test_the_product_page_names_the_sale_behind_the_price(): void
    {
        $product = $this->product('PAGE-6', 600_00);

        $promotion = $this->sale(['name' => ['en' => 'Named sale'], 'slug' => 'named', 'published' => true]);
        $promotion->tiers()->create(['position' => 0, 'conditions' => null, 'discount_type' => 'percent', 'discount_value' => 20]);

        $response = $this->get('http://localhost/product/'.$product->slug)->assertOk();
        $response->assertSee('Named sale');
        $response->assertSee('/sale/named', false);
        // Its offer on the tile, and no "About the sale" for a sale with nothing more to say.
        $response->assertSee('−20%');
        $response->assertDontSee(__('About the sale'));
    }

    /** "About the sale" opens a popup with the shop's words, the period and the way to the sale's page; a dated sale gets the bar of how much has gone. */
    public function test_the_product_page_opens_the_sale_in_a_popup(): void
    {
        $this->at('2026-10-11 00:00:00');
        $product = $this->product('PAGE-7', 600_00);

        $promotion = $this->sale([
            'name' => ['en' => 'Dated sale'], 'slug' => 'dated', 'published' => true, 'show_countdown' => true,
            'description' => ['en' => '<p>Everything warm for less.</p>'],
            'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-21 00:00:00',
        ]);
        $promotion->tiers()->create(['position' => 0, 'conditions' => null, 'discount_type' => 'percent', 'discount_value' => 20]);

        $html = $this->get('http://localhost/product/'.$product->slug)->assertOk()->getContent();
        $this->assertStringContainsString(__('About the sale'), $html);
        $this->assertStringContainsString('id="modal-promo-'.$promotion->id.'"', $html);
        $this->assertStringContainsString('Everything warm for less.', $html);
        $this->assertStringContainsString(__('Every product in the sale'), $html);
        $this->assertStringContainsString('width: 50%', $html, 'half of the sale has gone');
    }

    /** The tile is the shop's choice — its words, an icon, nothing — in the colour it picked; a choice with nothing behind it falls back to the worked-out one. */
    public function test_the_shop_chooses_the_tile_and_the_colour(): void
    {
        $product = $this->product('PAGE-8', 600_00);
        $promotion = $this->sale(['name' => ['en' => 'Chosen look'], 'slug' => 'chosen', 'published' => true]);
        $promotion->tiers()->create(['position' => 0, 'conditions' => null, 'discount_type' => 'percent', 'discount_value' => 20]);
        $page = fn () => $this->get('http://localhost/product/'.$product->slug)->assertOk()->getContent();

        $this->assertSame(['text', '−20%'], $promotion->tile('−20%'), 'worked out: the offer');

        $promotion->update(['badge' => 'text', 'badge_text' => ['en' => '1+1'], 'tint' => 'blue']);
        $html = $page();
        $this->assertStringContainsString('>1+1</b>', $html);
        $this->assertStringContainsString('--promo-hue: 250', $html);

        $promotion->update(['badge' => 'picture']);
        $this->assertSame(['text', '−20%'], $promotion->fresh()->tile('−20%'), 'no picture uploaded: worked out instead');

        $promotion->update(['badge' => 'icon', 'badge_icon' => 'gift']);
        $this->assertSame(['icon', 'gift'], $promotion->fresh()->tile('−20%'));

        $promotion->update(['badge' => 'none']);
        $this->assertNull($promotion->fresh()->tile('−20%'));
        $this->assertStringNotContainsString('torgash-promo-note-tile', $page());
    }

    /** The admin form keeps the look; an icon the pack does not have is not kept. */
    public function test_the_admin_saves_the_look(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $this->actingAs($admin)->post('http://localhost/admin/promotions', [
            'name' => 'Look sale', 'zone' => 'catalog', 'combine' => 'replace', 'enabled' => '1',
            'badge' => 'icon', 'badge_icon' => 'no-such-icon-anywhere', 'badge_text' => 'Gift', 'tint' => 'green',
            'tiers' => [['match' => 'all', 'discount_type' => 'percent', 'discount_value' => '10', 'rules' => []]],
        ])->assertRedirect();

        $saved = Promotion::where('name->en', 'Look sale')->firstOrFail();
        $this->assertSame('icon', $saved->badge);
        $this->assertNull($saved->badge_icon);
        $this->assertSame('Gift', $saved->t('badge_text'));
        $this->assertSame(150, $saved->hue());
    }

    /** Public pages belong in the sitemap; catalog gained the filter that lets a module say so. */
    public function test_published_sales_appear_in_the_sitemap(): void
    {
        $this->sale(['slug' => 'mapped', 'published' => true]);
        $this->sale(['slug' => 'unmapped', 'published' => false]);

        $response = $this->get('http://localhost/sitemap.xml')->assertOk();
        $response->assertSee('/sale/mapped', false);
        $response->assertDontSee('/sale/unmapped', false);
    }

    /** The address is derived when the merchant does not type one, and never collides. */
    public function test_the_admin_derives_a_unique_slug(): void
    {
        $admin = User::role('admin')->firstOrFail();

        foreach ([1, 2] as $ignored) {
            $this->actingAs($admin)->post('http://localhost/admin/promotions', [
                'name' => 'Чёрная пятница',
                'zone' => 'catalog',
                'combine' => 'replace',
                'enabled' => '1',
                'published' => '1',
                'tiers' => [['match' => 'all', 'discount_type' => 'percent', 'discount_value' => '30', 'rules' => []]],
            ])->assertRedirect();
        }

        $slugs = Promotion::orderBy('id')->pluck('slug')->filter()->values()->all();

        $this->assertContains('cernaia-piatnica', $slugs, 'a Cyrillic name transliterates into an address');
        $this->assertContains('cernaia-piatnica-2', $slugs, 'the second sale of the same name gets its own address');
    }

    protected function at(string $time): void
    {
        Carbon::setTestNow($time);
        app(\Torgash\Promotions\PromotionCache::class)->flush();
    }

    protected function sale(array $attributes): Promotion
    {
        return Promotion::create([
            'name' => ['en' => 'Page sale'],
            'zone' => 'catalog',
            'combine' => Promotion::REPLACE,
            'enabled' => true,
            ...$attributes,
        ]);
    }

    protected function product(string $sku, int $price): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku),
            'price' => $price, 'stock' => 5, 'status' => 'active',
        ]);
    }
}
