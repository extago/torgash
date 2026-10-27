<?php

namespace Tests\Feature\Core;

use App\Core\Support\Currencies;
use App\Models\Currency;
use App\Models\Storefront;
use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;

/**
 * Which currency the ADMIN reads money in.
 *
 * Two separate things are pinned here, and the first is a bug that was live:
 * the admin and the storefront share a session and a domain, so an owner who
 * had walked their own shop in hryvnia found every price in the admin quoted in
 * hryvnia — nobody decided that, and nothing on the screen said it.
 *
 * The second is the rule that makes the switcher safe: it changes what is READ,
 * never what is TYPED. An inline price edit posts the currency the price is
 * stored in, or the switcher would become a way to rewrite the catalogue
 * through an exchange rate.
 */
class AdminCurrencyTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::updateOrCreate(['code' => 'UAH'], [
            'symbol' => '₴', 'rate' => 41, 'decimals' => 0, 'enabled' => true,
        ]);
        app(Currencies::class)->flush();

        foreach (Storefront::all() as $storefront) {
            $storefront->update(['currencies' => [...($storefront->currencies ?? []), 'UAH']]);
        }

        $this->admin = User::role('admin')->firstOrFail();

        Product::updateOrCreate(['sku' => 'ADMCUR-1'], [
            'name' => ['en' => 'Admin priced widget'], 'slug' => 'admin-priced-widget',
            'price' => 1000_00, 'stock' => 3, 'status' => 'active',
        ]);
    }

    /** The storefront switcher is the visitor's business and stops at the admin door. */
    public function test_the_storefronts_currency_does_not_follow_the_owner_into_the_admin(): void
    {
        $this->actingAs($this->admin)
            ->withSession(['currency' => 'UAH'])
            ->get('http://localhost/admin/catalog/products?q=ADMCUR-1')
            ->assertOk()
            ->assertSee("1\u{00A0}000.00\u{00A0}$")
            ->assertDontSee("41\u{00A0}000\u{00A0}₴");
    }

    /** Picking one converts what is shown — at today's rate, which is what a comparison needs. */
    public function test_choosing_a_currency_converts_what_is_shown(): void
    {
        $this->actingAs($this->admin)->post('http://localhost/admin/currency', ['currency' => 'UAH']);

        $this->actingAs($this->admin)
            ->get('http://localhost/admin/catalog/products?q=ADMCUR-1')
            ->assertOk()
            ->assertSee("41\u{00A0}000\u{00A0}₴")
            // ...and the input beside it still carries the stored number, so
            // applying a quick edit cannot move the price by a rate.
            ->assertSee('value="1000.00"', false);
    }

    /** Anything that is not a currency means "as stored", which is the default. */
    public function test_an_unknown_currency_is_no_currency(): void
    {
        $this->actingAs($this->admin)->post('http://localhost/admin/currency', ['currency' => 'XTS']);

        $this->actingAs($this->admin)
            ->get('http://localhost/admin/catalog/products?q=ADMCUR-1')
            ->assertOk()
            ->assertSee("1\u{00A0}000.00\u{00A0}$");
    }
}
