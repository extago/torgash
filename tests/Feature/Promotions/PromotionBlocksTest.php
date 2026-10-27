<?php

namespace Tests\Feature\Promotions;

use Illuminate\Support\Carbon;
use Tests\TestCase;
use Torgash\Blocks\Models\Block;
use Torgash\Blocks\ZoneRenderer;
use Torgash\Catalog\Models\Product;
use Torgash\Promotions\Models\Promotion;

/**
 * The two blocks a shop puts its sales on a page with — "Deal with a
 * countdown" and "Current promotions". What they must never do is show a sale
 * that is not on, or count to a different minute than the sale itself.
 */
class PromotionBlocksTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * None picked: of the running sales, the one that CLOSES first — a daily
     * window ending at midnight beats a sale that runs for another week — and
     * its timer counts to that midnight.
     */
    public function test_the_deal_is_the_running_sale_that_closes_soonest(): void
    {
        Carbon::setTestNow('2026-10-09 15:00:00');
        $this->product('DEAL-1');

        $this->sale('Week-long sale', ['ends_at' => now()->addWeek()]);
        $this->sale('Deal of the day', ['schedule' => ['windows' => [['days' => [], 'from' => '00:00', 'to' => '00:00']]]]);

        $html = $this->block('promotion_deal', []);

        $this->assertStringContainsString('Deal of the day', $html);
        $this->assertStringNotContainsString('Week-long sale', $html);
        $this->assertStringContainsString(Carbon::parse('2026-10-10 00:00:00')->toIso8601String(), $html);
        $this->assertStringContainsString('torgash-countdown-boxes', $html);
        $this->assertStringContainsString('DEAL-1', $html);
        $this->assertStringContainsString('−15%', $html);
    }

    /** A picked sale outside its window is not on: the block prints nothing rather than a timer beside full prices. */
    public function test_a_paused_or_open_ended_sale_makes_no_deal(): void
    {
        Carbon::setTestNow('2026-10-09 10:00:00');
        $this->product('DEAL-2');

        $evening = $this->sale('Evening sale', ['schedule' => ['windows' => [['days' => [], 'from' => '18:00', 'to' => '22:00']]]]);
        $forever = $this->sale('Forever sale', []);

        $this->assertSame('', trim($this->block('promotion_deal', ['promotion' => (string) $evening->id])));
        // Running, but with no end there is nothing to count down to.
        $this->assertSame('', trim($this->block('promotion_deal', ['promotion' => (string) $forever->id])));
    }

    /**
     * The cards: running first, then the one about to open with when it
     * opens; never what is over, never a sale without a page. Days are
     * counted when more than one is left.
     */
    public function test_the_cards_show_what_is_on_or_coming_and_never_what_is_over(): void
    {
        Carbon::setTestNow('2026-10-09 10:00:00');

        $this->sale('Evening sale', ['slug' => 'evening', 'published' => true,
            'schedule' => ['windows' => [['days' => [], 'from' => '18:00', 'to' => '22:00']]]]);
        $this->sale('Long sale', ['slug' => 'long', 'published' => true, 'ends_at' => now()->addDays(22)]);
        $this->sale('Old sale', ['slug' => 'old', 'published' => true, 'ends_at' => now()->subDay()]);
        $this->sale('Pageless sale', ['ends_at' => now()->addDays(3)]);

        $html = $this->block('promotion_cards', ['heading' => 'On sale now']);

        $this->assertStringContainsString('On sale now', $html);
        $this->assertStringNotContainsString('Old sale', $html);
        $this->assertStringNotContainsString('Pageless sale', $html);
        $this->assertLessThan(strpos($html, 'Evening sale'), strpos($html, 'Long sale'), 'running before paused');
        $this->assertMatchesRegularExpression('/<b>22<\/b>/', $html);
        $this->assertStringContainsString('every day 18:00–22:00', $html);
        $this->assertStringContainsString('/sale/evening"', $html);
    }

    /** A schedule the shop does not tell: the sale still runs by it, the card says nothing of "every day". */
    public function test_a_hidden_schedule_is_not_told(): void
    {
        Carbon::setTestNow('2026-10-09 10:00:00');

        $this->sale('Deal of the day', ['slug' => 'deal', 'published' => true, 'show_schedule' => false,
            'schedule' => ['windows' => [['days' => [], 'from' => '00:00', 'to' => '00:00']]]]);

        $html = $this->block('promotion_cards', []);

        $this->assertStringContainsString('Deal of the day', $html);
        $this->assertStringNotContainsString('every day', $html);
        $this->assertStringNotContainsString('torgash-promo-when', $html);
    }

    /** No sale has a page: the block is not drawn at all. */
    public function test_no_cards_without_sales(): void
    {
        $this->assertSame('', trim($this->block('promotion_cards', [])));
    }

    protected function block(string $type, array $settings): string
    {
        return app(ZoneRenderer::class)->block(new Block(['type' => $type, 'settings' => $settings]));
    }

    protected function sale(string $name, array $attributes): Promotion
    {
        $promotion = Promotion::create([
            'name' => ['en' => $name],
            'zone' => 'catalog',
            'combine' => Promotion::REPLACE,
            'enabled' => true,
            ...$attributes,
        ]);
        $promotion->tiers()->create(['position' => 0, 'conditions' => null, 'discount_type' => 'percent', 'discount_value' => 15]);

        return $promotion;
    }

    protected function product(string $sku): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku),
            'price' => 100_00, 'stock' => 5, 'status' => 'active',
        ]);
    }
}
