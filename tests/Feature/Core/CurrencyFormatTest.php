<?php

namespace Tests\Feature\Core;

use App\Core\Support\Currencies;
use App\Models\Currency;
use App\Models\User;
use Tests\TestCase;

/**
 * How an amount is WRITTEN is the currency's own: where the sign stands and
 * which separators it uses — one method, so the storefront and an order printed
 * at its frozen rate cannot write the same money two ways.
 */
class CurrencyFormatTest extends TestCase
{
    protected function currency(array $attributes = []): Currency
    {
        return new Currency($attributes + ['code' => 'XTS', 'symbol' => '$', 'decimals' => 2]);
    }

    public function test_the_default_is_what_every_price_was_before(): void
    {
        $this->assertSame("1\u{00A0}299.50\u{00A0}$", $this->currency()->format(1299.5));
    }

    public function test_the_sign_stands_where_the_currency_says(): void
    {
        $this->assertSame("$1\u{00A0}299.50", $this->currency(['symbol_position' => 'before'])->format(1299.5));
        $this->assertSame("$\u{00A0}1\u{00A0}299.50", $this->currency(['symbol_position' => 'before_space'])->format(1299.5));
        $this->assertSame("1\u{00A0}299.50$", $this->currency(['symbol_position' => 'after'])->format(1299.5));
    }

    public function test_separators_are_the_currencys_own(): void
    {
        $euro = $this->currency(['symbol' => '€', 'decimal_separator' => ',', 'thousands_separator' => '.', 'symbol_position' => 'before_space']);
        $this->assertSame("€\u{00A0}1.299,50", $euro->format(1299.5));

        $plain = $this->currency(['thousands_separator' => '', 'decimals' => 0]);
        $this->assertSame("1300\u{00A0}$", $plain->format(1299.5));
    }

    /** format_price() reads it: a stored currency's choice reaches every price on the storefront. */
    public function test_format_price_uses_the_stored_format(): void
    {
        Currency::create(['code' => 'XTS', 'symbol' => '¤', 'name' => 'Test', 'rate' => 1, 'decimals' => 2, 'symbol_position' => 'before']);
        app(Currencies::class)->flush();

        $this->assertSame('¤12.00', format_price(1200, 'XTS'));
    }

    /** The form saves it — a lone space included, which a request would otherwise trim to nothing. */
    public function test_the_admin_form_saves_the_format(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $currency = Currency::create(['code' => 'XTS', 'symbol' => '¤', 'name' => 'Test', 'rate' => 1, 'decimals' => 2]);

        $this->actingAs($admin)->put(route('admin.currencies.update', $currency), [
            'code' => 'XTS', 'symbol' => '¤', 'name' => 'Test', 'rate' => 1, 'decimals' => 2, 'enabled' => 1,
            'symbol_position' => 'before_space', 'decimal_separator' => ',', 'thousands_separator' => 'space',
        ])->assertRedirect();

        $currency->refresh();
        $this->assertSame('before_space', $currency->symbol_position);
        $this->assertSame(',', $currency->decimal_separator);
        $this->assertSame(' ', $currency->thousands_separator);
    }
}
