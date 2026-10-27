<?php

namespace Tests\Feature\Promotions;

use App\Core\Settings\SettingsManager;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Promotions\Models\Promotion;

/**
 * The small timer on a product card, among its labels:
 * only for a product in a running sale that shows a countdown and has an end,
 * counting to the same minute the sale's own timer does.
 */
class CardCountdownTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_card_in_a_timed_sale_counts_down_and_the_others_do_not(): void
    {
        Carbon::setTestNow('2026-10-26 12:00:00');
        $this->product('TIMER-IN');
        $this->sale(['ends_at' => now()->addDays(5)], 'TIMER-IN');
        $this->sale(['ends_at' => now()->addDays(2), 'show_countdown' => false], 'TIMER-IN');
        $this->product('TIMER-OUT');

        $in = $this->card('TIMER-IN');
        $this->assertStringContainsString('torgash-countdown-pill', $in);
        $this->assertStringContainsString(now()->addDays(5)->toIso8601String(), $in, 'the sale that shows a countdown, not the hidden one');

        $this->assertStringNotContainsString('torgash-countdown-pill', $this->card('TIMER-OUT'));

        app(SettingsManager::class)->set('torgash.promotions', 'card_countdown', false);
        $this->assertStringNotContainsString('torgash-countdown-pill', $this->card('TIMER-IN'));
    }

    public function test_an_open_ended_sale_has_no_timer_on_the_card(): void
    {
        $this->product('TIMER-OPEN');
        $this->sale([], 'TIMER-OPEN');

        $this->assertStringNotContainsString('torgash-countdown-pill', $this->card('TIMER-OPEN'));
    }

    protected function card(string $sku): string
    {
        $product = Product::where('sku', $sku)->withCardData()->firstOrFail();

        return view('torgash.catalog::partials.product-card', ['product' => $product, 'cardLabels' => []])->render();
    }

    protected function sale(array $attributes, string $sku): Promotion
    {
        $promotion = Promotion::create([
            'name' => ['en' => 'Card sale '.uniqid()],
            'zone' => 'catalog',
            'combine' => Promotion::REPLACE,
            'enabled' => true,
            ...$attributes,
        ]);
        $promotion->tiers()->create([
            'position' => 0, 'discount_type' => 'percent', 'discount_value' => 10,
            'conditions' => ['match' => 'all', 'rules' => [['kind' => 'product', 'operator' => 'prod_in', 'ids' => [Product::where('sku', $sku)->value('id')]]]],
        ]);

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
