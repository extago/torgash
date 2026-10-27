<?php

namespace Tests\Feature\Search;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Search\Engine;
use Torgash\Search\Rules;
use Torgash\Search\SearchQuery;
use Torgash\Search\Stats;

/**
 * What customers search for, counted (PLAN §34, step 5): searches once per
 * visitor per query per day, typing never, crawlers never; a product opened
 * while it was an answer on screen is a click, and lifts it for that query;
 * popular queries in the window; the statistics screen.
 */
class SearchStatsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('m_search_documents')->delete();
        DB::table('m_search_words')->delete();
        DB::table('m_search_stats')->delete();
        DB::table('m_search_clicks')->delete();
    }

    public function test_a_search_is_counted_once_per_visitor_per_day_and_typing_never(): void
    {
        $this->product('STA-1', 'Statkettle one');
        $this->product('STA-2', 'Statkettle two');

        foreach (['s', 'st', 'stat', 'statkettle'] as $typed) {
            $this->get('http://localhost/search/suggest?q='.$typed)->assertOk();
        }
        $this->assertSame(0, (int) DB::table('m_search_stats')->sum('searches'), 'typing is not searching');

        $this->get('http://localhost/search?q=Statkettle')->assertOk();
        $this->get('http://localhost/search?q=statkettle')->assertOk();
        app(\App\Core\Settings\SettingsManager::class)->set('torgash.catalog', 'per_page_choices', '1 24');
        $this->get('http://localhost/search?q=statkettle&page=2&per_page=1')->assertOk();

        $row = DB::table('m_search_stats')->where('query', 'statkettle')->first();
        $this->assertSame(1, (int) $row->searches);
        $this->assertSame(2, (int) $row->found);
        $this->assertSame(0, (int) $row->zero);
        // No person in it: the query, the day, the counts.
        $this->assertSame(['query', 'day', 'searches', 'zero', 'found', 'clicks'], array_keys((array) $row));
    }

    public function test_a_search_that_finds_nothing_is_counted_as_such(): void
    {
        $this->get('http://localhost/search?q=nonexistentium')->assertOk();

        $this->assertSame(1, (int) DB::table('m_search_stats')->where('query', 'nonexistentium')->value('zero'));
    }

    public function test_a_crawler_is_not_counted(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1)')->get('http://localhost/search?q=statkettle')->assertOk();

        $this->assertSame(0, DB::table('m_search_stats')->count());
    }

    /** Opened while it was on screen as an answer — once; and it lifts the product for that query. */
    public function test_a_product_opened_from_the_answers_is_a_click_and_lifts_it(): void
    {
        $first = $this->product('STA-3', 'Statlamp alpha');
        $second = $this->product('STA-4', 'Statlamp beta');

        $this->get('http://localhost/search?q=statlamp')->assertOk();
        $this->assertSame($first->id, app(Engine::class)->search('statlamp')->ids[0]);

        $this->get(route('catalog.product', 'sta-4'))->assertOk();
        $this->get(route('catalog.product', 'sta-4'))->assertOk();

        $this->assertSame(1, (int) DB::table('m_search_stats')->where('query', 'statlamp')->value('clicks'));
        $this->assertSame(1, (int) DB::table('m_search_clicks')->where(['query' => 'statlamp', 'product_id' => $second->id])->value('clicks'));
        $this->assertSame($second->id, app(Engine::class)->search('statlamp')->ids[0], 'chosen for this query');
        $this->assertSame($first->id, app(Engine::class)->search('statlamp alpha')->ids[0], 'and only for this query');

        app(SettingsManager::class)->set('torgash.search', 'click_boost', false);
        $this->assertSame($first->id, app(Engine::class)->search('statlamp')->ids[0]);
    }

    /** A product opened from the WINDOW makes its query a search, though no results page was asked for. */
    public function test_a_product_opened_from_the_window_counts_the_search_and_the_click(): void
    {
        $this->product('STA-5', 'Statdesk');

        $this->get('http://localhost/search/suggest?q=statde')->assertOk();
        $this->get(route('catalog.product', 'sta-5'))->assertOk();

        $row = DB::table('m_search_stats')->where('query', 'statde')->first();
        $this->assertSame(1, (int) $row->searches);
        $this->assertSame(1, (int) $row->clicks);
    }

    public function test_a_product_opened_from_elsewhere_is_no_click(): void
    {
        $this->product('STA-6', 'Statchair');

        $this->get(route('catalog.product', 'sta-6'))->assertOk();

        $this->assertSame(0, DB::table('m_search_clicks')->count());
    }

    public function test_popular_queries_are_offered_in_the_window_only_when_they_find_something(): void
    {
        DB::table('m_search_stats')->insert([
            ['query' => 'stat kettle', 'day' => now()->toDateString(), 'searches' => 9, 'zero' => 0, 'found' => 3, 'clicks' => 0],
            ['query' => 'stat kettles black', 'day' => now()->toDateString(), 'searches' => 5, 'zero' => 0, 'found' => 1, 'clicks' => 0],
            ['query' => 'stat kettle gold', 'day' => now()->toDateString(), 'searches' => 7, 'zero' => 7, 'found' => 0, 'clicks' => 0],
            ['query' => 'stat kettle once', 'day' => now()->toDateString(), 'searches' => 1, 'zero' => 0, 'found' => 1, 'clicks' => 0],
        ]);

        $this->assertSame(['stat kettle', 'stat kettles black'], app(Stats::class)->popular(new SearchQuery('stat ket'), 5));

        $window = strip_tags($this->get('http://localhost/search/suggest?q=stat+ket')->assertOk()->getContent());
        $this->assertStringContainsString('stat kettles black', $window);
        $this->assertStringNotContainsString('gold', $window, 'a dead end is not suggested');
        $this->assertStringNotContainsString('once', $window, 'one person is not popular');

        $this->assertStringContainsString('stat kettle', $this->get('http://localhost/search/suggest?q=')->getContent(), 'before anything is typed');
    }

    public function test_counting_can_be_switched_off(): void
    {
        app(SettingsManager::class)->set('torgash.search', 'stats_enabled', false);

        $this->get('http://localhost/search?q=statkettle')->assertOk();

        $this->assertSame(0, DB::table('m_search_stats')->count());
    }

    public function test_old_statistics_are_forgotten(): void
    {
        DB::table('m_search_stats')->insert([
            ['query' => 'old', 'day' => now()->subDays(400)->toDateString(), 'searches' => 1, 'zero' => 0, 'found' => 1, 'clicks' => 0],
            ['query' => 'new', 'day' => now()->toDateString(), 'searches' => 1, 'zero' => 0, 'found' => 1, 'clicks' => 0],
        ]);

        app(Stats::class)->prune();

        $this->assertSame(['new'], DB::table('m_search_stats')->pluck('query')->all());
    }

    public function test_the_screen_lists_what_finds_nothing_with_its_fixes(): void
    {
        $this->product('STA-7', 'Rulephone Orbit');
        DB::table('m_search_stats')->insert([
            ['query' => 'mobila', 'day' => now()->toDateString(), 'searches' => 4, 'zero' => 4, 'found' => 0, 'clicks' => 0],
            ['query' => 'rulephone', 'day' => now()->toDateString(), 'searches' => 10, 'zero' => 0, 'found' => 1, 'clicks' => 3],
        ]);
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->get('http://localhost/admin/search-engine/stats?days=7')->assertOk()
            ->assertSee('mobila')
            ->assertSee(e(route('admin.search-engine.rules.index', ['kind' => 'synonyms', 'phrases' => 'mobila'])), false)
            ->assertSee('rulephone');

        // The fix, prefilled.
        $this->actingAs($admin)->get('http://localhost/admin/search-engine/rules/synonyms?phrases=mobila')->assertOk()->assertSee('>mobila</textarea>', false);

        $this->actingAs(User::factory()->create())->get('http://localhost/admin/search-engine/stats')->assertForbidden();
    }

    /** @return Product */
    protected function product(string $sku, string $name): Product
    {
        app()->forgetInstance(Rules::class);

        return Product::create([
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => 100_00, 'stock' => 10, 'status' => 'active',
        ]);
    }
}
