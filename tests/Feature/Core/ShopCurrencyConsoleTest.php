<?php

namespace Tests\Feature\Core;

use App\Core\Support\Currencies;
use App\Models\Storefront;
use Tests\TestCase;

/**
 * The books currency with no storefront served — a console command, a job —
 * is the default storefront's, not a bare "USD": the demo installed into a
 * shop in hryvnias priced a $29 card at 29 ₴ (2026-10-27).
 */
class ShopCurrencyConsoleTest extends TestCase
{
    public function test_without_a_storefront_the_books_are_the_default_storefronts(): void
    {
        Storefront::where('is_default', true)->update(['default_currency' => 'EUR']);
        app()->forgetInstance('torgash.storefront');
        app(Currencies::class)->flush();

        $this->assertNull(current_storefront());
        $this->assertSame('EUR', shop_currency());
    }
}
