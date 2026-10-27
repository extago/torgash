<?php

namespace Tests\Feature\Promotions;

use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Torgash\Api\Models\ApiToken;
use Torgash\Catalog\Models\Product;
use Torgash\Promotions\Models\Promotion;
use Torgash\Promotions\PromotionCache;

/**
 * The repeating window — "deal of the day". The dates say when the sale
 * exists, the window says when it is open, and the two are answered by the
 * clock at read time rather than materialised into periods (see the
 * migration for why older engines have to do the latter).
 *
 * Everything here is pinned with Carbon::setTestNow: a schedule test that
 * asks the real clock passes at 14:00 and fails at 23:00, which is the worst
 * kind of test to own.
 */
class PromotionScheduleTest extends TestCase
{
    /**
     * Move the clock, and forget what was worked out under the old one.
     *
     * PromotionCache is a per-REQUEST memo: in real life it is built and
     * dropped inside one short request, so "is this sale open" is asked once.
     * A test moves the clock inside a single request, so without the flush
     * every assertion after the first would be answered from the list built
     * at the first time of day.
     */
    protected function at(string $time): void
    {
        Carbon::setTestNow($time);
        app(PromotionCache::class)->flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A price that is only the sale price while the window is open. */
    public function test_a_daily_window_prices_only_inside_itself(): void
    {
        $product = $this->product('SCHED-1');
        $this->dailyPromotion(['windows' => [['days' => [], 'from' => '18:00', 'to' => '22:00']]]);

        $this->at('2026-09-08 12:00:00');
        $this->assertSame(1000_00, $product->fresh()->effectivePrice(), 'before the window the sale must not apply');

        $this->at('2026-09-08 18:30:00');
        $this->assertSame(500_00, $product->fresh()->effectivePrice(), 'inside the window the sale applies');

        $this->at('2026-09-08 22:00:00');
        $this->assertSame(1000_00, $product->fresh()->effectivePrice(), 'the closing minute is the end, not one more minute of sale');
    }

    /**
     * A night sale. The days a window is listed under are the days it STARTS
     * on, so "Friday 22:00–02:00" is still running at one in the morning on
     * Saturday — the reading a merchant means, and the one that stops them
     * having to enter two rows for one night.
     */
    public function test_a_window_crossing_midnight_stays_open_into_the_next_day(): void
    {
        $product = $this->product('SCHED-2');
        // 2026-09-11 is a Friday.
        $this->dailyPromotion(['windows' => [['days' => [5], 'from' => '22:00', 'to' => '02:00']]]);

        $this->at('2026-09-11 23:30:00');
        $this->assertSame(500_00, $product->fresh()->effectivePrice(), 'Friday night, inside the window');

        $this->at('2026-09-12 01:30:00');
        $this->assertSame(500_00, $product->fresh()->effectivePrice(), 'Saturday morning, still the same window');

        $this->at('2026-09-12 03:00:00');
        $this->assertSame(1000_00, $product->fresh()->effectivePrice(), 'after it closed');

        $this->at('2026-09-12 23:30:00');
        $this->assertSame(1000_00, $product->fresh()->effectivePrice(), 'Saturday night is not Friday night');
    }

    /**
     * The state a scheduled promotion is in between windows. Not 'running'
     * (the storefront charges full price) and not 'ended' (it is back at
     * six) — a merchant reading the list has to be able to tell.
     */
    public function test_a_promotion_between_windows_reads_as_paused_and_says_when_it_returns(): void
    {
        $promotion = $this->dailyPromotion(['windows' => [['days' => [], 'from' => '18:00', 'to' => '22:00']]]);

        $this->at('2026-09-08 12:00:00');
        $promotion = $promotion->fresh();

        $this->assertSame('paused', $promotion->status());
        $this->assertSame('2026-09-08 18:00:00', $promotion->opensAt()->format('Y-m-d H:i:s'));

        $this->at('2026-09-08 19:00:00');
        $this->assertSame('running', $promotion->fresh()->status());
    }

    /**
     * What a countdown counts to: whichever comes first, today's close or the
     * end of the sale. A timer that runs past the moment the price changes
     * teaches customers that the timer is decoration.
     */
    public function test_the_countdown_target_is_the_earlier_of_the_window_and_the_end_date(): void
    {
        $promotion = $this->dailyPromotion(['windows' => [['days' => [], 'from' => '18:00', 'to' => '22:00']]]);

        $this->at('2026-09-08 19:00:00');
        $this->assertSame('2026-09-08 22:00:00', $promotion->fresh()->countdownEndsAt()->format('Y-m-d H:i:s'));

        // The sale itself ends before tonight's window does.
        $promotion->update(['ends_at' => '2026-09-08 20:00:00']);
        $this->assertSame('2026-09-08 20:00:00', $promotion->fresh()->countdownEndsAt()->format('Y-m-d H:i:s'));

        // No window and no end date: nothing to count.
        $promotion->update(['schedule' => null, 'ends_at' => null]);
        $this->assertNull($promotion->fresh()->countdownEndsAt());
    }

    /**
     * A paused sale counts down to nothing. It has an end date like any other,
     * and counting to it while the shop charges full price is a timer beside
     * goods that are not on offer — worse than no timer at all.
     */
    public function test_a_paused_sale_has_nothing_to_count_down_to(): void
    {
        $promotion = $this->dailyPromotion(['windows' => [['days' => [], 'from' => '18:00', 'to' => '22:00']]]);
        $promotion->update(['ends_at' => '2026-09-30 00:00:00', 'published' => true, 'slug' => 'paused-sale']);

        $this->at('2026-09-08 12:00:00');

        $this->assertSame('paused', $promotion->fresh()->status());
        $this->assertNull($promotion->fresh()->countdownEndsAt());

        $this->get('http://localhost/sale/paused-sale')
            ->assertOk()
            ->assertDontSee(__('Ends in'))
            ->assertSee(__('Opens :date', ['date' => $promotion->fresh()->opensAt()->diffForHumans()]));
    }

    /** A promotion with no windows is exactly what it was before this existed. */
    public function test_no_window_means_open_whenever_the_dates_say(): void
    {
        $product = $this->product('SCHED-3');
        $this->dailyPromotion(null);

        $this->at('2026-09-08 03:17:00');
        $this->assertSame(500_00, $product->fresh()->effectivePrice());
    }

    /** The form's rows reach the database normalised, and a half-filled one does not reach it at all. */
    public function test_the_admin_form_saves_and_normalises_windows(): void
    {
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->post('http://localhost/admin/promotions', [
            'name' => 'Happy hour',
            'zone' => 'catalog',
            'combine' => 'replace',
            'enabled' => '1',
            'schedule' => ['windows' => [
                ['days' => ['5', '5', '1', '99'], 'from' => '18:00:00', 'to' => '22:00'],
                ['days' => ['2'], 'from' => '', 'to' => ''],
            ]],
            'tiers' => [['match' => 'all', 'discount_type' => 'percent', 'discount_value' => '50', 'rules' => []]],
        ])->assertRedirect();

        $promotion = Promotion::latest('id')->firstOrFail();
        $windows = $promotion->scheduleWindows()->toArray();

        $this->assertCount(1, $windows, 'a window with no times is not a window');
        $this->assertSame([1, 5], $windows[0]['days'], 'deduplicated, sorted, and a nonsense day dropped');
        $this->assertSame('18:00', $windows[0]['from'], 'seconds a browser may send are normalised away');
        $this->assertSame('22:00', $windows[0]['to']);
    }

    /** The list screen's four states became five, and the API answers with the same word. */
    public function test_the_api_tells_paused_from_running(): void
    {
        $paused = $this->dailyPromotion(['windows' => [['days' => [], 'from' => '18:00', 'to' => '22:00']]]);
        $always = $this->dailyPromotion(null);

        $this->at('2026-09-08 12:00:00');

        $running = $this->apiGet('/api/v2/admin/promotions?status=running')->assertOk();
        $this->assertSame([$always->id], array_column($running->json('data'), 'id'));

        $stopped = $this->apiGet('/api/v2/admin/promotions?status=paused')->assertOk();
        $this->assertSame([$paused->id], array_column($stopped->json('data'), 'id'));
        $this->assertSame('paused', $stopped->json('data.0.status'));
        $this->assertNotNull($stopped->json('data.0.schedule'), 'the window is answered in words, not as raw rules');
    }

    protected function apiGet(string $uri)
    {
        [, $token] = ApiToken::issue(User::role('admin')->firstOrFail(), 'Tests');

        return $this->json('GET', 'http://localhost'.$uri, [], ['Authorization' => 'Bearer '.$token]);
    }

    protected function dailyPromotion(?array $schedule): Promotion
    {
        $promotion = Promotion::create([
            'name' => ['en' => 'Deal of the day'],
            'zone' => 'catalog',
            'combine' => Promotion::REPLACE,
            'enabled' => true,
            'schedule' => $schedule,
        ]);
        $promotion->tiers()->create([
            'position' => 0, 'conditions' => null, 'discount_type' => 'percent', 'discount_value' => 50,
        ]);

        return $promotion;
    }

    protected function product(string $sku): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku),
            'price' => 1000_00, 'stock' => 5, 'status' => 'active',
        ]);
    }
}
