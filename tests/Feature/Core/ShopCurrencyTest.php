<?php

namespace Tests\Feature\Core;

use App\Models\Currency;
use App\Models\Storefront;
use App\Models\User;
use Tests\TestCase;

/**
 * The shop's own currency is the one every stored amount is already in.
 *
 * A visitor switching currency in the header changes what prices LOOK like; the
 * storefront's default currency changes what the numbers in the database MEAN.
 * Nothing converts when it is changed — the same stored 104225 stops meaning
 * $1042.25 and starts meaning ₴1042.25, for the catalogue and for every order
 * ever placed.
 *
 * There is one mechanical way to catch the mistake, and this is it: rates are
 * expressed against the shop's own currency, so that currency is the one whose
 * rate is 1. Anything else as the default is a shop whose prices have all been
 * multiplied by a rate nobody meant to apply.
 */
class ShopCurrencyTest extends TestCase
{
    public function test_a_currency_that_is_not_the_base_cannot_become_the_default(): void
    {
        Currency::updateOrCreate(['code' => 'UAH'], [
            'symbol' => '₴', 'name' => 'Hryvnia', 'rate' => 41, 'decimals' => 0, 'enabled' => true,
        ]);

        $storefront = Storefront::firstOrFail();

        $this->actingAs($this->admin())
            ->put('http://localhost/admin/storefronts/'.$storefront->id, $this->form($storefront, 'UAH'))
            ->assertSessionHasErrors('default_currency');

        $this->assertSame('USD', $storefront->refresh()->default_currency, 'the shop was re-based by a form save');
    }

    /** Re-based properly first, it saves — the guard is about the rate, not about the code. */
    public function test_it_saves_once_the_rates_have_been_re_expressed(): void
    {
        Currency::updateOrCreate(['code' => 'UAH'], [
            'symbol' => '₴', 'name' => 'Hryvnia', 'rate' => 1, 'decimals' => 0, 'enabled' => true,
        ]);

        $storefront = Storefront::firstOrFail();

        $this->actingAs($this->admin())
            ->put('http://localhost/admin/storefronts/'.$storefront->id, $this->form($storefront, 'UAH'))
            ->assertSessionHasNoErrors();

        $this->assertSame('UAH', $storefront->refresh()->default_currency);
    }

    /** @return array<string, mixed> */
    protected function form(Storefront $storefront, string $currency): array
    {
        return [
            'code' => $storefront->code,
            'name' => $storefront->name,
            'domains' => implode(',', $storefront->domains ?? ['localhost']),
            'theme' => $storefront->theme,
            'default_locale' => $storefront->default_locale,
            'locales' => $storefront->locales,
            'default_currency' => $currency,
            'currencies' => array_values(array_unique([...$storefront->currencies, $currency])),
            'enabled' => '1',
            'is_default' => '1',
        ];
    }

    protected function admin(): User
    {
        return User::role('admin')->firstOrFail();
    }
}
