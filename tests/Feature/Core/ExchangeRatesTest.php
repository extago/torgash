<?php

namespace Tests\Feature\Core;

use App\Core\Currency\FetchRates;
use App\Core\Currency\RateSources;
use App\Core\Currency\RateUpdater;
use App\Core\Settings\SettingsManager;
use App\Core\Support\Currencies;
use App\Models\Currency;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;

/**
 * Rates that fetch themselves.
 *
 * The arithmetic is the part worth pinning down, because it is the part that is
 * silently wrong rather than loudly broken. A source quotes in ITS currency —
 * the NBU in hryvnia — and our column means "how many of this currency one unit
 * of the SHOP's currency buys". Crossing those the wrong way round multiplies
 * every price on the storefront where it should divide, and nothing errors.
 *
 * The guard matters just as much now: since a rate change reprices every
 * product priced in that currency, one bad number from a feed reprices the
 * catalogue.
 */
class ExchangeRatesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Shop books are dollars (the storefront's own currency), so a
        // hryvnia-quoting source has to be crossed rather than copied.
        Currency::updateOrCreate(['code' => 'UAH'], [
            'symbol' => '₴', 'rate' => 41, 'decimals' => 0, 'enabled' => true, 'auto_rate' => true,
        ]);
        Currency::where('code', 'EUR')->update(['auto_rate' => true]);
        app(Currencies::class)->flush();

        app(SettingsManager::class)->set('core', 'rates_markup', 0);
        app(SettingsManager::class)->set('core', 'rates_max_change', 0);
    }

    /** One dollar costs 42 hryvnia today, so the stored UAH rate is 42. */
    public function test_a_hryvnia_quoting_source_is_crossed_into_the_shops_own_currency(): void
    {
        $this->fakeNbu(['USD' => 42.0, 'EUR' => 48.0]);

        $report = app(RateUpdater::class)->update(app(RateSources::class)->find('nbu'));

        // 42 UAH per 1 USD.
        $this->assertEqualsWithDelta(42.0, $this->rate('UAH'), 0.00001);
        // 42 / 48 = 0.875 EUR per 1 USD — crossed through the source's base,
        // never copied from it.
        $this->assertEqualsWithDelta(0.875, $this->rate('EUR'), 0.00001);
        // The books' own currency is not a rate anybody fetches.
        $this->assertEqualsWithDelta(1.0, $this->rate('USD'), 0.00001);
        $this->assertSame(2, $report['updated']);
    }

    /** The official rate is not the rate anyone gives you. */
    public function test_the_markup_raises_what_a_customer_pays_in_another_currency(): void
    {
        app(SettingsManager::class)->set('core', 'rates_markup', 2);
        $this->fakeNbu(['USD' => 42.0]);

        app(RateUpdater::class)->update(app(RateSources::class)->find('nbu'));

        // 42 × 1.02: a customer paying in hryvnia pays more hryvnia, which is
        // the direction that covers the spread.
        $this->assertEqualsWithDelta(42.84, $this->rate('UAH'), 0.00001);
        // ...and the shop's own currency is never marked up: everything is
        // measured against its 1.
        $this->assertEqualsWithDelta(1.0, $this->rate('USD'), 0.00001);
    }

    /** A feed that has changed its units must not reprice the catalogue. */
    public function test_a_rate_that_jumps_too_far_is_refused_and_reported(): void
    {
        app(SettingsManager::class)->set('core', 'rates_max_change', 20);
        // Ten times the current rate — the shape of a source that started
        // answering in a different unit.
        $this->fakeNbu(['USD' => 410.0]);

        $report = app(RateUpdater::class)->update(app(RateSources::class)->find('nbu'));

        $this->assertSame(['UAH'], $report['refused']);
        $this->assertEqualsWithDelta(41.0, $this->rate('UAH'), 0.00001);
    }

    /** A rate the shop holds by hand stays exactly as typed. */
    public function test_a_currency_marked_by_hand_is_left_alone(): void
    {
        Currency::where('code', 'UAH')->update(['auto_rate' => false]);
        $this->fakeNbu(['USD' => 42.0]);

        $report = app(RateUpdater::class)->update(app(RateSources::class)->find('nbu'));

        $this->assertEqualsWithDelta(41.0, $this->rate('UAH'), 0.00001);
        $this->assertSame(1, $report['skipped']);
    }

    /** What the source does not carry is named, not guessed at. */
    public function test_a_currency_the_source_does_not_carry_is_reported(): void
    {
        $this->fakeNbu(['USD' => 42.0]);

        $report = app(RateUpdater::class)->update(app(RateSources::class)->find('nbu'));

        $this->assertContains('EUR', $report['missing']);
        // Left exactly as it was rather than zeroed or dropped.
        $this->assertGreaterThan(0, $this->rate('EUR'));
    }

    /**
     * The whole point of the generic source: a feed nobody wrote a class for,
     * keyed by something other than an ISO code.
     */
    public function test_a_json_feed_reads_a_currency_by_its_own_key(): void
    {
        Currency::updateOrCreate(['code' => 'BTC'], [
            'symbol' => '₿', 'name' => 'Bitcoin', 'rate' => 0.00001, 'decimals' => 4,
            'enabled' => true, 'auto_rate' => true, 'rate_key' => 'bitcoin',
        ]);
        app(Currencies::class)->flush();

        $settings = app(SettingsManager::class);
        $settings->set('core', 'rates_json_url', 'https://feed.example.test/prices');
        $settings->set('core', 'rates_json_base', 'USD');
        $settings->set('core', 'rates_json_path', '{code}.usd');
        $settings->set('core', 'rates_max_change', 0);

        Http::fake(['feed.example.test/*' => Http::response([
            'bitcoin' => ['usd' => 50000],
            'UAH' => ['usd' => 0.025],
        ])]);

        app(RateUpdater::class)->update(app(RateSources::class)->find('json'));

        // One bitcoin costs $50 000, so one dollar is 1/50000 of a bitcoin.
        $this->assertEqualsWithDelta(1 / 50000, $this->rate('BTC'), 0.000000001);
        // And the ordinary currency in the same feed still crosses correctly:
        // one hryvnia costs $0.025, so a dollar is 40 hryvnia.
        $this->assertEqualsWithDelta(40.0, $this->rate('UAH'), 0.00001);
    }

    /** A feed the other way round — "how many X for one of ours" — is ticked, not rewritten. */
    public function test_an_inverted_json_feed_is_read_the_right_way_round(): void
    {
        $settings = app(SettingsManager::class);
        $settings->set('core', 'rates_json_url', 'https://ecb.example.test/latest');
        $settings->set('core', 'rates_json_base', 'EUR');
        $settings->set('core', 'rates_json_path', 'rates.{code}');
        $settings->set('core', 'rates_json_invert', true);

        // ECB shape: one euro buys 1.08 dollars and 45 hryvnia.
        Http::fake(['ecb.example.test/*' => Http::response([
            'rates' => ['USD' => 1.08, 'UAH' => 45.0],
        ])]);

        app(RateUpdater::class)->update(app(RateSources::class)->find('json'));

        // 45 / 1.08 = 41.67 hryvnia to the dollar.
        $this->assertEqualsWithDelta(41.6667, $this->rate('UAH'), 0.001);
    }

    /** Without the shop's own currency there is nothing to cross against. */
    public function test_a_source_that_cannot_quote_the_shops_currency_writes_nothing(): void
    {
        $this->fakeNbu(['PLN' => 10.0]);

        $report = app(RateUpdater::class)->update(app(RateSources::class)->find('nbu'));

        $this->assertSame(0, $report['updated']);
        $this->assertSame(['USD'], $report['missing']);
        $this->assertEqualsWithDelta(41.0, $this->rate('UAH'), 0.00001);
    }

    /** A fetched rate reprices the products priced in that currency (§52). */
    public function test_fetching_rates_reprices_the_products_priced_in_them(): void
    {
        $product = Product::create([
            'sku' => 'RATE-1', 'name' => ['en' => 'Hryvnia priced'], 'slug' => 'hryvnia-priced',
            'price' => 102439, 'price_currency' => 'UAH', 'price_original' => 4200000,
            'stock' => 1, 'status' => 'active',
        ]);

        $this->fakeNbu(['USD' => 50.0]);

        app(RateUpdater::class)->update(app(RateSources::class)->find('nbu'));

        // Still ₴42 000; what moved is what that is worth in the books.
        $this->assertSame(4200000, $product->fresh()->price_original);
        $this->assertSame(84000, $product->fresh()->price);
    }

    /** The job is the thing on the schedule, and it reports what it did. */
    public function test_the_job_records_what_it_updated(): void
    {
        app(SettingsManager::class)->set('core', 'rates_source', 'nbu');
        $this->fakeNbu(['USD' => 42.0, 'EUR' => 48.0]);

        // The queue is sync under test, so start() runs it here and now.
        $task = FetchRates::start();

        $this->assertSame(2, $task->fresh()->result['updated'] ?? null);
        $this->assertSame('done', $task->fresh()->status);
    }

    // -------------------------------------------------- a source that breaks

    /**
     * The day a bank changes its API: the chosen source throws, the European
     * Central Bank is asked for the same currencies, and the report names both.
     */
    public function test_a_broken_source_is_covered_by_the_fallback(): void
    {
        app(SettingsManager::class)->set('core', 'rates_source', 'nbu');
        app(SettingsManager::class)->set('core', 'rates_fallback', true);
        Currency::where('code', 'UAH')->update(['auto_rate' => false]);

        Http::fake([
            'bank.gov.ua/*' => Http::response('gone', 500),
            'www.ecb.europa.eu/*' => Http::response($this->ecbXml(['USD' => 1.25])),
        ]);

        $report = app(RateUpdater::class)->updateFrom(app(RateSources::class)->chain());

        // One euro buys 1.25 dollars, so one dollar buys 0.8 euro.
        $this->assertEqualsWithDelta(0.8, $this->rate('EUR'), 0.00001);
        $this->assertArrayHasKey('nbu', $report['failed']);
        $this->assertSame(['ecb'], array_keys($report['answered']));
        $this->assertNotContains('EUR', $report['missing']);
        // What neither carries is still named — the ECB publishes no rouble.
        $this->assertContains('RUB', $report['missing']);
    }

    /** Each source after the first is asked only for what is still missing. */
    public function test_a_fallback_is_asked_only_for_what_the_first_source_did_not_give(): void
    {
        app(SettingsManager::class)->set('core', 'rates_source', 'nbu');
        // The NBU answers for the hryvnia but has no euro today.
        Http::fake([
            'bank.gov.ua/*' => Http::response([['cc' => 'USD', 'rate' => 42.0]]),
            'www.ecb.europa.eu/*' => Http::response($this->ecbXml(['USD' => 1.25, 'UAH' => 50.0])),
        ]);

        $report = app(RateUpdater::class)->updateFrom(app(RateSources::class)->chain());

        $this->assertEqualsWithDelta(42.0, $this->rate('UAH'), 0.00001, 'the NBU\'s own number is kept, not overwritten by the ECB\'s');
        $this->assertEqualsWithDelta(0.8, $this->rate('EUR'), 0.00001);
        $this->assertSame(['nbu' => '1', 'ecb' => '1'], $report['answered']);
    }

    /** Off means off: a silent source leaves the rates, and the run fails where it can be seen. */
    public function test_without_the_fallback_a_broken_source_fails_the_run(): void
    {
        app(SettingsManager::class)->set('core', 'rates_source', 'nbu');
        app(SettingsManager::class)->set('core', 'rates_fallback', false);
        Http::fake(['bank.gov.ua/*' => Http::response('gone', 500)]);

        // The queue is sync under test, so the failure also surfaces here.
        try {
            FetchRates::start();
            $this->fail('A run where no source answered has to fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No rate source answered', $e->getMessage());
        }

        $this->assertSame('failed', \App\Models\BackgroundTask::latest('id')->value('status'));
        $this->assertEqualsWithDelta(41.0, $this->rate('UAH'), 0.00001);
    }

    /**
     * Neutral by the shop's currency, not by a country: "automatically" is the
     * central bank quoting in the books' currency, then the ECB — and the
     * picker lists the sources quoting in it first.
     */
    public function test_automatic_is_the_central_bank_of_the_books_currency_then_the_ecb(): void
    {
        app(SettingsManager::class)->set('core', 'rates_source', RateSources::AUTO);

        // The test shop keeps its books in dollars: no central bank here
        // quotes in them, so the ECB alone.
        $this->assertSame(['ecb'], array_map(fn ($source) => $source::key(), app(RateSources::class)->chain()));
        $this->assertSame(RateSources::AUTO, array_key_first(app(RateSources::class)->options()));
    }

    /** A rate that stopped moving is named — by the bell and by the guard alike. */
    public function test_a_rate_that_stopped_updating_is_reported(): void
    {
        app(SettingsManager::class)->set('core', 'rates_source', 'nbu');
        Currency::where('code', 'UAH')->update(['rate_updated_at' => now()->subDays(20)]);
        Currency::where('code', 'EUR')->update(['rate_updated_at' => now()]);

        $stale = app(\App\Core\Currency\RateFreshness::class)->stale()->pluck('code')->all();

        $this->assertContains('UAH', $stale);
        $this->assertNotContains('EUR', $stale);

        // Kept by hand, nothing is expected to move.
        app(SettingsManager::class)->set('core', 'rates_source', '');
        $this->assertSame([], app(\App\Core\Currency\RateFreshness::class)->stale()->all());
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, float> $rates code => how many of it one euro buys */
    protected function ecbXml(array $rates): string
    {
        $cubes = collect($rates)->map(fn ($rate, $code) => "<Cube currency='{$code}' rate='{$rate}'/>")->implode('');

        return "<?xml version='1.0' encoding='UTF-8'?><gesmes:Envelope><Cube><Cube time='2026-09-26'>{$cubes}</Cube></Cube></gesmes:Envelope>";
    }

    /** @param array<string, float> $rates code => hryvnia for one unit */
    protected function fakeNbu(array $rates): void
    {
        Http::fake(['bank.gov.ua/*' => Http::response(
            collect($rates)->map(fn ($rate, $code) => ['cc' => $code, 'rate' => $rate])->values()->all()
        )]);
    }

    protected function rate(string $code): float
    {
        return (float) Currency::where('code', $code)->value('rate');
    }
}
