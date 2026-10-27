<?php

namespace Tests\Feature\Core;

use App\Core\Support\Currencies;
use App\Models\Currency;
use App\Models\CustomerGroup;
use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Coupon;
use Torgash\Options\Models\OptionChoice;
use Torgash\Options\PriceRules;

/**
 * Money worked out from a percentage is rounded to the places the books are
 * SHOWN with: a shop in whole hryvnias showed 51 396 ₴ on the product and
 * asked 51 395.76 ₴ in the basket (first clean install, 2026-10-27).
 */
class RoundMoneyTest extends TestCase
{
    protected function booksShownWith(int $decimals): void
    {
        Currency::where('code', shop_currency())->update(['decimals' => $decimals]);
        app(Currencies::class)->flush();
    }

    public function test_a_shop_in_whole_units_gets_whole_amounts_from_every_percentage(): void
    {
        $this->booksShownWith(0);

        $this->assertSame(4793300, round_money(4793310));
        $this->assertSame(4793400, round_money(4793350));

        // A customer group's 10% off 532.59.
        $group = CustomerGroup::create(['name' => ['en' => 'Wholesale'], 'discount_percent' => 10]);
        $user = User::factory()->create(['customer_group_id' => $group->id]);
        $product = Product::create(['sku' => 'RM-1', 'name' => ['en' => 'Phone'], 'slug' => 'rm-1', 'price' => 53259, 'stock' => 1, 'status' => 'active']);
        $this->assertSame(47900, $product->effectivePrice($user));

        // An option at 6.6% of the product.
        $choice = new OptionChoice(['price_rule' => 'percent', 'percent' => 6.6]);
        $this->assertSame(3500, app(PriceRules::class)->apply($choice, 53259));

        // A coupon's 10%.
        $coupon = new Coupon(['type' => 'percent', 'value' => 10, 'enabled' => true]);
        $this->assertSame(5300, $coupon->discountFor(53259));
    }

    public function test_a_shop_with_cents_keeps_them(): void
    {
        $this->booksShownWith(2);

        $this->assertSame(4793310, round_money(4793310));

        $choice = new OptionChoice(['price_rule' => 'percent', 'percent' => 6.6]);
        $this->assertSame(3515, app(PriceRules::class)->apply($choice, 53259));
    }
}
