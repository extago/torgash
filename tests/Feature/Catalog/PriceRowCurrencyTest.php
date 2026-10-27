<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use App\Core\Support\Currencies;
use App\Models\Currency;
use App\Models\Storefront;
use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\PriceType;
use Torgash\Catalog\Models\Product;

/**
 * A wholesale tier and a per-SKU price-type override are the same negotiation
 * as the product's own price, so they live in the same currency and follow the
 * same rule: the number the shop typed is the fixed one, the books amount
 * beside it is derived and moves with the rate.
 */
class PriceRowCurrencyTest extends TestCase
{
    protected User $admin;

    protected PriceType $wholesale;

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
        app(SettingsManager::class)->set('torgash.catalog', 'prices_in_other_currencies', true);

        $this->wholesale = PriceType::create([
            'code' => 'ws', 'name' => ['en' => 'Wholesale'], 'is_base' => false,
            'adjustment_percent' => null, 'enabled' => true,
        ]);
    }

    public function test_a_tier_and_an_override_are_kept_and_derived_like_the_price(): void
    {
        $product = $this->save('UAH', tier: '38000', override: '35000');

        $tier = $product->priceTiers()->firstOrFail();
        $this->assertSame(3_800_000, $tier->price_original);
        $this->assertSame((int) round(3_800_000 / 41), $tier->price);

        $override = $product->priceTypeOverrides()->firstOrFail();
        $this->assertSame(3_500_000, $override->price_original);
        $this->assertSame((int) round(3_500_000 / 41), $override->price);
    }

    public function test_a_product_in_the_shops_currency_writes_no_original(): void
    {
        $product = $this->save(shop_currency(), tier: '8', override: '9');

        $tier = $product->priceTiers()->firstOrFail();
        $this->assertNull($tier->price_original);
        $this->assertSame(800, $tier->price);

        $this->assertNull($product->priceTypeOverrides()->firstOrFail()->price_original);
    }

    public function test_a_rate_change_moves_the_books_amount_and_leaves_the_typed_one(): void
    {
        $product = $this->save('UAH', tier: '38000', override: '35000');

        Currency::where('code', 'UAH')->firstOrFail()->update(['rate' => 50]);
        app(Currencies::class)->flush();

        $tier = $product->priceTiers()->firstOrFail();
        $this->assertSame(3_800_000, $tier->price_original, 'the typed number does not move');
        $this->assertSame((int) round(3_800_000 / 50), $tier->price);

        $override = $product->priceTypeOverrides()->firstOrFail();
        $this->assertSame(3_500_000, $override->price_original);
        $this->assertSame((int) round(3_500_000 / 50), $override->price);
    }

    public function test_deleting_the_currency_keeps_the_last_books_amount(): void
    {
        $product = $this->save('UAH', tier: '38000', override: '35000');
        $tierBooks = $product->priceTiers()->firstOrFail()->price;

        Currency::where('code', 'UAH')->firstOrFail()->delete();
        app(Currencies::class)->flush();

        $tier = $product->priceTiers()->firstOrFail();
        $this->assertNull($tier->price_original);
        $this->assertSame($tierBooks, $tier->price);
    }

    public function test_the_books_amounts_are_what_pricing_reads(): void
    {
        $product = $this->save('UAH', tier: '38000', override: '35000');

        // qty 10 qualifies for the tier and the visitor for the wholesale type;
        // effectivePrice picks the cheapest, and it works entirely in the books
        // — never the multi-million-cent `price_original`.
        $tierBooks = $product->priceTiers()->firstOrFail()->price;
        $overrideBooks = $product->priceTypeOverrides()->firstOrFail()->price;

        $this->assertSame(min($tierBooks, $overrideBooks), $product->effectivePrice(null, 10));
        $this->assertLessThan($product->price, $product->effectivePrice(null, 10));
    }

    public function test_the_form_shows_the_typed_tier_amount_not_the_books_one(): void
    {
        $product = $this->save('UAH', tier: '38000', override: '35000');

        $this->actingAs($this->admin)
            ->get('http://localhost/admin/catalog/products/'.$product->id.'/edit')
            ->assertOk()
            ->assertSee('38000')
            ->assertSee('35000');
    }

    /** Posts a product with one tier and one wholesale override through the admin funnel. */
    protected function save(string $currency, string $tier, string $override): Product
    {
        $sku = 'PRC-'.random_int(1000, 9999);

        $this->actingAs($this->admin)->post('http://localhost/admin/catalog/products', [
            'sku' => $sku,
            'name' => 'Tiered widget',
            'price' => '42000',
            'price_currency' => $currency,
            'stock' => 100,
            'status' => 'active',
            'price_tiers' => json_encode([
                ['customer_group_id' => '', 'min_qty' => 10, 'price' => $tier],
            ]),
            'overrides' => [$this->wholesale->id => $override],
        ])->assertRedirect();

        return Product::where('sku', $sku)->firstOrFail();
    }
}
