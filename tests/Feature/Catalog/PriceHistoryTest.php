<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Pricing\PriceHistory;

/**
 * The lowest price before a discount (PLAN §36, the Omnibus rule): the shop's
 * own history of the PUBLIC price, a reference derived from it, and the one
 * setting that decides what the struck-through number is — everywhere.
 */
class PriceHistoryTest extends TestCase
{
    public function test_a_price_is_an_interval_and_the_same_price_writes_nothing(): void
    {
        $product = $this->product('PH-1', 100_00);
        $history = app(PriceHistory::class);

        $this->assertSame(1, $this->rows($product), 'a new product starts its history');
        $this->assertFalse($history->record($product->fresh()), 'nothing moved, nothing written');

        $product->update(['price' => 90_00]);

        $rows = DB::table('m_catalog_price_history')->where('product_id', $product->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[0]->ends_at, 'the old price closed');
        $this->assertNull($rows[1]->ends_at, 'the new one is open');
        $this->assertSame(90_00, (int) $rows[1]->price);
    }

    /** The lowest of the window BEFORE the current price began — not today's window. */
    public function test_the_reference_is_the_lowest_of_the_window_before_the_current_price(): void
    {
        $product = $this->product('PH-2', 100_00);
        $this->history($product, [
            [120_00, '-70 days', '-40 days'], // out of the window before "now-10"
            [95_00, '-40 days', '-25 days'],   // in it: the lowest
            [110_00, '-25 days', '-10 days'],  // in it
            [80_00, '-10 days', null],         // the current price
        ]);

        app(PriceHistory::class)->refresh($product->id);

        $this->assertSame(95_00, $product->fresh()->prior_lowest_price);
    }

    public function test_no_history_before_the_current_price_claims_nothing(): void
    {
        app(SettingsManager::class)->set('torgash.catalog', 'old_price_source', 'lowest');
        $product = $this->product('PH-3', 100_00, ['list_price' => 200_00]);

        $this->assertNull($product->fresh()->prior_lowest_price);
        $this->assertSame(0, $product->fresh()->discountPercent(), 'a typed RRP is not a price the product had');
    }

    /** One setting, and every struck-through number follows: the product, its "−X%", the "on sale" box. */
    public function test_the_setting_decides_what_a_discount_is_off(): void
    {
        $product = $this->product('PH-4', 80_00, ['list_price' => 200_00]);
        $this->history($product, [[100_00, '-20 days', '-1 day'], [80_00, '-1 day', null]]);
        app(PriceHistory::class)->refresh($product->id);

        $this->assertSame(200_00, $product->fresh()->oldPrice(), 'by default the RRP, as before');
        $this->assertSame(60, $product->fresh()->discountPercent());

        app(SettingsManager::class)->set('torgash.catalog', 'old_price_source', 'lowest');
        $this->assertSame(100_00, $product->fresh()->oldPrice());
        $this->assertSame(20, $product->fresh()->discountPercent());

        $this->product('PH-5', 80_00, ['list_price' => 200_00]); // an RRP, and no history
        app(SettingsManager::class)->set('torgash.catalog', 'filter_flags', ['in_stock', 'on_sale']);
        $onSale = $this->get('http://localhost/catalog?on_sale=1&sort=newest')->assertOk();
        $this->assertStringContainsString('PH-4 thing', $onSale->getContent(), 'on sale against its own history');
        $this->assertStringNotContainsString('PH-5 thing', $onSale->getContent(), 'an RRP alone is not a discount here');
    }

    public function test_the_page_says_the_lowest_price_when_asked(): void
    {
        $product = $this->product('PH-6', 80_00, ['list_price' => 200_00]);
        $this->history($product, [[100_00, '-20 days', '-1 day'], [80_00, '-1 day', null]]);
        app(PriceHistory::class)->refresh($product->id);

        $this->get(route('catalog.product', 'ph-6'))->assertOk()->assertDontSee('data-lowest-price', false);

        app(SettingsManager::class)->set('torgash.catalog', 'lowest_price_note', true);
        $this->get(route('catalog.product', 'ph-6'))->assertOk()
            ->assertSee('Lowest price in the 30 days before the discount')
            ->assertSee(format_price(100_00));
    }

    /** A price nothing wrote — a promotion's window — is caught by the sweep. */
    public function test_the_sweep_catches_a_price_that_moved_with_nothing_saved(): void
    {
        $product = $this->product('PH-7', 100_00);
        DB::table('m_catalog_price_history')->where('product_id', $product->id)->update(['starts_at' => now()->subDay()]);
        DB::table('m_catalog_products')->where('id', $product->id)->update(['price' => 70_00]);

        $this->assertSame(1, $this->rows($product));
        app(PriceHistory::class)->sweep();

        $this->assertSame(2, $this->rows($product));
        $this->assertSame(100_00, $product->fresh()->prior_lowest_price);
    }

    /** Pruned per product, back from when ITS price began — never the rows its reference stands on. */
    public function test_pruning_keeps_what_the_reference_is_made_of(): void
    {
        $product = $this->product('PH-8', 100_00);
        $this->history($product, [
            [300_00, '-200 days', '-125 days'], // unreachable: goes
            [150_00, '-125 days', '-90 days'],  // the window before a 90-day-old price: stays
            [100_00, '-90 days', null],
        ]);

        app(PriceHistory::class)->prune();

        $this->assertSame([150_00, 100_00], DB::table('m_catalog_price_history')->where('product_id', $product->id)->orderBy('starts_at')->pluck('price')->map(fn ($p) => (int) $p)->all());
    }

    public function test_a_copy_does_not_inherit_a_reference(): void
    {
        $product = $this->product('PH-9', 80_00);
        DB::table('m_catalog_products')->where('id', $product->id)->update(['prior_lowest_price' => 100_00]);

        $copy = $product->fresh()->replicate([...Product::GENERATED_COLUMNS, ...Product::DERIVED_COLUMNS]);
        $this->assertNull($copy->prior_lowest_price);
    }

    /** @param  list<array{0: int, 1: string, 2: ?string}>  $intervals  price, from, to */
    protected function history(Product $product, array $intervals): void
    {
        DB::table('m_catalog_price_history')->where('product_id', $product->id)->delete();

        foreach ($intervals as [$price, $from, $to]) {
            DB::table('m_catalog_price_history')->insert([
                'product_id' => $product->id, 'price' => $price,
                'starts_at' => Carbon::parse($from), 'ends_at' => $to ? Carbon::parse($to) : null,
            ]);
        }
    }

    protected function rows(Product $product): int
    {
        return DB::table('m_catalog_price_history')->where('product_id', $product->id)->count();
    }

    protected function product(string $sku, int $price, array $extra = []): Product
    {
        return Product::create($extra + [
            'sku' => $sku, 'name' => ['en' => $sku.' thing'], 'slug' => strtolower($sku),
            'price' => $price, 'stock' => 10, 'status' => 'active', 'created_at' => now()->addYear(),
        ]);
    }
}
