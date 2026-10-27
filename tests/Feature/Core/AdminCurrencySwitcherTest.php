<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Tests\TestCase;
use Torgash\Taxes\Models\Tax;

/**
 * "Show amounts in" has to be obeyed by every amount an admin screen prints.
 *
 * The switcher itself always worked; what did not was the money on some
 * screens, printed with format_price() — which reads the STOREFRONT's session
 * currency. The admin and the shop share a session, so a screen doing that
 * quietly answered in whatever currency the owner had last browsed their own
 * shop in: not a decision anybody made, and not something the switcher above
 * it could correct.
 *
 * The test states it as the rule it is: whatever the storefront session says,
 * the admin's own choice is what an admin screen prints in.
 */
class AdminCurrencySwitcherTest extends TestCase
{
    public function test_an_admin_screen_prints_in_the_admins_currency_not_the_storefronts(): void
    {
        Tax::create([
            'code' => 'duty',
            'name' => ['en' => 'Fixed duty'],
            'rate_type' => 'fixed',
            'rate_amount' => 100_00,
            'enabled' => true,
        ]);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->withSession([
                // What the owner last picked while walking their own shop…
                'currency' => 'RUB',
                // …and what they asked the ADMIN to show them.
                'admin_currency' => 'EUR',
            ]);

        $page = $this->get('http://localhost/admin/taxes')->assertOk();

        // 100.00 of the books (USD) at 0.86. Asserted as the AMOUNT, not just
        // the symbol: every admin page lists every currency's symbol in the
        // switcher itself, so "contains ₽" is true whatever the screen prints.
        $page->assertSee("86.00\u{00A0}€", escape: false);
        $page->assertDontSee("8\u{00A0}100.00", escape: false);
    }

    /** The default — "Initial" — is the currency each amount is stored in. */
    public function test_initial_means_as_stored(): void
    {
        Tax::create([
            'code' => 'duty',
            'name' => ['en' => 'Fixed duty'],
            'rate_type' => 'fixed',
            'rate_amount' => 100_00,
            'enabled' => true,
        ]);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->withSession(['currency' => 'RUB', 'admin_currency' => null]);

        $this->get('http://localhost/admin/taxes')
            ->assertOk()
            ->assertSee("100.00\u{00A0}$", escape: false)
            ->assertDontSee("8\u{00A0}100.00", escape: false);
    }

    /** And the switcher itself stores only a currency the shop actually has. */
    public function test_the_switcher_refuses_a_currency_the_shop_does_not_have(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());

        $this->post('http://localhost/admin/currency', ['currency' => 'EUR'])->assertRedirect();
        $this->assertSame('EUR', session('admin_currency'));

        $this->post('http://localhost/admin/currency', ['currency' => 'XXX'])->assertRedirect();
        $this->assertNull(session('admin_currency'), 'anything unknown means "Initial"');
    }
}
