<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Core\Support\Currencies;
use App\Core\Support\PriceColumns;
use App\Core\Support\PriceScale;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A price per unit finer than an amount (PLAN §65): the scale's arithmetic,
 * its printing, and moving every declared price when the places change.
 */
class PriceScaleTest extends TestCase
{
    public function test_two_places_is_the_old_engine(): void
    {
        $scale = price_scale();

        $this->assertSame(2, $scale->places());
        $this->assertSame(1, $scale->perMinor());
        $this->assertSame(1999, $scale->amount(1999));
        $this->assertSame(1999, $scale->fromMajor('19.99'));
        $this->assertSame(format_price(1999, shop_currency()), format_unit_price(1999, shop_currency()));
        $this->assertSame(admin_price(1999), admin_unit_price(1999));
    }

    public function test_typed_numbers_are_parsed_in_integers(): void
    {
        $this->assertSame(125, PriceScale::parse('0.0125', 4));
        $this->assertSame(125, PriceScale::parse('0,0125', 4));
        $this->assertSame(129950, PriceScale::parse('1 299,50', 2));
        $this->assertSame(129950, PriceScale::parse("1\u{00A0}299.5", 2));
        $this->assertSame(129950, PriceScale::parse('1,299.50', 2));
        $this->assertSame(126, PriceScale::parse('0.01255', 4), 'the first dropped digit rounds half away from zero');
        $this->assertSame(125, PriceScale::parse('0.01254', 4));
        $this->assertSame(-101, PriceScale::parse('-1.005', 2));
        $this->assertSame(42050, PriceScale::parse(420.5, 2));
        $this->assertSame(30, PriceScale::parse(0.1 + 0.2, 2));
        $this->assertSame(1, PriceScale::parse(0.0001, 4));
        $this->assertSame(500, PriceScale::parse(5, 2));
        $this->assertNull(PriceScale::parse('', 2));
        $this->assertNull(PriceScale::parse(null, 2));
        $this->assertNull(PriceScale::parse('abc', 2));
        $this->assertNull(PriceScale::parse('1.2.3', 2));
    }

    public function test_decimal_strings_have_exactly_the_places(): void
    {
        $this->assertSame('0.0120', PriceScale::digits(120, 4));
        $this->assertSame('-0.05', PriceScale::digits(-5, 2));
        $this->assertSame('199990.00', money_decimal(19999000));
        $this->assertSame('0.00', money_decimal(0));
    }

    public function test_four_places_keep_a_fraction_of_a_cent(): void
    {
        $this->places(4);
        $scale = price_scale();

        $this->assertSame(4, $scale->places());
        $this->assertSame(100, $scale->perMinor());
        $this->assertSame(120, $scale->fromMajor('0.012'));
        $this->assertTrue($scale->tooPrecise('0.01255'));
        $this->assertFalse($scale->tooPrecise('0.0125'));
        $this->assertFalse($scale->tooPrecise('0.01250'));

        // 0,012 × 1000 = 12,00; × 333 = 3,996 → 4,00; 0,0125 × 1 = 0,0125 → 0,01.
        $this->assertSame(1200, $scale->amount(120 * 1000));
        $this->assertSame(400, $scale->amount(120 * 333));
        $this->assertSame(1, $scale->amount(125));
        // Exactly half a cent goes away from zero.
        $this->assertSame(1, $scale->amount(50));
        $this->assertSame(2, $scale->amount(150));
        $this->assertSame(-1, $scale->amount(-50));

        // A worked-out price keeps the shop's places: 0,012 − 7% = 0,01116 → 0,0112.
        $this->assertSame(112, $scale->round(120 * 0.93));
        $this->assertSame('0.0120', $scale->decimal(120));
        $this->assertSame('0.012', $scale->trimmed(120));
        $this->assertSame('999.00', $scale->trimmed(9990000));
    }

    public function test_four_places_print_only_the_places_that_mean_something(): void
    {
        $this->places(4);
        $books = app(Currencies::class)->find(shop_currency());

        $this->assertSame($books->format(0.012, 3), format_unit_price(120, shop_currency()));
        $this->assertSame($books->format(0.0125, 4), format_unit_price(125, shop_currency()));
        $this->assertSame($books->format(999.0), format_unit_price(9990000, shop_currency()));
        $this->assertSame($books->format(5.0), format_unit_price(50000, shop_currency()));
    }

    public function test_a_converted_price_does_not_print_the_rate_noise(): void
    {
        $this->places(4);
        $other = \App\Models\Currency::create([
            'code' => 'XTS', 'symbol' => 'T', 'name' => 'Test', 'rate' => 41.958418, 'decimals' => 2, 'enabled' => true,
        ]);
        app(Currencies::class)->flush();

        // $999.00 × 41.958418 = 41 916.46 — two places, not 41 916.4596.
        $this->assertSame($other->format(999 * 41.958418, 2), format_unit_price(9990000, 'XTS'));
        // $0.012 × 41.958418 = 0.5035: two places would lose 0.7% — three are shown.
        $this->assertSame($other->format(0.012 * 41.958418, 3), format_unit_price(120, 'XTS'));
    }

    public function test_more_places_multiply_every_declared_price_and_fewer_divide_them_back(): void
    {
        $this->table();
        DB::table('test_unit_prices')->insert([['price' => 1999, 'note' => 'a'], ['price' => 5, 'note' => 'b']]);

        $this->places(4);
        $this->assertSame([199900, 500], $this->prices());

        $this->places(3);
        $this->assertSame([19990, 50], $this->prices());

        $this->places(2);
        $this->assertSame([1999, 5], $this->prices());
    }

    public function test_fewer_places_are_refused_while_a_price_would_lose_digits(): void
    {
        $this->table();
        $this->places(4);
        DB::table('test_unit_prices')->insert([['price' => 125, 'note' => 'ST-0125'], ['price' => 100, 'note' => 'ST-0100']]);

        $lost = app(PriceColumns::class)->lossy(4, 2);
        $this->assertSame([['name' => 'Test prices', 'count' => 1, 'examples' => ['ST-0125']]], $lost);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->post(route('admin.settings.update', 'core'), $this->values([PriceScale::SETTING => '2']))
            ->assertSessionHasErrors(PriceScale::SETTING);

        $this->assertSame(4, price_scale()->places());
        $this->assertSame([125, 100], $this->prices());

        // Three places would lose the last digit of 0.0125 just the same.
        $this->assertNotEmpty(app(PriceColumns::class)->lossy(4, 3));

        DB::table('test_unit_prices')->where('note', 'ST-0125')->update(['price' => 200]);

        $this->post(route('admin.settings.update', 'core'), $this->values([PriceScale::SETTING => '2']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, price_scale()->places());
        $this->assertSame([2, 1], $this->prices());
    }

    public function test_a_failed_move_puts_the_setting_back(): void
    {
        $this->table();
        DB::table('test_unit_prices')->insert(['price' => 1999, 'note' => 'a']);

        hook_listen('core.price.columns', fn (array $columns) => [...$columns, [
            'name' => 'Broken',
            'lossy' => fn () => 0,
            'rescale' => fn () => throw new \RuntimeException('disk full'),
        ]]);

        try {
            $this->places(4);
            $this->fail('the move should have failed');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }

        $this->assertSame(2, price_scale()->places());
        $this->assertSame([1999], $this->prices());
    }

    // ------------------------------------------------------------- helpers

    protected function places(int $places): void
    {
        app(SettingsManager::class)->set('core', PriceScale::SETTING, (string) $places);
    }

    protected function table(): void
    {
        Schema::create('test_unit_prices', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('price')->nullable();
            $table->string('note');
        });

        hook_listen('core.price.columns', fn (array $columns) => [...$columns, [
            'name' => 'Test prices',
            'table' => 'test_unit_prices',
            'columns' => ['price'],
            'example' => 'note',
        ]]);
    }

    /** @return list<int> */
    protected function prices(): array
    {
        return DB::table('test_unit_prices')->orderBy('id')->pluck('price')->map(fn ($p) => (int) $p)->all();
    }

    /** @param array<string, mixed> $override */
    protected function values(array $override): array
    {
        $values = [];

        foreach (\App\Core\Settings\CoreSettings::definitions() as $def) {
            if (! isset($def['key']) || in_array($def['type'] ?? 'text', ['group', 'action', 'link', 'image'], true)) {
                continue;
            }

            $values[$def['key']] = app(SettingsManager::class)->get('core', $def['key'], $def['default'] ?? null);
        }

        return $override + $values;
    }
}
